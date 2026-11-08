<?php

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ProductSupplierMigrationTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Migration tests must use isolated in-memory SQLite.');
        }

        return $app;
    }

    public function test_additive_migration_preserves_preexisting_product_fields_and_leaves_supplier_null(): void
    {
        $products = require database_path('migrations/2026_11_07_171658_create_products_table.php');
        $suppliers = require database_path('migrations/2026_11_08_004930_create_suppliers_table.php');
        $products->up();
        $suppliers->up();
        DB::table('products')->insert(['id' => 7, 'name' => 'Existing gift', 'description' => 'Keep this description', 'category' => 'Gifts', 'price' => '19.95', 'stock_quantity' => 12, 'image_path' => 'products/existing.png', 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-02 12:00:00']);
        $before = (array) DB::table('products')->where('id', 7)->first();
        $migration = require database_path('migrations/2026_11_08_015827_add_supplier_id_to_products_table.php');

        $migration->up();

        $after = (array) DB::table('products')->where('id', 7)->first();
        $this->assertNull($after['supplier_id']);
        unset($after['supplier_id']);
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('products', 1);
    }
}
