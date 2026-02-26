# Laravel Clickhouse

[![Latest Stable Version](https://poser.pugx.org/bavix/laravel-clickhouse/v/stable)](https://packagist.org/packages/bavix/laravel-clickhouse)
[![License](https://poser.pugx.org/bavix/laravel-clickhouse/license)](https://packagist.org/packages/bavix/laravel-clickhouse)
[![composer.lock](https://poser.pugx.org/bavix/laravel-clickhouse/composerlock)](https://packagist.org/packages/bavix/laravel-clickhouse)

> [!WARNING]
> **This project is provided "As Is".**  
> The maintainers are not actively developing new features or fixing bugs.  
> **Pull Requests are welcome!** If you need a fix or feature, please submit a PR with tests.

Laravel Clickhouse - Eloquent model for ClickHouse.

* **Vendor**: bavix
* **Package**: laravel-clickhouse
* **[Composer](https://getcomposer.org/):** `composer require bavix/laravel-clickhouse`

> [!IMPORTANT]
> I recommend using the standard postgres/mysql interface for clickhouse. More details here: https://clickhouse.com/docs/en/interfaces/mysql

The implementation is provided as is. Further work with the library only through contributors. Added linters, tests and much more. To make it easier for you to send PR.

## Get started
```sh
$ composer require bavix/laravel-clickhouse
```

Then add the code above into your config/app.php file providers section
```php
Bavix\LaravelClickHouse\ClickHouseServiceProvider::class,
```

And add new connection into your config/database.php file. Something like this:
```php
'connections' => [
    'bavix::clickhouse' => [
        'driver' => 'bavix::clickhouse',
        'host' => '',
        'port' => '',
        'database' => '',
        'username' => '',
        'password' => '',
        'options' => [
            'timeout' => 10,
            'protocol' => 'https'
        ]
    ]
]
```

Or like this, if clickhouse runs in cluster
```php
'connections' => [
    'bavix::clickhouse' => [
        'driver' => 'bavix::clickhouse',
        'servers' => [
            [
                'host' => 'ch-00.domain.com',
                'port' => '',
                'database' => '',
                'username' => '',
                'password' => '',
                'options' => [
                    'timeout' => 10,
                    'protocol' => 'https'
                ]
            ],
            [
                'host' => 'ch-01.domain.com',
                'port' => '',
                'database' => '',
                'username' => '',
                'password' => '',
                'options' => [
                    'timeout' => 10,
                    'protocol' => 'https'
                ]
            ]
        ]
    ]
],
```

Then create model
```php
<?php

use Bavix\LaravelClickHouse\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'payments';
}
```

And use it
```php
Payment::select(raw('count() AS cnt'), 'payment_system')
    ->whereBetween('payed_at', [
        Carbon\Carbon::parse('2017-01-01'),
        now(),
    ])
    ->groupBy('payment_system')
    ->get();

```
