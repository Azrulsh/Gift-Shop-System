<?php

namespace Tests\MariaDb;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\OrderService;
use App\Services\ReportService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IntegrityTest extends MariaDbTestCase
{
    public function test_tables_use_innodb_and_foreign_keys_restrict_supplier_deletion(): void
    {
        $tables = DB::select("SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('products', 'suppliers', 'customers', 'orders', 'order_items')");
        $this->assertCount(5, $tables);
        foreach ($tables as $table) {
            $this->assertSame('InnoDB', $table->engine);
        }
        $supplier = $this->supplier();
        $this->product()->update(['supplier_id' => $supplier->id]);
        $this->expectException(QueryException::class);
        $supplier->forceDelete();
    }

    public function test_soft_deletion_preserves_product_relationships_and_stock(): void
    {
        $supplier = $this->supplier();
        $product = $this->product();
        $product->update(['supplier_id' => $supplier->id]);
        $supplier->delete();

        $this->assertTrue($product->fresh()->supplier->trashed());
        $this->assertSame($product->id, $supplier->products()->sole()->id);
        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->get(route('suppliers.show', $supplier))->assertOk();
        $this->get(route('suppliers.edit', $supplier))->assertNotFound();
    }

    public function test_customer_email_remains_unique_after_soft_deletion(): void
    {
        $customer = $this->customer();
        $customer->delete();
        $this->expectException(QueryException::class);
        Customer::create(['name' => 'Duplicate', 'email' => 'INTEGRATION@EXAMPLE.TEST', 'phone' => '0123456789']);
    }

    public function test_supplier_email_remains_unique_after_soft_deletion(): void
    {
        $supplier = $this->supplier();
        $data = $supplier->only(['name', 'contact_person', 'email', 'phone', 'status']);
        $supplier->delete();
        $this->expectException(QueryException::class);
        Supplier::create($data);
    }

    public function test_inactive_and_deleted_suppliers_cannot_be_new_assignments(): void
    {
        $supplier = $this->supplier();
        $supplier->update(['status' => 'inactive']);
        $data = ['name' => 'Rejected gift', 'category' => 'Testing', 'price' => '0.10', 'stock_quantity' => 1, 'supplier_id' => $supplier->id];
        $this->post(route('products.store'), $data)->assertSessionHasErrors('supplier_id');
        $supplier->delete();
        $this->post(route('products.store'), $data)->assertSessionHasErrors('supplier_id');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_creation_reserves_once_duplicate_retries_resolve_and_cancellation_restores_once(): void
    {
        $customer = $this->customer();
        $product = $this->product(stock: 2);
        $data = $this->orderData($customer, [['product_id' => $product->id, 'quantity' => 2]]);
        $service = app(OrderService::class);
        $order = $service->create($data);
        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame('24.70', $order->total_amount);
        $this->assertSame($order->id, $service->create($data)->id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $cancelled = $service->cancel($order, 0);
        $service->cancel($cancelled, 0);
        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertSame(1, $cancelled->lock_version);
    }

    public function test_stale_updates_do_not_change_stock_or_order_state(): void
    {
        $product = $this->product();
        $service = app(OrderService::class);
        $order = $service->create($this->orderData($this->customer(), [['product_id' => $product->id, 'quantity' => 1]]));
        try {
            $service->update($order, ['lock_version' => 99, 'status' => 'processing', 'payment_status' => 'unpaid']);
            $this->fail('A stale update was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lock_version', $exception->errors());
        }
        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame(9, $product->fresh()->stock_quantity);
    }

    public function test_insufficient_stock_rolls_back_prior_item_deduction_and_order_insert(): void
    {
        $customer = $this->customer();
        $first = $this->product('First');
        $second = $this->product('Unavailable', stock: 0);
        try {
            app(OrderService::class)->create($this->orderData($customer, [['product_id' => $first->id, 'quantity' => 1], ['product_id' => $second->id, 'quantity' => 1]]));
            $this->fail('Insufficient stock was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.1.quantity', $exception->errors());
        }
        $this->assertSame(10, $first->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_decimal_reporting_uses_snapshots_and_excludes_cancelled_orders(): void
    {
        $customer = $this->customer();
        $product = $this->product(price: '0.10');
        $service = app(OrderService::class);
        $paid = $this->orderData($customer, [['product_id' => $product->id, 'quantity' => 3]]);
        $paid['payment_status'] = 'paid';
        $service->create($paid);
        $unpaid = $service->create($this->orderData($customer, [['product_id' => $product->id, 'quantity' => 2]], '22222222-2222-4222-8222-222222222222'));
        $cancelled = $service->create($this->orderData($customer, [['product_id' => $product->id, 'quantity' => 1]], '33333333-3333-4333-8333-333333333333'));
        $service->cancel($cancelled, 0);
        $product->update(['price' => '999.99']);
        $customer->update(['name' => 'Renamed customer']);
        $customer->delete();
        $reports = app(ReportService::class);
        $summary = $reports->salesSummary(['date_from' => '2026-11-01', 'date_to' => '2026-11-08']);
        $this->assertSame('0.30', $summary['paid_revenue']);
        $this->assertSame('0.20', $summary['outstanding_value']);
        $performance = $reports->products()->first();
        $this->assertSame(5, (int) $performance->quantity_sold);
        $this->assertSame('0.50', ReportService::money($performance->order_value));
        $this->assertSame('Integration customer', $unpaid->fresh()->customer_name_snapshot);
        $this->assertSame('0.30', ReportService::money($reports->customers()->first()->paid_spending));
        $this->assertDatabaseCount('orders', 3);
    }

    public function test_order_product_and_customer_foreign_keys_preserve_history(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        app(OrderService::class)->create($this->orderData($customer, [['product_id' => $product->id, 'quantity' => 1]]));
        foreach ([$customer, $product, Order::sole()] as $model) {
            try {
                $model instanceof Customer ? $model->forceDelete() : $model->delete();
                $this->fail('A referenced record was deleted.');
            } catch (QueryException $exception) {
                $this->assertSame('23000', $exception->errorInfo[0]);
            }
        }
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['name' => 'Integration supplier', 'contact_person' => 'Test contact', 'email' => 'supplier@example.test', 'phone' => '0123456789', 'status' => 'active']);
    }
}
