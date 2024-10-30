<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Tests\Unit\Database\Eloquent;

use Bavix\LaravelClickHouse\Database\Connection;
use Bavix\LaravelClickHouse\Database\Eloquent\Builder;
use Bavix\LaravelClickHouse\Database\Eloquent\Collection;
use Bavix\LaravelClickHouse\Database\Query\Builder as QueryBuilder;
use Bavix\LaravelClickHouse\Tests\BaseEloquentModelCasting;
use Bavix\LaravelClickHouse\Tests\Helpers;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery\Mock;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Tinderbox\ClickhouseBuilder\Query\Enums\Operator;
use Tinderbox\ClickhouseBuilder\Query\Grammar;
use Tinderbox\ClickhouseBuilder\Query\Identifier;
use Tinderbox\ClickhouseBuilder\Query\Tuple;
use Tinderbox\ClickhouseBuilder\Query\TwoElementsLogicExpression;

/**
 * @property Mock|Connection connection
 * @property Builder builder
 * @property BaseEloquentModelCasting model
 */
class BuilderTest extends TestCase
{
    use Helpers;

    /**
     * @var MockInterface&Connection
     */
    private MockInterface $connection;

    private Builder $builder;

    private BaseEloquentModelCasting $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->mock(Connection::class);

        $this->model = new BaseEloquentModelCasting();

        $this->builder = (new Builder(new QueryBuilder($this->connection, new Grammar())))
            ->setModel($this->model);
    }

    public function testWhereKey(): void
    {
        $id = $this->faker()
            ->numberBetween(1);

        $this->builder->whereKey($id);

        $wheres = $this->builder->getQuery()
            ->getWheres();

        self::assertCount(1, $wheres);
        /** @var TwoElementsLogicExpression $expression */
        $expression = $wheres[0];
        self::assertInstanceOf(TwoElementsLogicExpression::class, $expression);
        /** @var Identifier $first */
        $first = $expression->getFirstElement();
        self::assertInstanceOf(Identifier::class, $first);
        self::assertSame($this->model->getTable().'.'.$this->model->getKeyName(), (string) $first);
        self::assertSame($id, $expression->getSecondElement());
        $operator = $expression->getOperator();
        self::assertInstanceOf(Operator::class, $operator);
        self::assertSame('=', $operator->getValue());
    }

    public function testWhereKeyNot(): void
    {
        $ids = range(1, 5);

        $this->builder->whereKeyNot($ids);

        $wheres = $this->builder->getQuery()
            ->getWheres();

        self::assertCount(1, $wheres);
        /** @var TwoElementsLogicExpression $expression */
        $expression = $wheres[0];
        self::assertInstanceOf(TwoElementsLogicExpression::class, $expression);
        /** @var Identifier $first */
        $first = $expression->getFirstElement();
        self::assertInstanceOf(Identifier::class, $first);
        self::assertSame($this->model->getTable().'.'.$this->model->getKeyName(), (string) $first);
        /** @var Tuple $second */
        $second = $expression->getSecondElement();
        self::assertSame($ids, $second->getElements());
        $operator = $expression->getOperator();
        self::assertInstanceOf(Operator::class, $operator);
        self::assertSame('NOT IN', $operator->getValue());
    }

    public function testWhereSimple(): void
    {
        $date = $this->faker()
            ->date();
        $this->builder->where('date_column', '>', $date);

        $wheres = $this->builder->getQuery()
            ->getWheres();

        self::assertCount(1, $wheres);
        /** @var TwoElementsLogicExpression $expression */
        $expression = $wheres[0];
        self::assertInstanceOf(TwoElementsLogicExpression::class, $expression);
        /** @var Identifier $first */
        $first = $expression->getFirstElement();
        self::assertInstanceOf(Identifier::class, $first);
        self::assertSame('date_column', (string) $first);
        self::assertSame($date, $expression->getSecondElement());
        $operator = $expression->getOperator();
        self::assertInstanceOf(Operator::class, $operator);
        self::assertSame('>', $operator->getValue());
    }

    public function testWhereClosure(): void
    {
        /** @var Mock|DatabaseManager $resolver */
        $resolver = $this->mock(DatabaseManager::class);
        $resolver->shouldReceive('connection')
            ->andReturn($this->connection);
        BaseEloquentModelCasting::setConnectionResolver($resolver);

        $this->builder
            ->where(function (Builder $query) {
                $query->where('id', '<', 10)
                    ->where('id', '=', 15, 'OR');
            })
            ->where('status', 100);

        $sql = $this->builder->toSql();

        self::assertSame('SELECT * FROM `test_table` WHERE (`id` < 10 OR `id` = 15) AND `status` = 100', $sql);
    }

    public function testOrWhere(): void
    {
        $id = $this->faker()
            ->numberBetween(1);
        $date = $this->faker()
            ->date();
        $this->builder->where('id', $id);
        $this->builder->orWhere('date_column', '>', $date);

        $sql = $this->builder->toSql();
        self::assertSame('SELECT * FROM `test_table` WHERE `id` = '.$id.' OR `date_column` > \''.$date.'\'', $sql);
    }

    public function testFind(): void
    {
        $id = $this->faker()
            ->numberBetween(1);
        $stringAttribute = $this->faker()
            ->word();

        $this->connection
            ->shouldReceive('getName')
            ->andReturn($this->model->getConnectionName());

        $this->connection
            ->shouldReceive('select')
            ->andReturn([
                [
                    'id' => $id,
                    'stringAttribute' => $stringAttribute,
                ],
            ]);

        $model = $this->builder->find($id);

        self::assertInstanceOf(BaseEloquentModelCasting::class, $model);
        self::assertSame($id, $model->id);
        self::assertSame($stringAttribute, $model->stringAttribute);
    }

    public function testFindMany(): void
    {
        $ids = collect()
            ->times(5);

        $this->connection
            ->shouldReceive('getName')
            ->andReturn($this->model->getConnectionName());

        $this->connection
            ->shouldReceive('select')
            ->andReturn($ids->map(function ($id) {
                return [
                    'id' => $id,
                ];
            })->toArray());

        $models = $this->builder->findMany($ids->toArray());

        self::assertInstanceOf(Collection::class, $models);
        self::assertCount($ids->count(), $models);
    }

    public function testFindOrFail(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->connection
            ->shouldReceive('getName')
            ->andReturn($this->model->getConnectionName());

        $this->connection
            ->shouldReceive('select')
            ->andReturn([]);

        $this->builder->findOrFail($this->faker()->numberBetween());
    }

    public function testGet(): void
    {
        $connectionResultRow = [
            'id' => $this->faker()
                ->randomDigit(),
            'intAttribute' => (string) $this->faker()
                ->randomDigit(),
            'floatAttribute' => (string) $this->faker()
                ->randomFloat(2),
            'stringAttribute' => $this->faker()
                ->randomDigit(),
            'boolAttribute' => 1,
            'booleanAttribute' => 1,
            'objectAttribute' => json_encode([
                $this->faker()
                    ->word() => $this->faker()
                    ->randomLetter(),
            ]),
            'arrayAttribute' => json_encode(range(1, 5)),
            'dateAttribute' => now()
                ->toDateTimeString(),
            'datetimeAttribute' => now()
                ->toDateString(),
            'timestampAttribute' => now()
                ->toDateString(),
        ];
        $connectionResultRow['jsonAttribute'] = json_encode($connectionResultRow['arrayAttribute']);

        $this->connection
            ->shouldReceive('getName')
            ->andReturn($this->model->getConnectionName());

        $this->connection
            ->shouldReceive('select')
            ->andReturn([$connectionResultRow]);

        $collection = $this->builder->get();

        self::assertInstanceOf(Collection::class, $collection);
        self::assertCount(1, $collection);

        $retrievedModel = $collection[0];
        self::assertSame($connectionResultRow['id'], $retrievedModel->id);
        self::assertSame((int) $connectionResultRow['intAttribute'], $retrievedModel->intAttribute);
        self::assertSame((float) $connectionResultRow['floatAttribute'], $retrievedModel->floatAttribute);
        self::assertSame((string) $connectionResultRow['stringAttribute'], $retrievedModel->stringAttribute);
        self::assertTrue($retrievedModel->boolAttribute);
        self::assertTrue($retrievedModel->booleanAttribute);
        self::assertEquals(json_decode($connectionResultRow['objectAttribute']), $retrievedModel->objectAttribute);
        self::assertSame(json_decode($connectionResultRow['arrayAttribute'], true), $retrievedModel->arrayAttribute);
        self::assertSame($connectionResultRow['arrayAttribute'], $retrievedModel->jsonAttribute);
    }
}
