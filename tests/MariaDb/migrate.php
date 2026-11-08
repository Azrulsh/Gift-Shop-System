<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\MariaDb\Safety;

require dirname(__DIR__, 2).'/vendor/autoload.php';

putenv('MARIADB_INTEGRATION=1');

try {
    $app = Safety::boot();
    Safety::assertWritesApproved($app);
    $count = $app['db']->connection('mysql')->selectOne('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE()');
    if ((int) $count->total !== 0) {
        throw new RuntimeException('The integration database is not empty; automatic resets are forbidden.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Migration safety verification refused execution. Credentials withheld. No migration started.\n");
    exit(1);
}

// Deliberately additive only: no fresh, wipe, seed, or rollback commands.
exit($app->make(Kernel::class)->call('migrate', ['--database' => 'mysql', '--force' => true, '--no-interaction' => true]));
