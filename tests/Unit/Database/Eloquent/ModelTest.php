<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Tests\Unit\Database\Eloquent;

use Bavix\LaravelClickHouse\Tests\BaseEloquentModel;
use Bavix\LaravelClickHouse\Tests\BaseEloquentModelCasting;
use Bavix\LaravelClickHouse\Tests\BaseEloquentModelWith;
use Bavix\LaravelClickHouse\Tests\Helpers;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    use Helpers;

    public function testAttributeManipulation(): void
    {
        $model = new BaseEloquentModel();
        $model->status = 'successful';
        self::assertEquals('successful', $model->status);
        self::assertTrue(isset($model->status));
        unset($model->status);
        self::assertFalse(isset($model->status));
        // test mutation
        $model->list_items = range(1, 5);
        self::assertEquals(range(1, 5), $model->list_items);
        $attributes = $model->getAttributes();
        self::assertEquals(json_encode(range(1, 5)), $attributes['list_items']);
    }

    public function testDirtyAttributes(): void
    {
        $this->expectException(MassAssignmentException::class);

        new BaseEloquentModel([
            'foo' => '1',
            'bar' => 2,
            'baz' => 3,
        ]);
    }

    public function testDirtyOnCastOrDateAttributes(): void
    {
        $model = new BaseEloquentModelCasting();
        $model->setDateFormat('Y-m-d H:i:s');
        $model->boolAttribute = 1;
        $model->foo = 1;
        $model->bar = '2017-03-18';
        $model->dateAttribute = '2017-03-18';
        $model->datetimeAttribute = '2017-03-23 22:17:00';
        $model->syncOriginal();
        $model->boolAttribute = true;
        $model->foo = true;
        $model->bar = '2017-03-18 00:00:00';
        $model->dateAttribute = '2017-03-18 00:00:00';
        $model->datetimeAttribute = null;
        self::assertTrue($model->isDirty());
        self::assertTrue($model->isDirty('foo'));
        self::assertTrue($model->isDirty('bar'));
        self::assertFalse($model->isDirty('boolAttribute'));
        self::assertFalse($model->isDirty('dateAttribute'));
        self::assertTrue($model->isDirty('datetimeAttribute'));
    }

    public function testCalculatedAttributes(): void
    {
        $model = new BaseEloquentModel();
        $model->password = 'secret';
        $attributes = $model->getAttributes();
        // ensure password attribute was not set to null
        self::assertArrayNotHasKey('password', $attributes);
        self::assertSame('******', $model->password);
        $hash = 'e5e9fa1ba31ecd1ae84f75caaa474f3a663f05f4';
        self::assertEquals($hash, $attributes['password_hash']);
        self::assertEquals($hash, $model->password_hash);
    }

    public function testWithMethodCallsQueryBuilderCorrectly(): void
    {
        $result = BaseEloquentModelWith::with('foo', 'bar');
        self::assertEquals('foo', $result);
    }

    public function testTimestampsAreReturnedAsObjectsFromPlainDatesAndTimestamps(): void
    {
        $datetime = '2012-12-04';
        $model = new BaseEloquentModelCasting();
        $model->payed_at = $datetime;

        self::assertInstanceOf(Carbon::class, $model->payed_at);
        self::assertSame($datetime.' 00:00:00', $model->payed_at->toDateTimeString());
    }
}
