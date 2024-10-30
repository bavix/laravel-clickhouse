<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Tests\Unit\Database\Query;

use Bavix\LaravelClickHouse\Database\Connection;
use Bavix\LaravelClickHouse\Database\Query\Builder;
use Bavix\LaravelClickHouse\Tests\Helpers;
use Illuminate\Support\Collection;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Tinderbox\ClickhouseBuilder\Query\Enums\Format;
use Tinderbox\ClickhouseBuilder\Query\Grammar;

/**
 * @property \Mockery\MockInterface|Connection connection
 * @property Builder builder
 */
class BuilderTest extends TestCase
{
    use Helpers;

    /**
     * @var MockInterface&Connection
     */
    private MockInterface $connection;

    private Builder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->mock(Connection::class);
        $this->builder = new Builder($this->connection, new Grammar());
        $this->builder->from($this->faker()->word());
    }

    public function testGet(): void
    {
        $connectionResult = $this->faker()
            ->shuffle(range(1, 5));

        $this->connection
            ->shouldReceive('select')
            ->andReturn($connectionResult);

        $builderResult = $this->builder->get();

        self::assertInstanceOf(Collection::class, $builderResult);
        self::assertSame($connectionResult, $builderResult->toArray());
    }

    public function testCount(): void
    {
        $connectionResult = range(1, 5);

        $this->connection
            ->shouldReceive('select')
            ->andReturn([[
                'count' => count($connectionResult),
            ]]);

        $builderResult = $this->builder->count();

        self::assertCount($builderResult, $connectionResult);
    }

    public function testFirst(): void
    {
        $connectionResult = $this->faker()
            ->shuffle(range(1, 5));

        $this->connection
            ->shouldReceive('select')
            ->andReturn($connectionResult);

        $builderResult = $this->builder->first();

        self::assertSame($connectionResult[0], $builderResult);
    }

    public function testNewQuery(): void
    {
        self::assertInstanceOf(Builder::class, $this->builder->newQuery());
    }

    public function testInsertFiles(): void
    {
        $this->connection
            ->shouldReceive('insertFiles')
            ->andReturn([]);

        $builderResult = $this->builder->insertFiles(['column_1', 'column_2'], []);
        self::assertSame([], $builderResult);
    }

    public function testInsert(): void
    {
        self::assertFalse($this->builder->insert([]));

        $insertedRow = [
            $this->faker()
                ->word() => $this->faker()
                ->randomDigit(),
            $this->faker()
                ->randomLetter() => $this->faker()
                ->randomDigit(),
            $this->faker()
                ->numerify('column_#') => $this->faker()
                ->randomLetter(),
        ];

        \ksort($insertedRow);
        $inserted = [$insertedRow];
        $generatedSql = sprintf(
            'INSERT INTO `%s` (%s) FORMAT %s (%s)',
            $this->builder->getFrom()
                ->getTable(),
            collect($insertedRow)
                ->keys()
                ->map(function (string $columnName) {
                    return sprintf('`%s`', $columnName);
                })
                ->implode(', '),
            Format::VALUES,
            collect($insertedRow)
                ->values()
                ->map(function ($value) {
                    if (is_numeric($value)) {
                        return $value;
                    }

                    return sprintf('\'%s\'', $value);
                })
                ->implode(', ')
        );

        $values = collect($insertedRow)
            ->values()
            ->toArray();
        $this->connection
            ->shouldReceive('insert')
            ->withArgs([$generatedSql, $values])
            ->andReturn(true);

        self::assertTrue($this->builder->insert($inserted));
    }
}
