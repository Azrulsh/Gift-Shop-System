<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        if ($connection !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Product tests must use isolated in-memory SQLite.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_lists_products_newest_first_with_fifteen_per_page(): void
    {
        $products = Product::factory()->count(16)->create();

        $this->get(route('products.index'))->assertOk()
            ->assertSee($products->last()->name)
            ->assertDontSee($products->first()->name)
            ->assertViewHas('products', fn ($page) => $page->total() === 16 && $page->count() === 15);
        $this->get(route('products.index', ['page' => 2]))->assertOk()->assertSee($products->first()->name);
    }

    public function test_searches_name_or_category_and_preserves_search_on_pagination(): void
    {
        Product::factory()->count(15)->create(['name' => 'Ceramic mug', 'category' => 'Keepsakes']);
        Product::factory()->create(['name' => 'Keepsake box', 'category' => 'Home']);
        Product::factory()->create(['name' => 'Bouquet', 'category' => 'Flowers']);

        $this->get(route('products.index', ['search' => 'keepsake']))->assertOk()
            ->assertSee('Keepsake box')->assertDontSee('Bouquet')
            ->assertSee('search=keepsake', false)
            ->assertViewHas('products', fn ($page) => $page->total() === 16);
    }

    public function test_renders_distinct_empty_and_no_match_messages(): void
    {
        $this->get(route('products.index'))->assertOk()->assertSee('No products yet.');
        Product::factory()->create(['name' => 'Mug', 'category' => 'Home']);

        $this->get(route('products.index', ['search' => 'unmatched']))->assertOk()->assertSee('No products match your search.');
    }

    public function test_renders_create_show_and_edit_screens(): void
    {
        $product = Product::factory()->create(['price' => '12.50', 'stock_quantity' => 7]);

        $this->get(route('products.create'))->assertOk()->assertSee('Create product');
        $this->get(route('products.show', $product))->assertOk()->assertSee($product->name)->assertSee('12.50');
        $this->get(route('products.edit', $product))->assertOk()->assertSee('Save changes')->assertSee($product->name);
    }

    public function test_creates_product_with_image_and_ignores_unexpected_fields(): void
    {
        Storage::fake('public');
        $data = $this->productData() + ['image' => $this->imageUpload(), 'image_path' => 'untrusted.php', 'id' => 999];

        $response = $this->post(route('products.store'), $data);

        $product = Product::sole();
        $response->assertRedirect(route('products.show', $product))->assertSessionHas('success', 'Product created successfully.');
        $this->assertDatabaseHas('products', ['name' => 'Gift box', 'price' => '19.95', 'stock_quantity' => 4]);
        $this->assertNotSame(999, $product->id);
        $this->assertStringStartsWith('products/', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_creates_product_with_zero_price_and_stock_without_optional_fields(): void
    {
        $data = array_replace($this->productData(), ['description' => null, 'price' => '0', 'stock_quantity' => 0]);

        $this->post(route('products.store'), $data)->assertSessionHasNoErrors()->assertSessionHas('success');

        $product = Product::sole();
        $this->assertNull($product->image_path);
        $this->assertSame('0.00', $product->price);
        $this->assertSame(0, $product->stock_quantity);
    }

    public function test_rejects_missing_required_fields_without_creating_a_product(): void
    {
        $this->from(route('products.create'))->post(route('products.store'), [])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors(['name', 'category', 'price', 'stock_quantity']);

        $this->assertDatabaseCount('products', 0);
    }

    #[DataProvider('invalidProductData')]
    public function test_rejects_invalid_fields_without_persisting(string $field, mixed $value): void
    {
        $this->post(route('products.store'), array_replace($this->productData(), [$field => $value]))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('products', 0);
    }

    public static function invalidProductData(): array
    {
        return [
            'name length' => ['name', str_repeat('a', 256)],
            'name type' => ['name', ['invalid']],
            'category length' => ['category', str_repeat('a', 101)],
            'category type' => ['category', ['invalid']],
            'description type' => ['description', ['invalid']],
            'negative price' => ['price', '-0.01'],
            'nonnumeric price' => ['price', 'free'],
            'price precision' => ['price', '1.001'],
            'price overflow' => ['price', '10000000000.00'],
            'negative stock' => ['stock_quantity', -1],
            'fractional stock' => ['stock_quantity', 1.5],
            'nonnumeric stock' => ['stock_quantity', 'many'],
            'stock overflow' => ['stock_quantity', '4294967296'],
        ];
    }

    public function test_rejects_unsafe_and_oversized_uploads(): void
    {
        Storage::fake('public');

        foreach ([UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml'), $this->imageUpload()->size(2049)] as $image) {
            $this->post(route('products.store'), $this->productData() + ['image' => $image])
                ->assertSessionHasErrors('image');
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_updates_product_without_replacing_existing_image(): void
    {
        Storage::fake('public');
        $path = $this->imageUpload()->store('products', 'public');
        $product = Product::factory()->create(['image_path' => $path]);

        $this->put(route('products.update', $product), $this->productData())
            ->assertRedirect(route('products.show', $product))->assertSessionHas('success');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Gift box', 'image_path' => $path]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_replaces_image_and_removes_previous_file(): void
    {
        Storage::fake('public');
        $path = $this->imageUpload()->store('products', 'public');
        $product = Product::factory()->create(['image_path' => $path]);

        $this->put(route('products.update', $product), $this->productData() + ['image' => $this->imageUpload()])
            ->assertSessionHas('success');

        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($product->fresh()->image_path);
    }

    public function test_invalid_update_preserves_product_and_image(): void
    {
        Storage::fake('public');
        $path = $this->imageUpload()->store('products', 'public');
        $product = Product::factory()->create(['name' => 'Original', 'image_path' => $path]);

        $this->put(route('products.update', $product), array_replace($this->productData(), ['price' => -1]))
            ->assertSessionHasErrors('price');

        $this->assertSame('Original', $product->fresh()->name);
        Storage::disk('public')->assertExists($path);
    }

    public function test_deletes_product_and_its_image(): void
    {
        Storage::fake('public');
        $path = $this->imageUpload()->store('products', 'public');
        $product = Product::factory()->create(['image_path' => $path]);

        $this->delete(route('products.destroy', $product))->assertRedirect(route('products.index'))
            ->assertSessionHas('success', 'Product deleted successfully.');

        $this->assertModelMissing($product);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_deletes_product_without_an_image(): void
    {
        $product = Product::factory()->create();

        $this->delete(route('products.destroy', $product))->assertSessionHas('success');

        $this->assertModelMissing($product);
    }

    public function test_returns_404_for_missing_product_actions(): void
    {
        $this->get('/products/999')->assertNotFound();
        $this->get('/products/999/edit')->assertNotFound();
        $this->put('/products/999', $this->productData())->assertNotFound();
        $this->delete('/products/999')->assertNotFound();
    }

    public function test_escapes_product_text_and_search_input(): void
    {
        $unsafe = '<script>alert(1)</script>';
        $product = Product::factory()->create(['name' => $unsafe, 'category' => $unsafe, 'description' => $unsafe]);

        foreach ([route('products.index'), route('products.show', $product), route('products.edit', $product), route('products.index', ['search' => $unsafe])] as $url) {
            $this->get($url)->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
        }
    }

    public function test_search_treats_sql_injection_as_a_bound_value(): void
    {
        Product::factory()->create(['name' => 'Mug', 'category' => 'Home']);

        $this->get(route('products.index', ['search' => "' OR 1=1 --"]))->assertOk()
            ->assertViewHas('products', fn ($page) => $page->total() === 0);
    }

    public function test_failed_creation_removes_uploaded_file_and_preserves_input(): void
    {
        Storage::fake('public');
        Event::listen('eloquent.creating: '.Product::class, function (): void {
            throw new RuntimeException('Simulated database failure.');
        });

        try {
            $this->from(route('products.create'))->post(route('products.store'), $this->productData() + ['image' => $this->imageUpload()])
                ->assertRedirect(route('products.create'))->assertSessionHas('error')
                ->assertSessionHasInput('name', 'Gift box');
        } finally {
            Event::forget('eloquent.creating: '.Product::class);
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_failed_update_preserves_original_image_and_removes_new_upload(): void
    {
        Storage::fake('public');
        $path = $this->imageUpload()->store('products', 'public');
        $product = Product::factory()->create(['name' => 'Original', 'image_path' => $path]);
        Event::listen('eloquent.updating: '.Product::class, function (): void {
            throw new RuntimeException('Simulated database failure.');
        });

        try {
            $this->from(route('products.edit', $product))->put(route('products.update', $product), $this->productData() + ['image' => $this->imageUpload()])
                ->assertRedirect(route('products.edit', $product))->assertSessionHas('error');
        } finally {
            Event::forget('eloquent.updating: '.Product::class);
        }

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Original', 'image_path' => $path]);
        $this->assertSame([$path], Storage::disk('public')->allFiles());
    }

    public function test_failed_delete_preserves_product_and_image(): void
    {
        Storage::fake('public');
        $path = $this->imageUpload()->store('products', 'public');
        $product = Product::factory()->create(['image_path' => $path]);
        Event::listen('eloquent.deleting: '.Product::class, function (): void {
            throw new RuntimeException('Simulated database failure.');
        });

        try {
            $this->from(route('products.show', $product))->delete(route('products.destroy', $product))
                ->assertRedirect(route('products.show', $product))->assertSessionHas('error');
        } finally {
            Event::forget('eloquent.deleting: '.Product::class);
        }

        $this->assertModelExists($product);
        Storage::disk('public')->assertExists($path);
    }

    public function test_failed_image_upload_does_not_create_product(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::set('public', $disk);

        $this->from(route('products.create'))->post(route('products.store'), $this->productData() + ['image' => $this->imageUpload()])
            ->assertRedirect(route('products.create'))->assertSessionHas('error');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_failed_replacement_upload_keeps_original_product_and_image(): void
    {
        Storage::fake('public');
        $originalDisk = Storage::disk('public');
        $path = $this->imageUpload()->store('products', 'public');
        $product = Product::factory()->create(['name' => 'Original', 'image_path' => $path]);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::set('public', $disk);

        $this->from(route('products.edit', $product))->put(route('products.update', $product), $this->productData() + ['image' => $this->imageUpload()])
            ->assertRedirect(route('products.edit', $product))->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Original', 'image_path' => $path]);
        $originalDisk->assertExists($path);
    }

    public function test_reports_image_cleanup_failure_after_product_deletion(): void
    {
        $product = Product::factory()->create(['image_path' => 'products/old.png']);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->with('products/old.png')->andReturn(false);
        Storage::set('public', $disk);

        $this->delete(route('products.destroy', $product))->assertRedirect(route('products.index'))
            ->assertSessionHas('success')->assertSessionHas('error', 'The product was deleted, but its image could not be removed.');

        $this->assertModelMissing($product);
    }

    public function test_reports_old_image_cleanup_failure_after_successful_update(): void
    {
        Storage::fake('public');
        $originalDisk = Storage::disk('public');
        $product = Product::factory()->create(['image_path' => 'products/old.png']);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturnUsing(fn (...$arguments) => $originalDisk->putFileAs(...$arguments));
        $disk->shouldReceive('delete')->once()->with('products/old.png')->andThrow(new RuntimeException('Storage unavailable.'));
        Storage::set('public', $disk);

        $this->put(route('products.update', $product), $this->productData() + ['image' => $this->imageUpload()])
            ->assertRedirect(route('products.show', $product))->assertSessionHas('success')
            ->assertSessionHas('error', 'The product was saved, but its previous image could not be removed.');

        $this->assertSame('Gift box', $product->fresh()->name);
        $originalDisk->assertExists($product->fresh()->image_path);
    }

    public function test_displays_success_and_error_feedback(): void
    {
        $this->withSession(['success' => 'Product saved.', 'error' => 'Please try again.'])
            ->get(route('products.index'))->assertOk()->assertSee('Product saved.')->assertSee('Please try again.');
    }

    public function test_displays_validation_summary_and_field_error(): void
    {
        $errors = ['default' => ['format' => ':message', 'messages' => ['name' => ['The name field is required.']]]];

        $this->withSession(['errors' => $errors])->get(route('products.create'))
            ->assertOk()->assertSee('Please correct the following errors.')
            ->assertSee('The name field is required.')->assertSee('id="name-error"', false);
    }

    public function test_rejects_non_string_search_without_querying_products(): void
    {
        $this->from(route('products.index'))->get(route('products.index', ['search' => ['invalid']]))
            ->assertRedirect(route('products.index'))->assertSessionHasErrors('search');
    }

    private function productData(): array
    {
        return ['name' => 'Gift box', 'description' => 'A thoughtful gift.', 'category' => 'Keepsakes', 'price' => '19.95', 'stock_quantity' => 4];
    }

    private function imageUpload(): File
    {
        return UploadedFile::fake()->createWithContent('gift.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII='
        ));
    }
}
