<?php

namespace Tests\MariaDb;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

abstract class MariaDbTestCase extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = Safety::boot();
        Safety::assertWritesApproved($app);
        $schema = $app['db']->connection('mysql')->getSchemaBuilder();
        foreach (['products', 'customers', 'suppliers', 'orders', 'order_items'] as $table) {
            if (! $schema->hasTable($table)) {
                throw new RuntimeException('Integration schema missing. Obtain approval and use the guarded migration entry point first.');
            }
        }
        if (! $schema->hasColumn('products', 'supplier_id')) {
            throw new RuntimeException('The supplier integration migration has not been applied.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-11-08 12:00:00');
    }

    protected function customer(): Customer
    {
        return Customer::create(['name' => 'Integration customer', 'email' => 'integration@example.test', 'phone' => '0123456789', 'address' => 'Test address']);
    }

    protected function product(string $name = 'Integration gift', string $price = '12.35', int $stock = 10): Product
    {
        return Product::create(['name' => $name, 'description' => 'Integration fixture', 'category' => 'Testing', 'price' => $price, 'stock_quantity' => $stock]);
    }

    protected function orderData(Customer $customer, array $items, string $token = '11111111-1111-4111-8111-111111111111'): array
    {
        return ['customer_id' => $customer->id, 'creation_token' => $token, 'order_date' => '2026-11-08', 'status' => 'pending', 'payment_status' => 'unpaid', 'items' => $items];
    }
}
