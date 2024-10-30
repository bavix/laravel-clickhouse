<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Tests;

class BaseEloquentModelCasting extends BaseEloquentModel
{
    use Helpers;

    protected $casts = [
        'intAttribute' => 'int',
        'floatAttribute' => 'float',
        'stringAttribute' => 'string',
        'boolAttribute' => 'bool',
        'booleanAttribute' => 'boolean',
        'objectAttribute' => 'object',
        'arrayAttribute' => 'array',
        'jsonAttribute' => 'json',
        'dateAttribute' => 'date',
        'datetimeAttribute' => 'datetime',
        'timestampAttribute' => 'timestamp',
    ];

    protected $dates = ['payed_at'];

    public function jsonAttributeValue()
    {
        return $this->attributes['jsonAttribute'];
    }
}
