<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Database\Eloquent;

use ArrayAccess;
use Bavix\LaravelClickHouse\Database\Connection;
use Bavix\LaravelClickHouse\Database\Query\Builder as QueryBuilder;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\ConnectionResolverInterface as Resolver;
use Illuminate\Database\Eloquent\Concerns\GuardsAttributes;
use Illuminate\Database\Eloquent\Concerns\HasEvents;
use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Concerns\HidesAttributes;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use JsonSerializable;
use Tinderbox\ClickhouseBuilder\Query\Grammar;

abstract class Model implements ArrayAccess, UrlRoutable, Arrayable, Jsonable, JsonSerializable
{
    use Concerns\HasAttributes;
    use Concerns\Common;
    use HasEvents;
    use HasRelationships;
    use HidesAttributes;
    use GuardsAttributes;

    /**
     * Indicates if the model exists.
     */
    public bool $exists = false;

    public bool $wasRecentlyCreated = false;

    /**
     * Indicates if an exception should be thrown when trying to access a missing attribute on a retrieved model.
     *
     * @var bool
     */
    protected static $modelsShouldPreventAccessingMissingAttributes = false;

    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'bavix::clickhouse';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of primary key.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * The relations to eager load on every query.
     *
     * @var array
     */
    protected $with = [];

    /**
     * The relationship counts that should be eager loaded on every query.
     *
     * @var array
     */
    protected $withCount = [];

    /**
     * The number of models to return for pagination.
     *
     * @var int
     */
    protected $perPage = 15;

    protected static ConnectionResolverInterface $resolver;

    protected static Dispatcher $dispatcher;

    /**
     * The array of booted models.
     *
     * @var array
     */
    protected static $booted = [];

    /**
     * Create a new Eloquent model instance.
     */
    public function __construct(array $attributes = [])
    {
        $this->bootIfNotBooted();

        $this->syncOriginal();

        $this->fill($attributes);
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if an attribute or relation exists on the model.
     *
     * @param string $key
     * @return bool
     */
    public function __isset($key)
    {
        return $this->offsetExists($key);
    }

    /**
     * Unset an attribute on the model.
     *
     * @param string $key
     */
    public function __unset($key)
    {
        $this->offsetUnset($key);
    }

    /**
     * Handle dynamic method calls into the model.
     *
     * @param string $method
     * @param array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->newQuery()
            ->{$method}(...$parameters);
    }

    /**
     * Handle dynamic static method calls into the method.
     *
     * @param string $method
     * @param array  $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return (new static())->{$method}(...$parameters);
    }

    /**
     * Clear the list of booted models so they will be re-booted.
     */
    public static function clearBootedModels()
    {
        static::$booted = [];
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @return $this
     *
     * @throws MassAssignmentException
     */
    public function fill(array $attributes)
    {
        $totallyGuarded = $this->totallyGuarded();

        foreach ($this->fillableFromArray($attributes) as $key => $value) {
            $key = $this->removeTableFromKey($key);

            // The developers may choose to place some attributes in the "fillable" array
            // which means only those attributes may be set through mass assignment to
            // the model, and all others will just get ignored for security reasons.
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($totallyGuarded) {
                throw new MassAssignmentException($key);
            }
        }

        return $this;
    }

    /**
     * Fill the model with an array of attributes. Force mass assignment.
     *
     * @return $this
     */
    public function forceFill(array $attributes)
    {
        return static::unguarded(function () use ($attributes) {
            return $this->fill($attributes);
        });
    }

    /**
     * Create a new instance of the given model.
     *
     * @param array $attributes
     * @param bool  $exists
     * @return static
     */
    public function newInstance($attributes = [], $exists = false)
    {
        // This method just provides a convenient way for us to generate fresh model
        // instances of this current model. It is particularly useful during the
        // hydration of new objects via the Eloquent query builder instances.
        $model = new static((array) $attributes);

        $model->exists = $exists;

        $model->setConnection($this->getConnectionName());

        return $model;
    }

    /**
     * Create a new model instance that is existing.
     *
     * @return static
     */
    public function newFromBuilder(array $attributes = [], ?string $connection = null)
    {
        $model = $this->newInstance([], true);

        $model->setRawAttributes($attributes, true);

        $model->setConnection(
            in_array($connection, [null, '', '0'], true) ? $this->getConnectionName() : $connection
        );

        $model->fireModelEvent('retrieved', false);

        return $model;
    }

    /**
     * Get all of the models from the database.
     *
     * @return Collection|static[]
     */
    public static function all()
    {
        return (new static())->newQuery()
            ->get();
    }

    /**
     * Begin querying a model with eager loading.
     *
     * @param array|string $relations
     * @return Builder|static
     */
    public static function with($relations)
    {
        return (new static())->newQuery()
            ->with(is_string($relations) ? func_get_args() : $relations);
    }

    public static function query(): Builder
    {
        return (new static())->newQuery();
    }

    public function newQuery(): Builder
    {
        return $this->newQueryWithoutScopes();
    }

    public function newQueryWithoutScopes(): Builder
    {
        $builder = $this->newEloquentBuilder($this->newBaseQueryBuilder());

        // Once we have the query builders, we will set the model instances so the
        // builder can easily access any information it may need from the model
        // while it is constructing and executing various queries against it.
        return $builder->setModel($this)
            ->with($this->with);
    }

    public function newQueryWithoutScope()
    {
        return $this->newQuery();
    }

    public function newEloquentBuilder(QueryBuilder $query): Builder
    {
        return new Builder($query);
    }

    /**
     * Create a new Eloquent Collection instance.
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * Convert the model instance to an array.
     */
    public function toArray(): array
    {
        return $this->attributesToArray();
    }

    /**
     * Convert the model instance to JSON.
     *
     * @param int $options
     *
     * @throws JsonEncodingException
     */
    public function toJson($options = 0): string
    {
        $json = json_encode($this->jsonSerialize(), $options);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw JsonEncodingException::forModel($this, json_last_error_msg());
        }

        return $json;
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the database connection for the model.
     */
    public function getConnection(): ConnectionInterface
    {
        return static::resolveConnection($this->getConnectionName());
    }

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): string
    {
        return $this->connection;
    }

    /**
     * Set the connection associated with the model.
     *
     * @return $this
     */
    public function setConnection(string $name)
    {
        $this->connection = $name;

        return $this;
    }

    /**
     * Resolve a connection instance.
     */
    public static function resolveConnection(?string $connection = null): ConnectionInterface
    {
        return static::getConnectionResolver()->connection($connection);
    }

    /**
     * Get the connection resolver instance.
     */
    public static function getConnectionResolver(): ConnectionResolverInterface
    {
        return static::$resolver;
    }

    /**
     * Set the connection resolver instance.
     */
    public static function setConnectionResolver(Resolver $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        if (! isset($this->table)) {
            $this->setTable(str_replace('\\', '', Str::snake(Str::plural(class_basename($this)))));
        }

        return $this->table;
    }

    /**
     * Set the table associated with the model.
     *
     * @return $this
     */
    public function setTable(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->resolveRouteBindingQuery($this, $value, $field)
            ->first();
    }

    public function resolveSoftDeletableRouteBinding(mixed $value, ?string $field = null): ?Model
    {
        return $this->resolveRouteBindingQuery($this, $value, $field)
            ->withTrashed()
            ->first();
    }

    public function resolveChildRouteBinding($childType, $value, $field): ?Model
    {
        return $this->resolveChildRouteBindingQuery($childType, $value, $field)
            ->first();
    }

    public function resolveSoftDeletableChildRouteBinding(
        string $childType,
        mixed $value,
        ?string $field = null
    ): ?Model {
        return $this->resolveChildRouteBindingQuery($childType, $value, $field)
            ->withTrashed()
            ->first();
    }

    public function resolveRouteBindingQuery(Relation|Model $query, mixed $value, ?string $field = null): Builder
    {
        return $query->where($field ?? $this->getRouteKeyName(), $value);
    }

    /**
     * Get the primary key for the model.
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Set the primary key for the model.
     *
     * @return $this
     */
    public function setKeyName(string $key): self
    {
        $this->primaryKey = $key;

        return $this;
    }

    /**
     * Get the table qualified key name.
     */
    public function getQualifiedKeyName(): string
    {
        return $this->getTable().'.'.$this->getKeyName();
    }

    /**
     * Get the pk key type.
     */
    public function getKeyType(): string
    {
        return $this->keyType;
    }

    /**
     * Set the data type for the primary key.
     *
     * @return $this
     */
    public function setKeyType(string $type)
    {
        $this->keyType = $type;

        return $this;
    }

    public function getRouteKey(): mixed
    {
        return $this->getAttribute($this->getRouteKeyName());
    }

    public function getRouteKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * Get the value of the model's primary key.
     *
     * @return mixed
     */
    public function getKey()
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Get the default foreign key name for the model.
     */
    public function getForeignKey(): string
    {
        return Str::snake(class_basename($this)).'_'.$this->primaryKey;
    }

    /**
     * Get the number of models to return per page.
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * Set the number of models to return per page.
     */
    public function setPerPage(int $perPage): static
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Determine if the given attribute exists.
     *
     * @param mixed $offset
     */
    public function offsetExists($offset): bool
    {
        return $this->getAttribute($offset) !== null;
    }

    /**
     * Get the value for a given offset.
     *
     * @param mixed $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->getAttribute($offset);
    }

    /**
     * Set the value for a given offset.
     *
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        $this->setAttribute($offset, $value);
    }

    /**
     * Unset the value for a given offset.
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->attributes[$offset]);
    }

    public static function preventsAccessingMissingAttributes(): bool
    {
        return static::$modelsShouldPreventAccessingMissingAttributes;
    }

    protected static function whenBooted(Closure $callback)
    {
    }

    protected function resolveChildRouteBindingQuery(
        string $childType,
        mixed $value,
        ?string $field = null
    ): Relation|Model {
        $relationship = $this->{$this->childRouteBindingRelationshipName($childType)}();

        $field = in_array($field, [null, '', '0'], true) ? $relationship->getRelated()
            ->getRouteKeyName() : $field;

        if ($relationship instanceof HasManyThrough ||
            $relationship instanceof BelongsToMany) {
            $field = $relationship->getRelated()
                ->getTable().'.'.$field;
        }

        return $relationship instanceof Model
            ? $relationship->resolveRouteBindingQuery($relationship, $value, $field)
            : $relationship->getRelated()
                ->resolveRouteBindingQuery($relationship, $value, $field);
    }

    protected function childRouteBindingRelationshipName(string $childType): string
    {
        return Str::plural(Str::camel($childType));
    }

    /**
     * Check if the model needs to be booted and if so, do it.
     */
    protected function bootIfNotBooted()
    {
        if (! isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;

            $this->fireModelEvent('booting', false);

            static::boot();

            $this->fireModelEvent('booted', false);
        }
    }

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        static::bootTraits();
    }

    /**
     * Boot all of the bootable traits on the model.
     */
    protected static function bootTraits()
    {
        $class = static::class;

        foreach (class_uses_recursive($class) as $trait) {
            if (method_exists($class, $method = 'boot'.class_basename($trait))) {
                forward_static_call([$class, $method]);
            }
        }
    }

    /**
     * Remove the table name from a given key.
     *
     * @param string $key
     * @return string
     */
    protected function removeTableFromKey($key)
    {
        return Str::contains($key, '.') ? last(explode('.', $key)) : $key;
    }

    protected function newBaseQueryBuilder(): QueryBuilder
    {
        /** @var Connection $connection */
        $connection = $this->getConnection();

        return new QueryBuilder($connection, new Grammar());
    }
}
