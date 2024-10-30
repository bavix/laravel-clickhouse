<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Database\Query;

interface PdoInterface
{
    public function quote(mixed $binding): mixed;
}
