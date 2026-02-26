<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Database;

use Bavix\LaravelClickHouse\Database\Query\Builder;
use Bavix\LaravelClickHouse\Database\Query\PdoInterface;
use Tinderbox\ClickhouseBuilder\Query\Grammar;

class Connection extends \Tinderbox\ClickhouseBuilder\Integrations\Laravel\Connection
{
    public function query(): Builder
    {
        return new Builder($this, new Grammar());
    }

    public function getPdo(): PdoInterface
    {
        return app(PdoInterface::class);
    }

    /**
     * Insert a new record into the database.
     *
     * ClickHouse doesn't use parameterized queries, so we don't pass bindings
     * to avoid Laravel's DateTime formatting which requires a grammar.
     *
     * @param string $query
     * @param array<array-key, mixed> $bindings
     * @return bool
     */
    public function insert($query, $bindings = [])
    {
        $startTime = microtime(true);

        $result = $this->getClient()
            ->writeOne($query);

        // Pass empty bindings to avoid Laravel's DateTime formatting
        $this->logQuery($query, [], microtime(true) - $startTime);

        return $result;
    }
}
