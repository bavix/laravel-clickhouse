<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Database\Query;

final class Pdo implements PdoInterface
{
    public function quote(mixed $binding): mixed
    {
        return $binding;
    }
}
