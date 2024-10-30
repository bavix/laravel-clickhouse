<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Tests;

use Bavix\LaravelClickHouse\Database\Eloquent\Model;

/**
 * @property int id
 * @property array transactions
 * @property int payment_system_id
 * @property float amount
 * @property string status
 */
class BaseEloquentModel extends Model
{
    protected $table = 'test_table';

    public function getListItemsAttribute($value)
    {
        return json_decode($value, true);
    }

    public function setListItemsAttribute($value)
    {
        $this->attributes['list_items'] = json_encode($value);
    }

    public function getPasswordAttribute()
    {
        return '******';
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password_hash'] = sha1($value);
    }
}
