<?php

use Tests\MariaDb\Safety;

require dirname(__DIR__, 2).'/vendor/autoload.php';

putenv('MARIADB_INTEGRATION=1');

try {
    $app = Safety::boot();
    $count = $app['db']->connection('mysql')->selectOne('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE()');
    echo json_encode($app->make('mariadb.verified') + ['table_count' => (int) $count->total, 'writes_enabled' => getenv('MARIADB_ALLOW_WRITES') === '1'], JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "MariaDB preflight refused the configuration or could not verify the connection. Credentials withheld. No migrations or test writes ran.\n");
    exit(1);
}
