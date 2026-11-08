<?php

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Validation\ValidationException;
use Tests\MariaDb\Safety;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    if (getenv('MARIADB_ALLOW_CONCURRENCY') !== '1') {
        throw new RuntimeException('Concurrency execution is disabled.');
    }
    $app = Safety::boot();
    Safety::assertWritesApproved($app);
    $database = $app['db']->connection('mysql');
    $database->statement('SET SESSION innodb_lock_wait_timeout = 5');
    echo "READY\n";
    fflush(STDOUT);
    $payload = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if ($payload['operation'] === 'create') {
        echo "START\n";
        fflush(STDOUT);
        try {
            $order = $app->make(OrderService::class)->create($payload['data']);
            $result = ['result' => 'created', 'order_id' => $order->id];
        } catch (ValidationException) {
            $result = ['result' => 'validation'];
        }
    } elseif ($payload['operation'] === 'cancel') {
        $order = Order::query()->findOrFail($payload['order_id']);
        echo json_encode(['result' => 'cancelling', 'connection_id' => (int) $database->selectOne('SELECT CONNECTION_ID() AS id')->id], JSON_THROW_ON_ERROR)."\n";
        fflush(STDOUT);
        $cancelled = $app->make(OrderService::class)->cancel($order, (int) $payload['lock_version']);
        $result = ['result' => 'cancelled', 'order_id' => $cancelled->id, 'status' => $cancelled->status->value, 'lock_version' => $cancelled->lock_version, 'cancelled_at' => $cancelled->getRawOriginal('cancelled_at')];
    } elseif ($payload['operation'] === 'deadlock') {
        $attempts = 0;
        $database->transaction(function () use ($database, $payload, &$attempts): void {
            $attempts++;
            $database->table('products')->where('id', $payload['first'])->lockForUpdate()->first();
            if ($attempts === 1) {
                echo "LOCKED\n";
                fflush(STDOUT);
                if (trim(fgets(STDIN)) !== 'CONTINUE') {
                    throw new RuntimeException('Missing synchronization signal.');
                }
            }
            $database->table('products')->where('id', $payload['second'])->lockForUpdate()->first();
            $database->table('products')->whereIn('id', [$payload['first'], $payload['second']])->increment('stock_quantity');
        }, attempts: 3);
        $result = ['result' => 'committed', 'attempts' => $attempts];
    } else {
        throw new RuntimeException('Unknown worker operation.');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    // Never forward database exceptions or environment values to the parent.
    echo "{\"result\":\"worker_error\"}\n";
    exit(1);
}
