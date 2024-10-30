<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Database\Query;

use Bavix\LaravelClickHouse\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;
use Tinderbox\Clickhouse\Common\Format;
use Tinderbox\ClickhouseBuilder\Query\BaseBuilder;
use Tinderbox\ClickhouseBuilder\Query\Expression;
use Tinderbox\ClickhouseBuilder\Query\Grammar;

class Builder extends BaseBuilder
{
    use Macroable {
        __call as macroCall;
    }

    protected Connection $connection;

    public function __construct(
        Connection $connection,
        Grammar $grammar
    ) {
        $this->connection = $connection;
        $this->grammar = $grammar;
    }

    /**
     * Perform compiled from builder sql query and getting result.
     *
     * @throws \Tinderbox\Clickhouse\Exceptions\ClientException
     */
    public function get(): Collection
    {
        if ($this->async !== []) {
            $result = $this->connection->selectAsync($this->toAsyncSqls());
        } else {
            $result = $this->connection->select($this->toSql(), [], $this->getFiles());
        }

        return collect($result);
    }

    /**
     * Performs compiled sql for count rows only. May be used for pagination
     * Works only without async queries.
     *
     * @param string $column Column to pass into count() aggregate function
     *
     * @throws \Tinderbox\Clickhouse\Exceptions\ClientException
     */
    public function count($column = '*'): int
    {
        $builder = $this->getCountQuery();
        $result = $builder->get();

        if (count($this->groups) > 0) {
            return count($result);
        }

        return (int) ($result[0]['count'] ?? 0);
    }

    /**
     * Perform query and get first row.
     *
     * @return mixed|null
     *
     * @throws \Tinderbox\Clickhouse\Exceptions\ClientException
     */
    public function first()
    {
        return $this->get()
            ->first();
    }

    /**
     * Makes clean instance of builder.
     */
    public function newQuery(): self
    {
        return new static($this->connection, $this->grammar);
    }

    /**
     * Insert in table data from files.
     *
     * @throws \Tinderbox\Clickhouse\Exceptions\ClientException
     */
    public function insertFiles(array $columns, array $files, string $format = Format::CSV, int $concurrency = 5): array
    {
        return $this->connection->insertFiles(
            (string) $this->getFrom()
                ->getTable(),
            $columns,
            $files,
            $format,
            $concurrency
        );
    }

    /**
     * Performs insert query.
     */
    public function insert(array $values): bool
    {
        if ($values === []) {
            return false;
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        // Here, we will sort the insert keys for every record so that each insert is
        // in the same order for the record. We need to make sure this is the case
        // so there are not any errors or problems when inserting these records.
        foreach ($values as &$value) {
            ksort($value);
        }

        return $this->connection->insert($this->grammar->compileInsert($this, $values), Arr::flatten($values));
    }

    public function getCountForPagination()
    {
        return (int) $this->getConnection()
            ->table(
                $this
                    ->cloneWithout([
                        'columns' => [],
                        'orders' => [],
                        'limit' => null,
                    ])
                    ->select(new Expression('1')), null
            )
            ->count();
    }

    /**
     * Set the limit and offset for a given page.
     *
     * @param  int  $page
     * @param  int  $perPage
     * @return $this
     */
    public function forPage($page, $perPage = 15)
    {
        return $this->limit($perPage, ($page - 1) * $perPage);
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
