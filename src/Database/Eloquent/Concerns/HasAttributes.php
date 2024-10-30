<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Database\Eloquent\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasAttributes as BaseHasAttributes;

trait HasAttributes
{
    use BaseHasAttributes;

    public function getDates(): array
    {
        return $this->dates ?? [];
    }

    public function getCasts(): array
    {
        return $this->casts ?? [];
    }

    protected function getDateFormat(): string
    {
        return $this->dateFormat ?? 'Y-m-d H:i:s';
    }
}
