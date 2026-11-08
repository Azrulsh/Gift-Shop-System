<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\OrderStatus;
use App\PaymentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Order tests must use isolated in-memory SQLite.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-11-08 12:00:00');
    }

    public function test_lists_newest_orders_with_fifteen_per_page(): void
    {
        $orders = Order::factory()->count(16)->create();

        $this->get(route('orders.index'))->assertOk()->assertSee($orders->last()->order_number)
            ->assertDontSee($orders->first()->order_number)
            ->assertViewHas('orders', fn ($page) => $page->total() === 16 && $page->count() === 15);
        $this->get(route('orders.index', ['page' => 2]))->assertOk()->assertSee($orders->first()->order_number);
    }

    public function test_empty_state_and_create_form(): void
    {
        $this->get(route('orders.index'))->assertOk()->assertSee('No orders yet.');
        $this->get(route('orders.create'))->assertOk()->assertSee('Create customer order')
            ->assertSee('name="creation_token"', false)->assertSee('name="_token"', false);
    }

    public function test_creates_multiple_items_with_exact_totals_and_reserves_stock_once(): void
    {
        $first = Product::factory()->create(['price' => '0.10', 'stock_quantity' => 10]);
        $second = Product::factory()->create(['price' => '19.95', 'stock_quantity' => 10]);
        $data = $this->orderData($first, ['items' => [['product_id' => $first->id, 'quantity' => 3], ['product_id' => $second->id, 'quantity' => 2]]]);

        $this->post(route('orders.store'), $data)->assertSessionHasNoErrors()->assertSessionHas('success');

        $order = Order::sole();
        $this->assertSame('40.20', $order->total_amount);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => $first->id, 'subtotal' => '0.30']);
        $this->assertSame(7, $first->fresh()->stock_quantity);
        $this->assertSame(8, $second->fresh()->stock_quantity);
        $this->assertSame(2, $order->items()->count());
        $this->post(route('orders.store'), $data)->assertRedirect(route('orders.show', $order));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(7, $first->fresh()->stock_quantity);
    }

    public function test_rejects_client_prices_totals_and_duplicate_items(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $data = $this->orderData($product, ['total_amount' => '0.01', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '0.01'], ['product_id' => $product->id, 'quantity' => 1]]]);

        $this->post(route('orders.store'), $data)->assertSessionHasErrors(['total_amount', 'items.0', 'items.0.product_id']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    #[DataProvider('invalidOrders')]
    public function test_rejects_invalid_order_fields(string $field, mixed $value, string $error): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $data = $this->orderData($product);
        data_set($data, $field, $value);

        $this->post(route('orders.store'), $data)->assertSessionHasErrors($error);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public static function invalidOrders(): array
    {
        return [
            'missing customer' => ['customer_id', null, 'customer_id'],
            'nonexistent customer' => ['customer_id', 9999, 'customer_id'],
            'no products' => ['items', [], 'items'],
            'nonexistent product' => ['items.0.product_id', 9999, 'items.0.product_id'],
            'zero quantity' => ['items.0.quantity', 0, 'items.0.quantity'],
            'negative quantity' => ['items.0.quantity', -1, 'items.0.quantity'],
            'fractional quantity' => ['items.0.quantity', 1.5, 'items.0.quantity'],
            'huge quantity' => ['items.0.quantity', 1000001, 'items.0.quantity'],
            'future date' => ['order_date', '2026-11-09', 'order_date'],
            'invalid date' => ['order_date', 'not-a-date', 'order_date'],
            'cancelled creation' => ['status', 'cancelled', 'status'],
            'completed creation' => ['status', 'completed', 'status'],
            'invalid payment' => ['payment_status', 'refunded', 'payment_status'],
            'invalid token' => ['creation_token', 'bad-token', 'creation_token'],
        ];
    }

    public function test_rejects_soft_deleted_customer(): void
    {
        $product = Product::factory()->create();
        $data = $this->orderData($product);
        Customer::findOrFail($data['customer_id'])->delete();

        $this->post(route('orders.store'), $data)->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_insufficient_stock_rolls_back_all_prior_changes(): void
    {
        $first = Product::factory()->create(['stock_quantity' => 10]);
        $second = Product::factory()->create(['stock_quantity' => 0]);
        $data = $this->orderData($first, ['items' => [['product_id' => $first->id, 'quantity' => 2], ['product_id' => $second->id, 'quantity' => 1]]]);

        $this->post(route('orders.store'), $data)->assertSessionHasErrors('items.1.quantity');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(10, $first->fresh()->stock_quantity);
    }

    public function test_preserves_historical_snapshots_after_profile_changes_and_soft_deletion(): void
    {
        $product = Product::factory()->create(['name' => 'Original gift', 'price' => '12.50', 'stock_quantity' => 10]);
        $customer = Customer::factory()->create(['name' => 'Original customer', 'email' => 'original@example.com']);
        $order = $this->placeOrder($product, ['customer_id' => $customer->id]);
        $product->update(['name' => 'Renamed gift', 'price' => '99.00']);
        $customer->update(['name' => 'Renamed customer']);
        $customer->delete();

        $this->get(route('orders.show', $order))->assertOk()->assertSee('Original gift')->assertSee('Original customer')
            ->assertSee('original@example.com')->assertSee('12.50')->assertDontSee('Renamed gift')->assertDontSee('Renamed customer');

        $this->assertTrue($order->customer->trashed());
        $this->assertSame($order->id, $customer->orders()->sole()->id);
        $this->assertSame($product->id, $order->items()->sole()->product->id);
    }

    public function test_pending_update_adjusts_differences_keeps_old_prices_and_prices_new_items(): void
    {
        $first = Product::factory()->create(['price' => '10.00', 'stock_quantity' => 10]);
        $second = Product::factory()->create(['price' => '3.00', 'stock_quantity' => 10]);
        $order = $this->placeOrder($first);
        $first->update(['price' => '99.00']);

        $this->put(route('orders.update', $order), $this->updateData($order, ['items' => [['product_id' => $first->id, 'quantity' => 3], ['product_id' => $second->id, 'quantity' => 2]]]))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('36.00', $order->fresh()->total_amount);
        $this->assertSame(7, $first->fresh()->stock_quantity);
        $this->assertSame(8, $second->fresh()->stock_quantity);
        $this->assertSame(1, $order->fresh()->lock_version);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => $first->id, 'unit_price' => '10.00']);
    }

    public function test_removed_item_restores_stock_and_reduced_quantity_restores_difference(): void
    {
        $first = Product::factory()->create(['price' => '10.00', 'stock_quantity' => 10]);
        $second = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($first, ['items' => [['product_id' => $first->id, 'quantity' => 4], ['product_id' => $second->id, 'quantity' => 2]]]);

        $this->put(route('orders.update', $order), $this->updateData($order, ['items' => [['product_id' => $first->id, 'quantity' => 1]]]))->assertSessionHasNoErrors();

        $this->assertSame(9, $first->fresh()->stock_quantity);
        $this->assertSame(10, $second->fresh()->stock_quantity);
        $this->assertSame('10.00', $order->fresh()->total_amount);
        $this->assertSame(1, $order->items()->count());
    }

    public function test_stale_order_update_and_cancellation_leave_stock_unchanged(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product);
        $this->put(route('orders.update', $order), $this->updateData($order, ['status' => 'processing']))->assertSessionHasNoErrors();

        $this->put(route('orders.update', $order), $this->updateData($order))->assertSessionHasErrors('lock_version');
        $this->post(route('orders.cancel', $order), ['lock_version' => 0])->assertSessionHasErrors('lock_version');

        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
    }

    #[DataProvider('transitions')]
    public function test_status_and_payment_transition_rules(string $from, string $to, string $payment, bool $allowed): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product);
        $order->update(['status' => $from]);

        $response = $this->put(route('orders.update', $order), $this->updateData($order, ['status' => $to, 'payment_status' => $payment]));

        if ($allowed) {
            $response->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->assertSame($to, $order->fresh()->status->value);
        } else {
            $response->assertSessionHasErrors();
            $this->assertSame($from, $order->fresh()->status->value);
        }
        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public static function transitions(): array
    {
        return [
            'pending to processing' => ['pending', 'processing', 'unpaid', true],
            'cannot skip processing' => ['pending', 'completed', 'paid', false],
            'processing to completed paid' => ['processing', 'completed', 'paid', true],
            'unpaid completion refused' => ['processing', 'completed', 'unpaid', false],
            'no backward transition' => ['processing', 'pending', 'unpaid', false],
            'completed locked' => ['completed', 'processing', 'paid', false],
            'cancelled locked' => ['cancelled', 'pending', 'unpaid', false],
            'cancel must use cancellation route' => ['pending', 'cancelled', 'unpaid', false],
        ];
    }

    public function test_paid_items_are_immutable_and_payment_cannot_be_reversed(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product, ['payment_status' => 'paid']);

        $this->put(route('orders.update', $order), $this->updateData($order, ['items' => [['product_id' => $product->id, 'quantity' => 1]]]))->assertSessionHasErrors('items');
        $this->put(route('orders.update', $order), $this->updateData($order, ['payment_status' => 'unpaid']))->assertSessionHasErrors('payment_status');
        $this->post(route('orders.cancel', $order), ['lock_version' => 0])->assertSessionHasErrors('status');

        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_processing_items_and_customer_are_fixed(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product, ['status' => 'processing']);

        $this->put(route('orders.update', $order), $this->updateData($order, ['items' => [['product_id' => $product->id, 'quantity' => 1]]]))->assertSessionHasErrors('items');
        $this->put(route('orders.update', $order), $this->updateData($order, ['customer_id' => Customer::factory()->create()->id]))->assertSessionHasErrors('customer_id');

        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public function test_cancellation_restores_stock_once_and_preserves_history(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product, ['status' => 'processing']);

        $this->post(route('orders.cancel', $order), ['lock_version' => 0])->assertSessionHas('success');
        $this->post(route('orders.cancel', $order), ['lock_version' => 0])->assertSessionHas('success');

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->cancelled_at);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(1, $order->fresh()->lock_version);
        $this->delete(route('orders.show', $order))->assertStatus(405);
    }

    public function test_search_and_combined_filters_preserve_parameters_and_snapshots(): void
    {
        Order::factory()->count(16)->create(['customer_name_snapshot' => 'Original customer', 'status' => 'processing', 'payment_status' => 'paid']);
        Order::factory()->create(['customer_name_snapshot' => 'Original customer', 'status' => 'cancelled']);
        $filters = ['search' => 'Original', 'status' => 'processing', 'payment_status' => 'paid'];

        $this->get(route('orders.index', $filters))->assertOk()->assertSee('status=processing', false)
            ->assertSee('payment_status=paid', false)->assertSee('search=Original', false)
            ->assertViewHas('orders', fn ($page) => $page->total() === 16);
        $this->get(route('orders.index', $filters + ['page' => 2]))->assertOk()->assertViewHas('orders', fn ($page) => $page->count() === 1);
        $order = Order::first();
        $this->get(route('orders.index', ['search' => $order->order_number]))->assertOk()->assertViewHas('orders', fn ($page) => $page->total() === 1);
    }

    public function test_invalid_filters_and_missing_orders(): void
    {
        $this->from(route('orders.index'))->get(route('orders.index', ['status' => 'invalid']))->assertSessionHasErrors('status');
        $this->get('/orders/999')->assertNotFound();
        $this->get('/orders/999/edit')->assertNotFound();
        $this->put('/orders/999', [])->assertNotFound();
        $this->post('/orders/999/cancellation', ['lock_version' => 0])->assertNotFound();
    }

    public function test_renders_edit_confirmation_and_read_only_terminal_screens(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product);

        $this->get(route('orders.edit', $order))->assertOk()->assertSee('Save changes')->assertSee('name="lock_version"', false);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Confirm cancellation')->assertSee('name="_token"', false);
        $order->update(['status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid]);
        $this->get(route('orders.edit', $order))->assertRedirect(route('orders.show', $order));
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('Confirm cancellation')->assertDontSee('Edit order');
    }

    public function test_product_deletion_is_blocked_and_stale_stock_edit_is_rejected(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $this->placeOrder($product);

        $this->delete(route('products.destroy', $product))->assertSessionHas('error', 'This product is referenced by an order and cannot be deleted.');
        $this->put(route('products.update', $product), ['name' => 'Updated', 'category' => 'Gifts', 'price' => '10.00', 'stock_quantity' => 10, 'original_stock_quantity' => 10])->assertSessionHasErrors('stock_quantity');

        $this->assertModelExists($product);
        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public function test_failed_item_insert_rolls_back_order_and_stock(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $data = $this->orderData($product);
        Event::listen('eloquent.creating: '.OrderItem::class, function (): void {
            throw new RuntimeException('Simulated insert failure.');
        });

        try {
            $this->from(route('orders.create'))->post(route('orders.store'), $data)->assertSessionHas('error');
        } finally {
            Event::forget('eloquent.creating: '.OrderItem::class);
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_failed_update_rolls_back_items_totals_and_stock(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product);
        Event::listen('eloquent.creating: '.OrderItem::class, function (): void {
            throw new RuntimeException('Simulated update failure.');
        });

        try {
            $this->put(route('orders.update', $order), $this->updateData($order, ['items' => [['product_id' => $product->id, 'quantity' => 3]]]))->assertSessionHas('error');
        } finally {
            Event::forget('eloquent.creating: '.OrderItem::class);
        }

        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame(2, $order->items()->sole()->quantity);
        $this->assertSame(0, $order->fresh()->lock_version);
        $this->assertSame($order->total_amount, $order->fresh()->total_amount);
    }

    public function test_failed_cancellation_rolls_back_stock_restoration(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product);
        Event::listen('eloquent.updating: '.Order::class, function (): void {
            throw new RuntimeException('Simulated cancellation failure.');
        });

        try {
            $this->post(route('orders.cancel', $order), ['lock_version' => 0])->assertSessionHas('error');
        } finally {
            Event::forget('eloquent.updating: '.Order::class);
        }

        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertNull($order->fresh()->cancelled_at);
    }

    public function test_order_amount_overflow_rolls_back_and_zero_prices_work(): void
    {
        $product = Product::factory()->create(['price' => '9999999999.99', 'stock_quantity' => 1000000]);

        $this->post(route('orders.store'), $this->orderData($product, ['items' => [['product_id' => $product->id, 'quantity' => 1000000]]]))->assertSessionHasErrors('items.0.quantity');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1000000, $product->fresh()->stock_quantity);
        $product->update(['price' => '0.00']);
        $order = $this->placeOrder($product);
        $this->assertSame('0.00', $order->total_amount);
    }

    public function test_escapes_order_snapshots(): void
    {
        $order = Order::factory()->create(['customer_name_snapshot' => '<script>alert(1)</script>', 'customer_address_snapshot' => '<img src=x onerror=alert(1)>']);

        $this->get(route('orders.show', $order))->assertOk()->assertSee($order->customer_name_snapshot)
            ->assertDontSee($order->customer_name_snapshot, false)->assertSee($order->customer_address_snapshot)
            ->assertDontSee($order->customer_address_snapshot, false);
    }

    public function test_duplicate_submission_after_stock_exhaustion_still_resolves_to_original_order(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 2]);
        $data = $this->orderData($product);
        $this->post(route('orders.store'), $data)->assertSessionHas('success');
        $order = Order::sole();

        $this->post(route('orders.store'), $data)->assertRedirect(route('orders.show', $order))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_pending_cancellation_restores_all_products_and_linked_products_remain_protected(): void
    {
        $first = Product::factory()->create(['stock_quantity' => 10]);
        $second = Product::factory()->create(['stock_quantity' => 20]);
        $order = $this->placeOrder($first, ['items' => [['product_id' => $first->id, 'quantity' => 2], ['product_id' => $second->id, 'quantity' => 3]]]);
        $total = $order->total_amount;

        $this->post(route('orders.cancel', $order), ['lock_version' => 0])->assertSessionHas('success');

        $this->assertSame(10, $first->fresh()->stock_quantity);
        $this->assertSame(20, $second->fresh()->stock_quantity);
        $this->assertSame($total, $order->fresh()->total_amount);
        $this->assertSame(2, $order->items()->count());
        $this->delete(route('products.destroy', $first))->assertSessionHas('error', 'This product is referenced by an order and cannot be deleted.');
        $this->assertModelExists($first);
    }

    public function test_cancellation_stock_overflow_rolls_back_every_change(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product);
        $product->update(['stock_quantity' => 4294967295]);

        $this->post(route('orders.cancel', $order), ['lock_version' => 0])->assertSessionHasErrors('items');

        $this->assertSame(4294967295, $product->fresh()->stock_quantity);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(0, $order->fresh()->lock_version);
    }

    public function test_aggregate_total_overflow_rolls_back_prior_stock_adjustments(): void
    {
        $first = Product::factory()->create(['price' => '9999999999.99', 'stock_quantity' => 10000]);
        $second = Product::factory()->create(['price' => '100.00', 'stock_quantity' => 1]);
        $data = $this->orderData($first, ['items' => [['product_id' => $first->id, 'quantity' => 10000], ['product_id' => $second->id, 'quantity' => 1]]]);

        $this->post(route('orders.store'), $data)->assertSessionHasErrors('items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10000, $first->fresh()->stock_quantity);
        $this->assertSame(1, $second->fresh()->stock_quantity);
    }

    public function test_insufficient_stock_during_update_preserves_previous_order(): void
    {
        $first = Product::factory()->create(['stock_quantity' => 10]);
        $second = Product::factory()->create(['stock_quantity' => 0]);
        $order = $this->placeOrder($first);

        $this->put(route('orders.update', $order), $this->updateData($order, ['items' => [['product_id' => $first->id, 'quantity' => 3], ['product_id' => $second->id, 'quantity' => 1]]]))
            ->assertSessionHasErrors('items.1.quantity');

        $this->assertSame(8, $first->fresh()->stock_quantity);
        $this->assertSame(2, $order->items()->sole()->quantity);
        $this->assertSame(0, $order->fresh()->lock_version);
    }

    public function test_paid_order_can_advance_without_deducting_stock_again(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product, ['payment_status' => 'paid']);

        $this->put(route('orders.update', $order), $this->updateData($order, ['status' => 'processing']))->assertSessionHasNoErrors();
        $order->refresh();
        $this->put(route('orders.update', $order), $this->updateData($order, ['status' => 'completed']))->assertSessionHasNoErrors();

        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_processing_date_cannot_change_and_new_customer_cannot_be_selected(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product, ['status' => 'processing']);

        $this->put(route('orders.update', $order), $this->updateData($order, ['order_date' => '2026-11-07']))->assertSessionHasErrors('items');

        $this->assertSame('2026-11-08', $order->fresh()->order_date->toDateString());
    }

    public function test_empty_update_items_and_duplicate_normalized_ids_are_rejected(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = $this->placeOrder($product);

        $this->put(route('orders.update', $order), $this->updateData($order, ['items' => []]))->assertSessionHasErrors('items');
        $this->put(route('orders.update', $order), $this->updateData($order, ['items' => [['product_id' => $product->id, 'quantity' => 1], ['product_id' => (string) $product->id, 'quantity' => 1]]]))->assertSessionHasErrors('items.0.product_id');

        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public function test_product_can_be_updated_with_current_stock_snapshot_after_order(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10, 'price' => '10.00']);
        $order = $this->placeOrder($product);

        $this->put(route('products.update', $product), ['name' => 'Renamed', 'category' => 'Gifts', 'price' => '99.99', 'stock_quantity' => 9, 'original_stock_quantity' => 8])->assertSessionHas('success');

        $this->assertSame(9, $product->fresh()->stock_quantity);
        $this->assertSame('10.00', $order->items()->sole()->unit_price);
    }

    public function test_validation_feedback_retains_dynamic_row_indexes(): void
    {
        $errors = ['default' => ['format' => ':message', 'messages' => ['items.2.quantity' => ['Quantity must be positive.']]]];

        $this->withSession(['errors' => $errors, '_old_input' => ['items' => [2 => ['product_id' => '', 'quantity' => 0]]]])
            ->get(route('orders.create'))->assertOk()->assertSee('items[2][quantity]', false)->assertSee('Quantity must be positive.');
    }

    public function test_product_foreign_key_restricts_direct_deletion(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $this->placeOrder($product);

        $this->expectException(QueryException::class);

        $product->delete();
    }

    private function orderData(Product $product, array $overrides = []): array
    {
        return array_replace(['customer_id' => $overrides['customer_id'] ?? Customer::factory()->create()->id, 'creation_token' => (string) Str::uuid(), 'order_date' => '2026-11-08', 'status' => 'pending', 'payment_status' => 'unpaid', 'items' => [['product_id' => $product->id, 'quantity' => 2]]], $overrides);
    }

    private function placeOrder(Product $product, array $overrides = []): Order
    {
        $data = $this->orderData($product, $overrides);
        $this->post(route('orders.store'), $data)->assertSessionHasNoErrors()->assertSessionHas('success');

        return Order::where('creation_token', $data['creation_token'])->firstOrFail();
    }

    private function updateData(Order $order, array $overrides = []): array
    {
        return array_replace(['lock_version' => $order->lock_version, 'status' => $order->status->value, 'payment_status' => $order->payment_status->value], $overrides);
    }
}
