<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Database\Eloquent\Concerns;

trait Common
{
    /**
     * Save the model to the database.
     */
    public function save(array $options = []): bool
    {
        return static::insert($this->toArray());
    }
}
