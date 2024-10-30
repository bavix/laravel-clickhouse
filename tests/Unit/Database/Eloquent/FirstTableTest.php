<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Tests\Unit\Database\Eloquent;

use Bavix\LaravelClickHouse\Database\Connection;
use Bavix\LaravelClickHouse\Tests\FirstTableEntry;
use Bavix\LaravelClickHouse\Tests\TestCase;

class FirstTableTest extends TestCase
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

    public function testPaginate(): void
    {
        $this->connection->statement('DROP TABLE IF EXISTS my_first_table');

        $result = $this->connection->statement('CREATE TABLE my_first_table
(
    user_id UInt32,
    message String,
    timestamp DateTime,
    metric Float32
)
ENGINE = MergeTree()
PRIMARY KEY (user_id, timestamp)');
        self::assertTrue($result);

        self::assertTrue(FirstTableEntry::query()->insert([
            'user_id' => 1,
            'message' => 'hello world',
            'timestamp' => new \DateTime(),
            'metric' => 42,
        ]));

        self::assertCount(1, FirstTableEntry::query()->paginate()->items());
    }
}
