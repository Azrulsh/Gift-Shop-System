<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FinalReviewRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Review tests require isolated in-memory SQLite.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[DataProvider('malformedForms')]
    public function test_malformed_fields_return_readable_forms(string $resource, bool $editing, array $fields): void
    {
        $model = match ($resource) {
            'products' => Product::factory()->create(),
            'customers' => Customer::factory()->create(),
            'suppliers' => Supplier::factory()->create(),
            default => Order::factory()->create(['status' => 'pending', 'payment_status' => 'unpaid']),
        };
        $url = route($resource.($editing ? '.edit' : '.create'), $editing ? $model : []);
        $data = array_fill_keys($fields, ['invalid']);
        $response = $editing
            ? $this->from($url)->put(route($resource.'.update', $model), $data)
            : $this->from($url)->post(route($resource.'.store'), $data);
        $response->assertRedirect($url)->assertSessionHasErrors($fields);
        $this->withCookie(config('session.cookie'), app('session.store')->getId());
        $this->followRedirects($response)->assertOk()->assertSee('Please correct the following errors.');
    }

    public static function malformedForms(): array
    {
        $cases = [];
        foreach ([
            'products' => ['name', 'category', 'price', 'stock_quantity', 'description'],
            'customers' => ['name', 'email', 'phone', 'address'],
            'suppliers' => ['name', 'contact_person', 'email', 'phone', 'address'],
            'orders' => ['creation_token', 'order_date'],
        ] as $resource => $fields) {
            $cases[$resource.' create'] = [$resource, false, $fields];
            $cases[$resource.' update'] = [$resource, true, match ($resource) {
                'orders' => ['lock_version', 'order_date'],
                'products' => [...$fields, 'original_stock_quantity'],
                default => $fields,
            }];
        }

        return $cases;
    }

    #[DataProvider('descriptionLengths')]
    public function test_description_limits(bool $editing, string $character, int $length): void
    {
        $description = str_repeat($character, $length);
        $product = Product::factory()->create(['stock_quantity' => 12, 'description' => 'Original description']);
        $data = ['name' => 'Reviewed gift', 'category' => 'Gifts', 'price' => '12.50', 'stock_quantity' => 12, 'description' => $description];
        $url = route('products.'.($editing ? 'edit' : 'create'), $editing ? $product : []);
        $response = $editing
            ? $this->from($url)->put(route('products.update', $product), $data + ['original_stock_quantity' => 12])
            : $this->from($url)->post(route('products.store'), $data);
        if ($length <= 10000) {
            $response->assertSessionHasNoErrors()->assertSessionHas('success');
            $saved = $editing ? $product->fresh() : Product::latest('id')->first();
            $this->assertSame($description, $saved->description);
        } else {
            $response->assertRedirect($url)->assertSessionHasErrors('description');
            $this->withCookie(config('session.cookie'), app('session.store')->getId());
            $this->followRedirects($response)->assertOk()->assertSee('maxlength="10000"', false);
            $this->assertSame('Original description', $product->fresh()->description);
            $this->assertDatabaseCount('products', 1);
        }
    }

    public static function descriptionLengths(): array
    {
        $cases = [];
        foreach ([false, true] as $editing) {
            foreach (['ASCII' => 'a', 'multibyte' => '🎁'] as $encoding => $character) {
                foreach ([9999, 10000, 10001] as $length) {
                    $cases[($editing ? 'update ' : 'create ').$encoding.' '.$length] = [$editing, $character, $length];
                }
            }
        }

        return $cases;
    }

    public function test_valid_old_input_stays_escaped(): void
    {
        $unsafe = '<script>alert(1)</script>';
        $response = $this->from(route('products.create'))->post(route('products.store'), ['name' => ['invalid'], 'description' => $unsafe]);
        $response->assertSessionHasErrors('name');
        $this->withCookie(config('session.cookie'), app('session.store')->getId());
        $this->followRedirects($response)->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false)
            ->assertSee('maxlength="10000"', false);
    }

    #[DataProvider('performanceActivity')]
    public function test_dashboard_empty_state_uses_non_cancelled_activity(?string $status, bool $empty): void
    {
        $product = Product::factory()->create();
        if ($status !== null) {
            $order = Order::factory()->create(['status' => $status, 'payment_status' => 'unpaid', 'total_amount' => '0.00']);
            OrderItem::factory()->for($order)->for($product)->create(['quantity' => 1, 'unit_price' => '0.00', 'subtotal' => '0.00']);
        }
        foreach (['dashboard', 'reports.index'] as $route) {
            $response = $this->get(route($route))->assertOk()
                ->assertViewHas('topProducts', fn ($products) => $products->isEmpty() === $empty)
                ->assertViewHas('productCount', 1);
            if ($empty) {
                $response->assertSee('No product performance data yet.');
            } else {
                $response->assertDontSee('No product performance data yet.')->assertSee($product->name);
            }
        }
        $this->get(route('reports.products'))->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 1);
    }

    public static function performanceActivity(): array
    {
        return ['inventory only' => [null, true], 'cancelled only' => ['cancelled', true], 'free pending reservation' => ['pending', false], 'free processing reservation' => ['processing', false]];
    }

    public function test_sparse_order_rows_have_unique_accessible_errors(): void
    {
        $response = $this->from(route('orders.create'))->post(route('orders.store'), [
            'customer_id' => Customer::factory()->create()->id,
            'creation_token' => (string) Str::uuid(), 'order_date' => now()->toDateString(), 'status' => 'pending',
            'items' => [2 => ['product_id' => 999, 'quantity' => 0], 7 => ['product_id' => 998, 'quantity' => 0]],
        ]);
        $response->assertSessionHasErrors(['items.2.product_id', 'items.2.quantity', 'items.7.product_id', 'items.7.quantity']);
        $this->withCookie(config('session.cookie'), app('session.store')->getId());
        $page = $this->followRedirects($response)->assertOk();
        $document = new \DOMDocument;
        $document->loadHTML($page->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        foreach ([2, 7, '__INDEX__'] as $index) {
            foreach (['product', 'quantity'] as $field) {
                $id = $field.'-'.$index;
                $control = $xpath->query('//*[@id="'.$id.'"]');
                $this->assertCount(1, $control);
                $this->assertSame($index === '__INDEX__' ? 'false' : 'true', $control->item(0)->getAttribute('aria-invalid'));
                $this->assertSame($id.'-error', $control->item(0)->getAttribute('aria-describedby'));
                $messages = $xpath->query('//*[@id="'.$id.'-error"]');
                $this->assertCount(1, $messages);
                if ($index !== '__INDEX__') {
                    $this->assertNotSame('', trim($messages->item(0)->textContent));
                }
            }
        }
        $this->assertDatabaseCount('orders', 0);
    }
}
