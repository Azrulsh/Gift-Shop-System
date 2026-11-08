<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductSupplierTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Product–Supplier tests must use isolated in-memory SQLite.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_product_can_be_created_with_active_supplier(): void
    {
        $supplier = Supplier::factory()->create();

        $this->post(route('products.store'), $this->productData() + ['supplier_id' => $supplier->id])->assertSessionHasNoErrors()->assertSessionHas('success');

        $product = Product::sole();
        $this->assertSame($supplier->id, $product->supplier->id);
        $this->assertSame($product->id, $supplier->products()->sole()->id);
    }

    public function test_product_can_be_created_unassigned(): void
    {
        $this->post(route('products.store'), $this->productData())->assertSessionHas('success');

        $this->assertNull(Product::sole()->supplier_id);
        $this->get(route('products.show', Product::sole()))->assertOk()->assertSee('Unassigned');
    }

    #[DataProvider('invalidSelections')]
    public function test_rejects_invalid_new_assignments(string $state): void
    {
        $id = match ($state) {
            'inactive' => Supplier::factory()->inactive()->create()->id,
            'deleted' => $this->deletedSupplier()->id,
            'array' => ['invalid'],
            'text' => 'invalid',
            default => 999,
        };

        $this->post(route('products.store'), $this->productData() + ['supplier_id' => $id])->assertSessionHasErrors('supplier_id');

        $this->assertDatabaseCount('products', 0);
    }

    public static function invalidSelections(): array
    {
        return ['inactive' => ['inactive'], 'deleted' => ['deleted'], 'missing' => ['missing'], 'array' => ['array'], 'text' => ['text']];
    }

    public function test_create_dropdown_contains_only_active_suppliers(): void
    {
        $active = Supplier::factory()->create(['name' => 'Eligible supplier']);
        Supplier::factory()->inactive()->create(['name' => 'Unavailable warehouse']);
        $this->deletedSupplier('Archived warehouse');

        $this->get(route('products.create'))->assertOk()->assertSee('Eligible supplier')->assertSee('Unassigned')
            ->assertDontSee('Unavailable warehouse')->assertDontSee('Archived warehouse')
            ->assertViewHas('suppliers', fn ($suppliers) => $suppliers->modelKeys() === [$active->id]);
    }

    public function test_reassignment_and_explicit_unassignment_update_associations(): void
    {
        $first = Supplier::factory()->create();
        $second = Supplier::factory()->create();
        $product = Product::factory()->for($first)->create(['stock_quantity' => 12]);

        $this->put(route('products.update', $product), $this->updateData($product) + ['supplier_id' => $second->id])->assertSessionHas('success');

        $this->assertSame($second->id, $product->fresh()->supplier_id);
        $this->assertSame(0, $first->products()->count());
        $this->put(route('products.update', $product), $this->updateData($product) + ['supplier_id' => ''])->assertSessionHas('success');
        $this->assertNull($product->fresh()->supplier_id);
        $this->assertSame(12, $product->fresh()->stock_quantity);
    }

    #[DataProvider('legacyStates')]
    public function test_omitted_and_unchanged_legacy_assignments_are_preserved(bool $deleted): void
    {
        $supplier = Supplier::factory()->inactive()->create();
        $product = Product::factory()->for($supplier)->create(['stock_quantity' => 12]);
        if ($deleted) {
            $supplier->delete();
        }
        Supplier::factory()->inactive()->create(['name' => 'Other ineligible supplier']);

        $this->get(route('products.edit', $product))->assertOk()->assertSee($supplier->name)->assertSee('current assignment')
            ->assertDontSee('Other ineligible supplier')->assertViewHas('suppliers', fn ($suppliers) => $suppliers->contains('id', $supplier->id));
        $this->put(route('products.update', $product), $this->updateData($product))->assertSessionHas('success');
        $this->assertSame($supplier->id, $product->fresh()->supplier_id);
        $this->put(route('products.update', $product), $this->updateData($product) + ['supplier_id' => (string) $supplier->id])->assertSessionHas('success');
        $this->assertSame($supplier->id, $product->fresh()->supplier_id);
        $this->assertSame($supplier->id, $product->fresh()->supplier->id);
    }

    public static function legacyStates(): array
    {
        return ['inactive' => [false], 'deleted' => [true]];
    }

    #[DataProvider('legacyStates')]
    public function test_cannot_reassign_to_another_ineligible_supplier(bool $deleted): void
    {
        $current = Supplier::factory()->create();
        $target = Supplier::factory()->inactive()->create();
        if ($deleted) {
            $target->delete();
        }
        $product = Product::factory()->for($current)->create(['stock_quantity' => 12]);

        $this->put(route('products.update', $product), $this->updateData($product) + ['supplier_id' => $target->id, 'original_supplier_id' => $target->id])->assertSessionHasErrors('supplier_id');

        $this->assertSame($current->id, $product->fresh()->supplier_id);
    }

    public function test_removed_legacy_assignment_cannot_be_selected_again(): void
    {
        $supplier = Supplier::factory()->inactive()->create();
        $product = Product::factory()->for($supplier)->create(['stock_quantity' => 12]);
        $this->put(route('products.update', $product), $this->updateData($product) + ['supplier_id' => null])->assertSessionHas('success');
        $product->refresh();

        $this->put(route('products.update', $product), $this->updateData($product) + ['supplier_id' => $supplier->id])->assertSessionHasErrors('supplier_id');

        $this->assertNull($product->fresh()->supplier_id);
    }

    public function test_product_listing_and_detail_display_supplier_statuses_without_n_plus_one(): void
    {
        $active = Supplier::factory()->create(['name' => 'Active partner']);
        $inactive = Supplier::factory()->inactive()->create(['name' => 'Inactive partner']);
        $deleted = $this->deletedSupplier('Deleted partner');
        Product::factory()->for($active)->count(5)->create();
        Product::factory()->for($inactive)->count(5)->create();
        $product = Product::factory()->for($deleted)->create();
        Product::factory()->create();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get(route('products.index'))->assertOk()->assertSee('Active partner')->assertSee('Inactive partner')->assertSee('Deleted partner')
            ->assertSee('Active')->assertSee('Inactive')->assertSee('Deleted')->assertSee('Unassigned');

        $this->assertLessThanOrEqual(4, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->get(route('products.show', $product))->assertOk()->assertSee('Deleted partner')->assertSee('Deleted')
            ->assertSee(route('suppliers.show', $deleted));
    }

    public function test_supplier_details_paginate_only_associated_products_and_count_all_pages(): void
    {
        $supplier = Supplier::factory()->create();
        $products = Product::factory()->for($supplier)->count(16)->sequence(fn ($sequence) => ['name' => 'Assigned '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();
        Product::factory()->create(['name' => 'Unrelated product']);

        $this->get(route('suppliers.show', $supplier))->assertOk()->assertSee('Associated products (16)')->assertSee($products->last()->name)->assertDontSee($products->first()->name)
            ->assertDontSee('Unrelated product')->assertViewHas('products', fn ($page) => $page->total() === 16 && $page->count() === 15);
        $this->get(route('suppliers.show', [$supplier, 'page' => 2]))->assertOk()->assertSee($products->first()->name);
    }

    public function test_supplier_soft_deletion_preserves_assignments_stock_and_read_only_visibility(): void
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->for($supplier)->create(['stock_quantity' => 12]);

        $this->delete(route('suppliers.destroy', $supplier))->assertSessionHas('success');

        $this->assertSame($supplier->id, $product->fresh()->supplier_id);
        $this->assertSame(12, $product->fresh()->stock_quantity);
        $this->get(route('suppliers.show', $supplier))->assertOk()->assertSee('Read-only profile.')->assertSee($product->name)
            ->assertDontSee('Edit supplier')->assertDontSee('Confirm deletion');
        $this->get(route('suppliers.edit', $supplier))->assertNotFound();
        $this->put(route('suppliers.update', $supplier), [])->assertNotFound();
        $this->delete(route('suppliers.destroy', $supplier))->assertNotFound();
    }

    public function test_physical_deletion_of_linked_supplier_is_restricted(): void
    {
        $supplier = Supplier::factory()->create();
        Product::factory()->for($supplier)->create();
        $supplier->delete();

        $this->expectException(QueryException::class);

        $supplier->forceDelete();
    }

    #[DataProvider('eligibilityRaces')]
    public function test_eligibility_is_rechecked_after_validation(string $operation, bool $deleted): void
    {
        $supplier = Supplier::factory()->create();
        $product = $operation === 'update' ? Product::factory()->create(['stock_quantity' => 12]) : null;
        $changed = false;
        DB::listen(function ($query) use ($supplier, $deleted, &$changed): void {
            if (! $changed && str_contains($query->sql, 'suppliers') && str_contains($query->sql, 'aggregate')) {
                $changed = true;
                if ($deleted) {
                    $supplier->delete();
                } else {
                    $supplier->update(['status' => 'inactive']);
                }
            }
        });

        $response = $operation === 'update'
            ? $this->put(route('products.update', $product), $this->updateData($product) + ['supplier_id' => $supplier->id])
            : $this->post(route('products.store'), $this->productData() + ['supplier_id' => $supplier->id]);

        $this->assertTrue($changed);
        $response->assertSessionHasErrors(['supplier_id' => 'This supplier is no longer active or available. Choose another supplier.']);
        $this->assertDatabaseCount('products', $operation === 'update' ? 1 : 0);
        if ($product) {
            $this->assertNull($product->fresh()->supplier_id);
            $this->assertSame(12, $product->fresh()->stock_quantity);
        }
    }

    public static function eligibilityRaces(): array
    {
        return ['create inactive' => ['create', false], 'create deleted' => ['create', true], 'update inactive' => ['update', false], 'update deleted' => ['update', true]];
    }

    public function test_supplier_labels_escape_html(): void
    {
        $supplier = Supplier::factory()->create(['name' => '<script>alert(1)</script>']);
        $product = Product::factory()->for($supplier)->create();

        $this->get(route('products.index'))->assertOk()->assertSee($supplier->name)->assertDontSee($supplier->name, false);
        $this->get(route('products.show', $product))->assertOk()->assertSee($supplier->name)->assertDontSee($supplier->name, false);
    }

    private function productData(): array
    {
        return ['name' => 'Gift box', 'description' => 'A gift', 'category' => 'Gifts', 'price' => '19.95', 'stock_quantity' => 12];
    }

    private function updateData(Product $product): array
    {
        return $this->productData() + ['original_stock_quantity' => $product->stock_quantity];
    }

    private function deletedSupplier(string $name = 'Deleted supplier'): Supplier
    {
        $supplier = Supplier::factory()->create(['name' => $name]);
        $supplier->delete();

        return $supplier;
    }
}
