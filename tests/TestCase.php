<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Tests;

use Bavix\LaravelClickHouse\ClickHouseServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ClickHouseServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.connections.bavix::clickhouse', [
            'host' => env('CLICKHOUSE_HOST', 'localhost'),
            'port' => env('CLICKHOUSE_PORT', '8123'),
            'driver' => env('CLICKHOUSE_DRIVER', 'bavix::clickhouse'),
            'database' => env('CLICKHOUSE_DATABASE', 'default'),
            'username' => env('CLICKHOUSE_USERNAME', 'default'),
            'password' => env('CLICKHOUSE_PASSWORD', ''),
        ]);
    }
}
