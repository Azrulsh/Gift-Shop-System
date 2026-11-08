<?php

namespace Tests\MariaDb\Concurrency;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MariaDb\Safety;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    private array $workers = [];

    private array $customers = [];

    private array $products = [];

    private const TOKENS = ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];

    public function createApplication(): Application
    {
        $app = Safety::boot();
        Safety::assertWritesApproved($app);
        if (getenv('MARIADB_ALLOW_CONCURRENCY') !== '1' || ! function_exists('proc_open')) {
            throw new RuntimeException('Concurrency requires separate approval, MARIADB_ALLOW_CONCURRENCY=1, and proc_open.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['orders', 'order_items', 'customers', 'products', 'suppliers'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Concurrency fixtures require empty integration tables. No automatic reset is allowed.');
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as [$process, $pipes]) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
        if ($this->app && $this->app->bound('mariadb.verified')) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->customers !== []) {
                Safety::assertWritesApproved($this->app);
                $orders = Order::whereIn('customer_id', $this->customers)->whereIn('creation_token', self::TOKENS)->get();
                foreach ($orders as $order) {
                    $order->items()->delete();
                    $order->delete();
                }
                Product::whereIn('id', $this->products)->delete();
                Customer::whereIn('id', $this->customers)->forceDelete();
            }
        }
        parent::tearDown();
    }

    public function test_concurrent_orders_cannot_oversell_stock(): void
    {
        $product = $this->product();
        $first = $this->data($this->customer(1), $product, self::TOKENS[0]);
        $second = $this->data($this->customer(2), $product, self::TOKENS[1]);
        $results = $this->race($product, $first, $second);
        $states = array_column($results, 'result');
        sort($states);
        $this->assertSame(['created', 'validation'], $states);
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_concurrent_duplicate_submission_deducts_stock_once(): void
    {
        $product = $this->product();
        $data = $this->data($this->customer(1), $product, self::TOKENS[0]);
        $results = $this->race($product, $data, $data);
        $this->assertSame(['created', 'created'], array_column($results, 'result'));
        $this->assertSame($results[0]['order_id'], $results[1]['order_id']);
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_real_deadlock_victim_is_retried_without_partial_inventory_changes(): void
    {
        // The test harness deliberately inverts locks; OrderService retains its consistent ordering.
        $this->customer(1);
        $first = $this->product();
        $second = $this->product();
        $a = $this->worker();
        $b = $this->worker();
        $this->send($a, ['operation' => 'deadlock', 'first' => $first->id, 'second' => $second->id]);
        $this->send($b, ['operation' => 'deadlock', 'first' => $second->id, 'second' => $first->id]);
        $this->assertSame('LOCKED', $this->read($a));
        $this->assertSame('LOCKED', $this->read($b));
        foreach ([$a, $b] as $worker) {
            fwrite($this->workers[$worker][1][0], "CONTINUE\n");
            fflush($this->workers[$worker][1][0]);
        }
        $results = [json_decode($this->read($a), true, flags: JSON_THROW_ON_ERROR), json_decode($this->read($b), true, flags: JSON_THROW_ON_ERROR)];
        $this->assertSame(['committed', 'committed'], array_column($results, 'result'));
        $this->assertGreaterThanOrEqual(3, array_sum(array_column($results, 'attempts')));
        $this->assertSame(7, $first->fresh()->stock_quantity);
        $this->assertSame(7, $second->fresh()->stock_quantity);
    }

    public function test_concurrent_cancellation_restores_reserved_stock_exactly_once(): void
    {
        $product = $this->product();
        $service = app(OrderService::class);
        $order = $service->create($this->data($this->customer(1), $product, self::TOKENS[0]));
        $originalVersion = $order->lock_version;
        $unchangedFields = array_diff_key($order->getRawOriginal(), array_flip(['status', 'cancelled_at', 'lock_version', 'updated_at']));
        $originalItems = $order->items()->get()->map->getRawOriginal()->all();
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertSame('unpaid', $order->payment_status->value);

        $a = $this->worker();
        $b = $this->worker();
        $parentConnection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        DB::beginTransaction();
        try {
            DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();
            foreach ([$a, $b] as $worker) {
                $this->send($worker, ['operation' => 'cancel', 'order_id' => $order->id, 'lock_version' => $originalVersion]);
            }
            $connections = [];
            foreach ([$a, $b] as $worker) {
                $started = json_decode($this->read($worker), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('cancelling', $started['result']);
                $connections[] = (int) $started['connection_id'];
            }
            $this->assertCount(2, array_unique($connections));
            $this->assertNotContains($parentConnection, $connections);
            $this->waitForCancellationLocks($connections, $parentConnection, $order->id);
            $this->assertSame(1, $product->fresh()->stock_quantity);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $results = [json_decode($this->read($a), true, flags: JSON_THROW_ON_ERROR), json_decode($this->read($b), true, flags: JSON_THROW_ON_ERROR)];
        $cancelled = $order->fresh();
        foreach ($results as $result) {
            $this->assertSame('cancelled', $result['result']);
            $this->assertSame($order->id, $result['order_id']);
            $this->assertSame('cancelled', $result['status']);
            $this->assertSame($originalVersion + 1, $result['lock_version']);
            $this->assertSame($cancelled->getRawOriginal('cancelled_at'), $result['cancelled_at']);
        }
        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame($originalVersion + 1, $cancelled->lock_version);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame($unchangedFields, array_intersect_key($cancelled->getRawOriginal(), $unchangedFields));
        $this->assertSame($originalItems, $cancelled->items()->get()->map->getRawOriginal()->all());

        $beforeRepeat = $cancelled->getRawOriginal();
        $repeated = $service->cancel($order, $originalVersion);
        $this->assertSame($beforeRepeat, $repeated->getRawOriginal());
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame($originalItems, $repeated->items()->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    /** @param list<int> $connections */
    private function waitForCancellationLocks(array $connections, int $parentConnection, int $orderId): void
    {
        $deadline = hrtime(true) + 3_000_000_000;
        do {
            try {
                $waiting = DB::select(
                    'SELECT DISTINCT requesting.trx_mysql_thread_id AS connection_id
                     FROM information_schema.INNODB_LOCK_WAITS AS waits
                     JOIN information_schema.INNODB_TRX AS requesting ON requesting.trx_id = waits.requesting_trx_id
                     JOIN information_schema.INNODB_TRX AS blocking ON blocking.trx_id = waits.blocking_trx_id
                     JOIN information_schema.INNODB_LOCKS AS requested ON requested.lock_id = waits.requested_lock_id
                     WHERE requesting.trx_mysql_thread_id IN (?, ?)
                     AND blocking.trx_mysql_thread_id = ? AND requested.lock_table = ?
                     AND requested.lock_index = ? AND requested.lock_type = ? AND requested.lock_data = ?',
                    [$connections[0], $connections[1], $parentConnection, '`gift_testing`.`orders`', 'PRIMARY', 'RECORD', (string) $orderId],
                );
            } catch (QueryException) {
                throw new RuntimeException('Cannot inspect MariaDB row-lock metadata. Required metadata access must be granted before this test can verify contention.');
            }
            $observed = array_map(fn ($row): int => (int) $row->connection_id, $waiting);
            if (count(array_intersect($connections, $observed)) === 2) {
                $this->assertEqualsCanonicalizing($connections, $observed);

                return;
            }
            usleep(20_000);
        } while (hrtime(true) < $deadline);

        $this->fail('Both cancellation workers did not demonstrably wait on the parent order-row lock within three seconds.');
    }

    private function race(Product $product, array $first, array $second): array
    {
        DB::beginTransaction();
        DB::table('products')->where('id', $product->id)->lockForUpdate()->first();
        $a = $this->worker();
        $b = $this->worker();
        $this->send($a, ['operation' => 'create', 'data' => $first]);
        $this->send($b, ['operation' => 'create', 'data' => $second]);
        $this->assertSame('START', $this->read($a));
        $this->assertSame('START', $this->read($b));
        DB::commit();

        return [json_decode($this->read($a), true, flags: JSON_THROW_ON_ERROR), json_decode($this->read($b), true, flags: JSON_THROW_ON_ERROR)];
    }

    private function worker(): int
    {
        $process = proc_open([PHP_BINARY, base_path('tests/MariaDb/worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to launch a guarded concurrency worker.');
        }
        stream_set_timeout($pipes[1], 15);
        $index = count($this->workers);
        $this->workers[] = [$process, $pipes];
        $this->assertSame('READY', $this->read($index));

        return $index;
    }

    private function send(int $worker, array $payload): void
    {
        fwrite($this->workers[$worker][1][0], json_encode($payload, JSON_THROW_ON_ERROR)."\n");
        fflush($this->workers[$worker][1][0]);
    }

    private function read(int $worker): string
    {
        $line = fgets($this->workers[$worker][1][1]);
        if ($line === false) {
            throw new RuntimeException('Worker failed or timed out. Diagnostic output withheld to protect credentials.');
        }

        return trim($line);
    }

    private function customer(int $number): Customer
    {
        $customer = Customer::create(['name' => 'Concurrent customer '.$number, 'email' => 'concurrent'.$number.'@example.test', 'phone' => '0123456789']);
        $this->customers[] = $customer->id;

        return $customer;
    }

    private function product(): Product
    {
        $product = Product::create(['name' => 'Concurrent gift', 'category' => 'Testing', 'price' => '0.10', 'stock_quantity' => 5]);
        $this->products[] = $product->id;

        return $product;
    }

    private function data(Customer $customer, Product $product, string $token): array
    {
        return ['customer_id' => $customer->id, 'creation_token' => $token, 'order_date' => '2026-11-08', 'status' => 'pending', 'payment_status' => 'unpaid', 'items' => [['product_id' => $product->id, 'quantity' => 4]]];
    }
}
