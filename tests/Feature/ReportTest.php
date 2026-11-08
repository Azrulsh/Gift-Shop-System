<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ReportService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Report tests must use isolated in-memory SQLite.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-11-08 12:00:00');
    }

    public function test_dashboard_counts_and_revenue_distinguish_statuses_and_deleted_profiles(): void
    {
        $customer = Customer::factory()->create();
        Customer::factory()->create()->delete();
        Product::factory()->count(2)->create();
        Supplier::factory()->create();
        Supplier::factory()->inactive()->create();
        Supplier::factory()->create()->delete();
        foreach ([['pending', 'paid', '10.10'], ['pending', 'unpaid', '20.20'], ['processing', 'paid', '30.30'], ['processing', 'unpaid', '40.40'], ['completed', 'paid', '50.50'], ['cancelled', 'paid', '999.00'], ['cancelled', 'unpaid', '888.00']] as [$status, $payment, $amount]) {
            $this->order($customer, $status, $payment, $amount);
        }

        $this->get(route('reports.index'))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['paid_revenue'] === '90.90' && $summary['outstanding_value'] === '60.60' && (int) $summary['order_count'] === 7 && (int) $summary['pending_count'] === 2 && (int) $summary['processing_count'] === 2 && (int) $summary['completed_count'] === 1 && (int) $summary['cancelled_count'] === 2)
            ->assertViewHas('customerCount', 1)->assertViewHas('productCount', 2)
            ->assertViewHas('supplierSummary', fn ($summary) => (int) $summary['total'] === 2 && (int) $summary['active'] === 1 && (int) $summary['inactive'] === 1)
            ->assertSee('not actual payment receipt dates')->assertSee('Reports');
    }

    public function test_sales_totals_do_not_double_count_multi_item_orders_or_identical_totals(): void
    {
        $customer = Customer::factory()->create();
        $products = Product::factory()->count(2)->create();
        foreach (range(1, 2) as $number) {
            $order = $this->order($customer, 'completed', 'paid', '12.34');
            $this->item($order, $products[0], 1, '10.00');
            $this->item($order, $products[1], 1, '2.34');
        }

        $this->get(route('reports.sales'))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['paid_revenue'] === '24.68' && (int) $summary['order_count'] === 2)
            ->assertViewHas('orders', fn ($orders) => $orders->total() === 2);
        $this->get(route('reports.index'))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['paid_revenue'] === '24.68');
    }

    #[DataProvider('revenueStates')]
    public function test_sales_recognition_uses_payment_and_cancellation_rules(string $status, string $payment, string $paid, string $outstanding): void
    {
        $customer = Customer::factory()->create();
        $this->order($customer, $status, $payment, '12.34');

        $this->get(route('reports.sales', ['status' => $status, 'payment_status' => $payment]))->assertOk()
            ->assertViewHas('summary', fn ($summary) => $summary['paid_revenue'] === $paid && $summary['outstanding_value'] === $outstanding);
    }

    public static function revenueStates(): array
    {
        return [
            'pending paid' => ['pending', 'paid', '12.34', '0.00'],
            'pending unpaid' => ['pending', 'unpaid', '0.00', '12.34'],
            'processing paid' => ['processing', 'paid', '12.34', '0.00'],
            'processing unpaid' => ['processing', 'unpaid', '0.00', '12.34'],
            'completed paid' => ['completed', 'paid', '12.34', '0.00'],
            'inconsistent completed unpaid' => ['completed', 'unpaid', '0.00', '12.34'],
            'cancelled paid' => ['cancelled', 'paid', '0.00', '0.00'],
            'cancelled unpaid' => ['cancelled', 'unpaid', '0.00', '0.00'],
        ];
    }

    public function test_month_to_date_defaults_include_boundaries_and_preserve_snapshots(): void
    {
        $customer = Customer::factory()->create(['name' => 'Current profile']);
        $this->order($customer, 'completed', 'paid', '99.00', '2026-10-31');
        $first = $this->order($customer, 'pending', 'paid', '1.00', '2026-11-01');
        $last = $this->order($customer, 'processing', 'paid', '2.00', '2026-11-08');
        $this->order($customer, 'pending', 'paid', '99.00', '2026-11-09');
        $customer->delete();

        $this->get(route('reports.sales'))->assertOk()->assertSee('Original customer')->assertDontSee('Current profile')
            ->assertSee($first->order_number)->assertSee($last->order_number)
            ->assertViewHas('filters', ['date_from' => '2026-11-01', 'date_to' => '2026-11-08'])
            ->assertViewHas('summary', fn ($summary) => $summary['paid_revenue'] === '3.00');
    }

    #[DataProvider('invalidDateRanges')]
    public function test_rejects_invalid_dates_for_screens_and_exports(array $filters, string $error): void
    {
        foreach (['reports.sales', 'reports.products', 'reports.sales.export', 'reports.products.export'] as $route) {
            $this->from(route('reports.index'))->get(route($route, $filters))->assertRedirect(route('reports.index'))->assertSessionHasErrors($error);
        }
    }

    public static function invalidDateRanges(): array
    {
        return [
            'missing end' => [['date_from' => '2026-11-01'], 'date_to'],
            'missing start' => [['date_to' => '2026-11-08'], 'date_from'],
            'reversed' => [['date_from' => '2026-11-08', 'date_to' => '2026-11-01'], 'date_from'],
            'future end' => [['date_from' => '2026-11-01', 'date_to' => '2026-11-09'], 'date_to'],
            'invalid format' => [['date_from' => 'yesterday', 'date_to' => '2026-11-08'], 'date_from'],
            'impossible date' => [['date_from' => '2026-02-30', 'date_to' => '2026-11-08'], 'date_from'],
            'array date' => [['date_from' => ['invalid'], 'date_to' => '2026-11-08'], 'date_from'],
        ];
    }

    public function test_sales_status_filters_exclude_other_orders_and_reject_invalid_values(): void
    {
        $customer = Customer::factory()->create();
        $wanted = $this->order($customer, 'processing', 'paid', '1.00');
        $other = $this->order($customer, 'pending', 'paid', '2.00');
        $this->order($customer, 'processing', 'unpaid', '3.00');

        $this->get(route('reports.sales', ['status' => 'processing', 'payment_status' => 'paid']))->assertOk()->assertSee($wanted->order_number)->assertDontSee($other->order_number)
            ->assertViewHas('summary', fn ($summary) => $summary['paid_revenue'] === '1.00' && $summary['outstanding_value'] === '0.00');
        $this->from(route('reports.sales'))->get(route('reports.sales', ['status' => 'invalid', 'payment_status' => 'refunded']))->assertSessionHasErrors(['status', 'payment_status']);
    }

    public function test_product_aggregates_use_historical_subtotals_exclude_cancellation_and_respect_dates(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['name' => 'New name', 'price' => '999.00', 'stock_quantity' => 4]);
        $unsold = Product::factory()->create();
        $this->item($this->order($customer, 'pending', 'paid', '20.00'), $product, 2, '20.00');
        $this->item($this->order($customer, 'processing', 'unpaid', '15.00'), $product, 3, '15.00');
        $this->item($this->order($customer, 'cancelled', 'paid', '99.00'), $product, 99, '99.00');
        $this->item($this->order($customer, 'completed', 'paid', '99.00', '2026-10-31'), $product, 99, '99.00');

        $this->get(route('reports.products'))->assertOk()->assertSee('New name')->assertSee('999.00')
            ->assertViewHas('products', function ($products) use ($product, $unsold): bool {
                $sold = $products->firstWhere('id', $product->id);
                $zero = $products->firstWhere('id', $unsold->id);

                return (int) $sold->quantity_sold === 5 && ReportService::money($sold->order_value) === '35.00'
                    && ReportService::money($sold->paid_revenue) === '20.00' && ReportService::money($sold->outstanding_value) === '15.00'
                    && (int) $zero->quantity_sold === 0 && ReportService::money($zero->paid_revenue) === '0.00';
            });
    }

    public function test_stock_indicators_use_exact_approved_boundaries(): void
    {
        foreach ([0, 1, 5, 6] as $quantity) {
            Product::factory()->create(['name' => 'Stock '.$quantity, 'stock_quantity' => $quantity]);
        }

        $rows = $this->csv($this->get(route('reports.products.export')));

        $this->assertSame(['Out of Stock', 'Low Stock', 'Low Stock', 'In Stock'], array_column(array_slice($rows, 1), 4));
        $this->get(route('reports.products'))->assertOk()->assertSee('Out of Stock')->assertSee('Low Stock')->assertSee('In Stock');
    }

    public function test_customer_spending_keeps_deleted_profiles_and_excludes_cancelled_values(): void
    {
        $customer = Customer::factory()->create(['name' => 'Deleted customer']);
        $empty = Customer::factory()->create(['name' => 'No orders customer']);
        $this->order($customer, 'completed', 'paid', '10.10', '2026-10-01');
        $this->order($customer, 'pending', 'unpaid', '20.20', '2026-11-01');
        $this->order($customer, 'cancelled', 'paid', '999.00', '2026-11-08');
        $customer->delete();

        $this->get(route('reports.customers'))->assertOk()->assertSee('Deleted customer')->assertSee('Deleted')->assertSee('No orders customer')
            ->assertViewHas('customers', function ($customers) use ($customer, $empty): bool {
                $row = $customers->firstWhere('id', $customer->id);
                $zero = $customers->firstWhere('id', $empty->id);

                return (int) $row->order_count === 3 && ReportService::money($row->paid_spending) === '10.10'
                    && ReportService::money($row->outstanding_value) === '20.20' && $row->latest_order_date === '2026-11-08'
                    && (int) $zero->order_count === 0 && $zero->latest_order_date === null;
            });
    }

    public function test_supplier_summary_includes_both_statuses_and_excludes_deleted_profiles(): void
    {
        Supplier::factory()->count(2)->create();
        Supplier::factory()->inactive()->create();
        Supplier::factory()->create(['name' => 'Hidden supplier'])->delete();

        $this->get(route('reports.suppliers'))->assertOk()->assertDontSee('Hidden supplier')
            ->assertViewHas('summary', fn ($summary) => (int) $summary['total'] === 3 && (int) $summary['active'] === 2 && (int) $summary['inactive'] === 1)
            ->assertSee('View a supplier profile to see its associated products.');
    }

    public function test_trend_fills_zero_days_and_uses_order_dates_with_current_payment_status(): void
    {
        $customer = Customer::factory()->create();
        $this->order($customer, 'pending', 'paid', '10.00', '2026-10-10');
        $this->order($customer, 'processing', 'paid', '20.00', '2026-11-08');
        $this->order($customer, 'completed', 'paid', '99.00', '2026-10-09');
        $this->order($customer, 'cancelled', 'paid', '99.00');
        $this->order($customer, 'pending', 'unpaid', '99.00');

        $this->get(route('reports.index'))->assertOk()->assertViewHas('trend', fn ($trend) => count($trend) === 30 && $trend[0] === ['date' => '2026-10-10', 'revenue' => '10.00'] && $trend[1]['revenue'] === '0.00' && $trend[29] === ['date' => '2026-11-08', 'revenue' => '20.00']);
    }

    public function test_top_products_are_ranked_by_all_time_paid_revenue(): void
    {
        $customer = Customer::factory()->create();
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $this->item($this->order($customer, 'completed', 'paid', '10.00', '2026-01-01'), $first, 1, '10.00');
        $this->item($this->order($customer, 'processing', 'paid', '20.00'), $second, 1, '20.00');
        $this->item($this->order($customer, 'pending', 'unpaid', '100.00'), $first, 10, '100.00');

        $this->get(route('reports.index'))->assertOk()->assertViewHas('topProducts', fn ($products) => $products->pluck('id')->all() === [$second->id, $first->id]);
    }

    #[DataProvider('detailReports')]
    public function test_paginates_details_and_exports_all_rows_independent_of_page(string $report, string $viewKey): void
    {
        match ($report) {
            'sales' => Order::factory()->count(16)->create(['order_date' => '2026-11-08', 'status' => 'pending', 'payment_status' => 'paid', 'total_amount' => '1.00']),
            'products' => Product::factory()->count(16)->create(),
            'customers' => Customer::factory()->count(16)->create(),
            'suppliers' => Supplier::factory()->count(16)->create(),
        };

        $this->get(route('reports.'.$report))->assertOk()->assertViewHas($viewKey, fn ($page) => $page->total() === 16 && $page->count() === 15);
        $this->get(route('reports.'.$report, ['page' => 2]))->assertOk()->assertViewHas($viewKey, fn ($page) => $page->count() === 1);
        $response = $this->get(route('reports.'.$report.'.export', ['page' => 2]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertCount(17, $this->csv($response));
    }

    public static function detailReports(): array
    {
        return ['sales' => ['sales', 'orders'], 'products' => ['products', 'products'], 'customers' => ['customers', 'customers'], 'suppliers' => ['suppliers', 'suppliers']];
    }

    public function test_sales_summary_covers_all_pages_and_pagination_preserves_filters(): void
    {
        Order::factory()->count(16)->create(['order_date' => '2026-11-08', 'status' => 'processing', 'payment_status' => 'paid', 'total_amount' => '0.10']);

        $this->get(route('reports.sales', ['status' => 'processing', 'payment_status' => 'paid']))->assertOk()
            ->assertSee('date_from=2026-11-01', false)->assertSee('payment_status=paid', false)->assertSee('status=processing', false)
            ->assertViewHas('summary', fn ($summary) => $summary['paid_revenue'] === '1.60');
    }

    public function test_csv_filters_snapshot_names_and_qualifying_revenue_columns(): void
    {
        $customer = Customer::factory()->create(['name' => 'Changed name']);
        $wanted = $this->order($customer, 'processing', 'paid', '12.34');
        $this->order($customer, 'processing', 'unpaid', '99.00');
        $this->order($customer, 'cancelled', 'paid', '99.00');

        $rows = $this->csv($this->get(route('reports.sales.export', ['status' => 'processing', 'payment_status' => 'paid']))->assertDownload('sales-2026-11-01-to-2026-11-08.csv'));

        $this->assertCount(2, $rows);
        $this->assertSame([$wanted->order_number, '2026-11-08', 'Original customer', 'snapshot@example.com', '12.34', 'Processing', 'Paid', '12.34', '0.00'], $rows[1]);
    }

    public function test_product_csv_matches_filtered_historical_aggregates(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['name' => 'Current name', 'price' => '99.99', 'stock_quantity' => 5]);
        $this->item($this->order($customer, 'completed', 'paid', '10.00'), $product, 2, '10.00');
        $this->item($this->order($customer, 'completed', 'paid', '99.00', '2026-10-31'), $product, 9, '99.00');

        $rows = $this->csv($this->get(route('reports.products.export')));

        $this->assertSame([(string) $product->id, 'Current name', '99.99', '5', 'Low Stock', '2', '10.00', '10.00', '0.00'], $rows[1]);
    }

    public function test_csv_escapes_commas_quotes_newlines_and_formula_prefixes(): void
    {
        $supplier = Supplier::factory()->create(['name' => "Gift, \"Shop\"\nWholesale", 'contact_person' => '=HYPERLINK("bad")', 'phone' => '+60123456789', 'address' => " \t@SUM(1,1)"]);

        $rows = $this->csv($this->get(route('reports.suppliers.export')));

        $this->assertSame($supplier->name, $rows[1][1]);
        $this->assertSame("'".$supplier->contact_person, $rows[1][2]);
        $this->assertSame("'".$supplier->phone, $rows[1][4]);
        $this->assertSame("'".$supplier->address, $rows[1][5]);
    }

    public function test_csv_streams_across_multiple_batches_without_duplicates(): void
    {
        Supplier::factory()->count(205)->create();

        $rows = $this->csv($this->get(route('reports.suppliers.export')));

        $this->assertCount(206, $rows);
        $ids = array_column(array_slice($rows, 1), 0);
        $this->assertCount(205, array_unique($ids));
    }

    public function test_empty_reports_and_exports_have_clear_states_and_headers(): void
    {
        $this->get(route('reports.index'))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['paid_revenue'] === '0.00' && $summary['outstanding_value'] === '0.00');
        foreach (['sales' => 'No orders match', 'products' => 'No products available', 'customers' => 'No customers available', 'suppliers' => 'No suppliers available'] as $report => $message) {
            $this->get(route('reports.'.$report))->assertOk()->assertSee($message);
            $this->assertCount(1, $this->csv($this->get(route('reports.'.$report.'.export'))));
        }
        $this->get('/reports/missing')->assertNotFound();
        $this->get('/reports/missing/export')->assertNotFound();
    }

    public function test_customer_report_uses_bounded_queries_instead_of_n_plus_one(): void
    {
        Customer::factory()->count(15)->hasOrders(2)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get(route('reports.customers'))->assertOk();

        $this->assertLessThanOrEqual(4, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_reports_escape_html_and_do_not_modify_existing_data(): void
    {
        $customer = Customer::factory()->create(['name' => '<script>alert(1)</script>']);
        $product = Product::factory()->create(['name' => '<img src=x onerror=alert(1)>', 'stock_quantity' => 5]);
        $order = $this->order($customer, 'completed', 'paid', '10.00');
        $this->item($order, $product, 1, '10.00');

        $this->get(route('reports.customers'))->assertOk()->assertSee($customer->name)->assertDontSee($customer->name, false);
        $this->get(route('reports.products'))->assertOk()->assertSee($product->name)->assertDontSee($product->name, false);
        $this->csv($this->get(route('reports.products.export')));

        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame('10.00', $order->fresh()->total_amount);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_large_decimal_strings_are_formatted_without_integer_or_float_conversion(): void
    {
        $this->assertSame('123456789012345678901234.56', ReportService::money('123456789012345678901234.56'));
    }

    #[DataProvider('revenueStates')]
    public function test_product_and_customer_csv_use_the_same_recognition_rules(string $status, string $payment, string $paid, string $outstanding): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $order = $this->order($customer, $status, $payment, '12.34');
        $this->item($order, $product, 2, '12.34');
        $customer->delete();

        $productRows = $this->csv($this->get(route('reports.products.export')));
        $customerRows = $this->csv($this->get(route('reports.customers.export')));

        $this->assertSame($status === 'cancelled' ? '0' : '2', $productRows[1][5]);
        $this->assertSame($status === 'cancelled' ? '0.00' : '12.34', $productRows[1][6]);
        $this->assertSame($paid, $productRows[1][7]);
        $this->assertSame($outstanding, $productRows[1][8]);
        $this->assertSame('Deleted', $customerRows[1][3]);
        $this->assertSame('1', $customerRows[1][4]);
        $this->assertSame($paid, $customerRows[1][5]);
        $this->assertSame($outstanding, $customerRows[1][6]);
    }

    public function test_custom_date_range_applies_identically_to_screens_and_exports(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $first = $this->order($customer, 'completed', 'paid', '1.25', '2026-10-01');
        $this->item($first, $product, 1, '1.25');
        $last = $this->order($customer, 'completed', 'paid', '2.75', '2026-10-31');
        $this->item($last, $product, 1, '2.75');
        $excluded = $this->order($customer, 'completed', 'paid', '99.00', '2026-11-01');
        $this->item($excluded, $product, 1, '99.00');
        $filters = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];

        $this->get(route('reports.sales', $filters))->assertOk()->assertSee($first->order_number)->assertSee($last->order_number)->assertDontSee($excluded->order_number)
            ->assertViewHas('summary', fn ($summary) => $summary['paid_revenue'] === '4.00');
        $salesRows = $this->csv($this->get(route('reports.sales.export', $filters)));
        $productRows = $this->csv($this->get(route('reports.products.export', $filters)));

        $this->assertCount(3, $salesRows);
        $this->assertSame('4.00', $productRows[1][7]);
        $this->assertSame('2', $productRows[1][5]);
    }

    public function test_supplier_csv_contains_current_contacts_and_statuses_only(): void
    {
        $active = Supplier::factory()->create();
        $inactive = Supplier::factory()->inactive()->create();
        Supplier::factory()->create()->delete();

        $rows = $this->csv($this->get(route('reports.suppliers.export')));

        $this->assertCount(3, $rows);
        $this->assertSame($active->email, $rows[1][3]);
        $this->assertSame('Active', $rows[1][6]);
        $this->assertSame($inactive->email, $rows[2][3]);
        $this->assertSame('Inactive', $rows[2][6]);
    }

    private function order(Customer $customer, string $status, string $payment, string $amount, string $date = '2026-11-08'): Order
    {
        return Order::factory()->for($customer)->create(['status' => $status, 'payment_status' => $payment, 'total_amount' => $amount, 'order_date' => $date, 'customer_name_snapshot' => 'Original customer', 'customer_email_snapshot' => 'snapshot@example.com']);
    }

    private function item(Order $order, Product $product, int $quantity, string $subtotal): OrderItem
    {
        return OrderItem::factory()->for($order)->for($product)->create(['quantity' => $quantity, 'subtotal' => $subtotal, 'unit_price' => '1.00', 'product_name_snapshot' => 'Historical product']);
    }

    private function csv(TestResponse $response): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $response->assertOk()->streamedContent());
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
