<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Tests\Unit\Database;

use Bavix\LaravelClickHouse\Database\Connection;
use Bavix\LaravelClickHouse\Database\Query\Builder;
use Bavix\LaravelClickHouse\Tests\TestCase;
use Tinderbox\Clickhouse\Exceptions\ClientException;

class ConnectionTest extends TestCase
{
    /**
     * @var Connection
     */
    protected $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = $this->getConnection('bavix::clickhouse');
    }

    public function testQuery(): void
    {
        self::assertInstanceOf(Builder::class, $this->connection->query());
    }

    /**
     * @throws ClientException
     */
    public function testSystemEvents(): void
    {
        self::assertIsNumeric($this->connection->query()->table('system.events')->count());
    }

    /**
     * @throws ClientException
     */
    public function testMyDatabase(): void
    {
        $result = $this->connection->statement('CREATE DATABASE IF NOT EXISTS tests');
        self::assertTrue($result);

        $result = $this->connection->statement('CREATE TABLE IF NOT EXISTS tests.dt
(
    `timestamp` DateTime(\'Europe/Moscow\'),
    `event_id` UInt8
)
ENGINE = TinyLog;');

        self::assertTrue($result);

        $result = $this->connection->statement('CREATE DATABASE IF NOT EXISTS tests');
        self::assertTrue($result);

        $result = $this->connection->statement('TRUNCATE tests.dt');
        self::assertTrue($result);

        $values = [
            [
                'timestamp' => '2019-01-01 00:00:00',
                'event_id' => 1,
            ],
            [
                'event_id' => 2,
                'timestamp' => '2020-01-01 00:00:00',
            ],
            [
                'event_id' => 3,
                'timestamp' => 1546300800,
            ],
        ];
        $this->connection->query()
            ->table('tests.dt')
            ->insert($values);

        self::assertEquals(3, $this->connection->query()->table('tests.dt')->count());

        $result = $this->connection->statement('DROP TABLE IF EXISTS tests.dt');
        self::assertTrue($result);

        $result = $this->connection->statement('DROP DATABASE IF EXISTS tests');
        self::assertTrue($result);
    }
}
