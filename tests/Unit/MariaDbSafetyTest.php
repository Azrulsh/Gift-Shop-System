<?php

namespace Tests\Unit;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\MariaDb\Safety;

class MariaDbSafetyTest extends TestCase
{
    #[DataProvider('unsafeTargets')]
    public function test_rejects_unsafe_targets_without_opening_a_connection(string $name, array $overrides): void
    {
        $config = array_replace(['driver' => 'mysql', 'database' => 'gift_testing', 'host' => '127.0.0.1', 'strict' => true], $overrides);
        $this->expectException(RuntimeException::class);
        Safety::assertTarget($name, $config);
    }

    public static function unsafeTargets(): array
    {
        return [
            'development database' => ['mysql', ['database' => 'gift']],
            'other database' => ['mysql', ['database' => 'other_testing']],
            'wrong connection' => ['sqlite', []],
            'wrong driver' => ['mysql', ['driver' => 'sqlite']],
            'remote server' => ['mysql', ['host' => 'example.test']],
            'URL override' => ['mysql', ['url' => 'mysql://redacted.invalid/gift']],
            'read override' => ['mysql', ['read' => ['database' => 'gift']]],
            'write override' => ['mysql', ['write' => ['database' => 'gift']]],
            'socket override' => ['mysql', ['unix_socket' => '/unverified/socket']],
            'prefix override' => ['mysql', ['prefix' => 'other.']],
            'strict mode disabled' => ['mysql', ['strict' => false]],
        ];
    }

    public function test_missing_verification_marker_refuses_writes(): void
    {
        $this->expectException(RuntimeException::class);
        Safety::assertWritesApproved(new Application);
    }
}
