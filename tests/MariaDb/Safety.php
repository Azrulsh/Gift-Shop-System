<?php

namespace Tests\MariaDb;

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use PDO;
use RuntimeException;
use Throwable;

final class Safety
{
    public static function boot(): Application
    {
        $root = dirname(__DIR__, 2);
        if (getenv('MARIADB_INTEGRATION') !== '1') {
            throw new RuntimeException('Select phpunit.mariadb.xml explicitly, or use the dedicated preflight command.');
        }
        if (is_file($root.'/bootstrap/cache/config.php') || getenv('APP_CONFIG_CACHE')) {
            throw new RuntimeException('MariaDB integration refuses cached or redirected application configuration.');
        }
        if (! is_file($root.'/.env.testing')) {
            throw new RuntimeException('The dedicated .env.testing file is required.');
        }
        try {
            $values = Dotenv::parse(file_get_contents($root.'/.env.testing'));
        } catch (Throwable) {
            throw new RuntimeException('Unable to parse the testing environment. No connection was opened.');
        }
        foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'gift_testing', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $key => $expected) {
            if (($values[$key] ?? null) !== $expected) {
                throw new RuntimeException('The dedicated testing environment failed its safety checks.');
            }
        }
        foreach ($values as $key => $value) {
            if (str_starts_with($key, 'DB_') || str_starts_with($key, 'APP_') || in_array($key, ['CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'MAIL_MAILER', 'BROADCAST_CONNECTION'], true)) {
                foreach ([getenv($key), $_ENV[$key] ?? false, $_SERVER[$key] ?? false] as $existing) {
                    if ($existing !== false && (string) $existing !== $value) {
                        throw new RuntimeException('Conflicting process environment for '.$key.'. No connection was opened.');
                    }
                }
                putenv($key.'='.$value);
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }

        try {
            $app = require $root.'/bootstrap/app.php';
            $app->loadEnvironmentFrom('.env.testing');
            $app->make(Kernel::class)->bootstrap();
            if ($app->configurationIsCached() || $app->environment() !== 'testing') {
                throw new RuntimeException('Unverified application environment.');
            }
            $connection = $app['config']->get('database.connections.mysql', []);
            self::assertTarget($app['config']->get('database.default'), $connection);
            foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'username' => 'DB_USERNAME', 'password' => 'DB_PASSWORD'] as $attribute => $variable) {
                if (! array_key_exists($variable, $values) || (string) ($connection[$attribute] ?? '') !== $values[$variable]) {
                    throw new RuntimeException('Connection differs from the dedicated environment.');
                }
            }
            $app['config']->set('database.connections', ['mysql' => $connection]);
            $database = $app['db']->connection('mysql');
            if ($database->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                throw new RuntimeException('Unexpected PDO driver.');
            }
            $server = $database->selectOne('SELECT DATABASE() AS database_name, VERSION() AS server_version, @@port AS server_port, @@foreign_key_checks AS foreign_keys');
            if ($server->database_name !== 'gift_testing' || stripos($server->server_version, 'MariaDB') === false || (int) $server->foreign_keys !== 1) {
                throw new RuntimeException('Server identity or foreign-key enforcement was not verified.');
            }
            $app->instance('mariadb.verified', [
                'connection' => 'mysql', 'driver' => 'mysql', 'database' => $server->database_name,
                'host' => $connection['host'], 'port' => $server->server_port,
                'server_version' => $server->server_version, 'foreign_keys' => true,
            ]);

            return $app;
        } catch (Throwable) {
            throw new RuntimeException('MariaDB verification failed. No migrations or test writes were started. Connection details were withheld.');
        }
    }

    public static function assertTarget(mixed $name, array $connection): void
    {
        if ($name !== 'mysql' || ($connection['driver'] ?? null) !== 'mysql'
            || ($connection['database'] ?? null) !== 'gift_testing'
            || ! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || ! empty($connection['url']) || isset($connection['read']) || isset($connection['write'])
            || ! empty($connection['unix_socket']) || ! empty($connection['prefix'])
            || ($connection['strict'] ?? false) !== true) {
            throw new RuntimeException('Refusing an unverified MariaDB test connection.');
        }
    }

    public static function assertWritesApproved(Application $app): void
    {
        if (! $app->bound('mariadb.verified') || getenv('MARIADB_ALLOW_WRITES') !== '1') {
            throw new RuntimeException('MariaDB writes are disabled. Obtain approval before explicitly setting MARIADB_ALLOW_WRITES=1.');
        }
        self::assertTarget($app['config']->get('database.default'), $app['config']->get('database.connections.mysql', []));
        if ($app['db']->connection('mysql')->selectOne('SELECT DATABASE() AS name')->name !== 'gift_testing') {
            throw new RuntimeException('The selected database changed; refusing writes.');
        }
    }
}
