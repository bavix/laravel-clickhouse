<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Database\Eloquent;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;

class Collection extends \Illuminate\Database\Eloquent\Collection
{
    /**
     * Find a model in the collection by key.
     *
     * @param mixed $key
     * @param mixed $default
     * @return Model|static
     */
    public function find($key, $default = null)
    {
        if ($key instanceof Model) {
            $key = $key->getKey();
        }

        if (is_array($key)) {
            if ($this->isEmpty()) {
                return new static();
            }

            return $this->whereIn($this->first()->getKeyName(), $key);
        }

        return Arr::first($this->items, function (Model $model) use ($key) {
            return $model->getKey() === $key;
        }, $default);
    }

    /**
     * Determine if a key exists in the collection.
     *
     * @param mixed $key
     * @param mixed $operator
     * @param mixed $value
     */
    public function contains($key, $operator = null, $value = null): bool
    {
        if (func_num_args() > 1 || $this->useAsCallable($key)) {
            return SupportCollection::contains(...func_get_args());
        }

        return SupportCollection::contains(function (Model $model) use ($key) {
            return $model->getKey() === $key;
        });
    }

    /**
     * Run a map over each of the items.
     *
     * @return SupportCollection|static
     */
    public function map(callable $callback)
    {
        $result = SupportCollection::map($callback);

        return $result->contains(function ($item) {
            return ! $item instanceof Model;
        }) ? $result->toBase() : $result;
    }
}
