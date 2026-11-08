<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Dashboard tests must use isolated in-memory SQLite.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-11-08 12:00:00');
    }

    public function test_dashboard_renders_existing_report_view_with_empty_data(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertViewIs('reports.index')->assertSee('>Dashboard</h1>', false)
            ->assertSee('Paid sales revenue')->assertSee('Outstanding unpaid value')->assertSee('Total orders')
            ->assertSee('Current customers')->assertSee('Total products')->assertSee('Current suppliers')
            ->assertSee('Daily paid revenue')->assertSee('Top products by paid revenue')
            ->assertViewHas('summary', fn ($summary) => (int) $summary['order_count'] === 0 && $summary['paid_revenue'] === '0.00' && $summary['outstanding_value'] === '0.00')
            ->assertViewHas('trend', fn ($trend) => count($trend) === 30);
    }

    public function test_dashboard_and_reports_share_summary_trend_and_top_product_data(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['name' => 'Gift box', 'price' => '99.00', 'stock_quantity' => 5]);
        Supplier::factory()->inactive()->create();
        $paid = Order::factory()->for($customer)->create(['status' => 'processing', 'payment_status' => 'paid', 'total_amount' => '10.00', 'order_date' => '2026-11-08']);
        OrderItem::factory()->for($paid)->for($product)->create(['quantity' => 2, 'unit_price' => '5.00', 'subtotal' => '10.00']);
        Order::factory()->for($customer)->create(['status' => 'pending', 'payment_status' => 'unpaid', 'total_amount' => '20.00']);
        Order::factory()->for($customer)->create(['status' => 'cancelled', 'payment_status' => 'paid', 'total_amount' => '999.00']);

        $dashboard = $this->get(route('dashboard'))->assertOk()->assertSee('Gift box')->assertSee('10.00')
            ->assertViewHas('summary', fn ($summary) => $summary['paid_revenue'] === '10.00' && $summary['outstanding_value'] === '20.00' && (int) $summary['order_count'] === 3)
            ->assertViewHas('productCount', 1)->assertViewHas('customerCount', 1)
            ->assertViewHas('supplierSummary', fn ($summary) => (int) $summary['total'] === 1);
        $reports = $this->get(route('reports.index'))->assertOk()->assertViewIs('reports.index')->assertSee('>Reports overview</h1>', false);

        $this->assertSame($dashboard->viewData('summary'), $reports->viewData('summary'));
        $this->assertSame($dashboard->viewData('trend'), $reports->viewData('trend'));
        $this->assertSame($dashboard->viewData('topProducts')->toArray(), $reports->viewData('topProducts')->toArray());
        $this->assertSame('10.00', $dashboard->viewData('trend')[29]['revenue']);
    }

    public function test_navigation_brand_links_and_active_states_distinguish_dashboard_and_reports(): void
    {
        $dashboard = $this->get(route('dashboard'))->assertOk()
            ->assertDontSee('aria-label="Report navigation"', false);
        $document = new \DOMDocument;
        $document->loadHTML($dashboard->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $active = $xpath->query('//nav[@aria-label="Main navigation"]//a[@aria-current="page"]');
        $this->assertCount(1, $active);
        $this->assertSame(route('dashboard'), $active->item(0)->getAttribute('href'));
        $brand = $xpath->query('//nav[@aria-label="Main navigation"]/a');
        $this->assertSame(route('dashboard'), $brand->item(0)->getAttribute('href'));
        foreach (['products', 'customers', 'orders', 'suppliers', 'reports'] as $resource) {
            $dashboard->assertSee('href="'.route($resource.'.index').'"', false);
        }

        $reports = $this->get(route('reports.index'))->assertOk()->assertSee('aria-label="Report navigation"', false);
        $document->loadHTML($reports->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $active = (new \DOMXPath($document))->query('//nav[@aria-label="Main navigation"]//a[@aria-current="page"]');
        $this->assertCount(1, $active);
        $this->assertSame(route('reports.index'), $active->item(0)->getAttribute('href'));
    }

    #[DataProvider('reportRoutes')]
    public function test_detailed_report_and_csv_export_remain_accessible(string $report): void
    {
        $this->get(route('reports.'.$report))->assertOk();
        $export = $this->get(route('reports.'.$report.'.export'))->assertOk()->assertDownload()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertNotEmpty($export->streamedContent());
    }

    public static function reportRoutes(): array
    {
        return ['sales' => ['sales'], 'products' => ['products'], 'customers' => ['customers'], 'suppliers' => ['suppliers']];
    }
}
