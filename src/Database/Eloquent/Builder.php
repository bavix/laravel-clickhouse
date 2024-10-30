<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse\Database\Eloquent;

use BadMethodCallException;
use Bavix\LaravelClickHouse\Database\Query\Builder as QueryBuilder;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Concerns\BuildsQueries;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\RelationNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tinderbox\Clickhouse\Exceptions\ClientException;

/**
 * @mixin QueryBuilder
 */
class Builder
{
    use BuildsQueries;

    /**
     * The base query builder instance.
     *
     * @var QueryBuilder
     */
    protected $query;

    /**
     * The model being queried.
     *
     * @var Model
     */
    protected $model;

    /**
     * The relationships that should be eager loaded.
     *
     * @var array
     */
    protected $eagerLoad = [];

    /**
     * All of the globally registered builder macros.
     *
     * @var array
     */
    protected static $macros = [];

    /**
     * All of the locally registered builder macros.
     *
     * @var array
     */
    protected $localMacros = [];

    /**
     * The methods that should be returned from query builder.
     *
     * @var array
     */
    protected $passthru = [
        'insert',
        'getBindings',
        'toSql',
        'exists',
        'count',
        'min',
        'max',
        'avg',
        'sum',
        'getConnection',
    ];

    /**
     * Applied global scopes.
     *
     * @var array
     */
    protected $scopes = [];

    public function __construct(QueryBuilder $query)
    {
        $this->query = $query;
    }

    /**
     * Dynamically handle calls into the query instance.
     *
     * @param string $method
     * @param array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if ($method === 'macro') {
            $this->localMacros[$parameters[0]] = $parameters[1];

            return null;
        }

        if (isset($this->localMacros[$method])) {
            array_unshift($parameters, $this);

            return $this->localMacros[$method](...$parameters);
        }

        if (isset(static::$macros[$method])) {
            if (static::$macros[$method] instanceof Closure) {
                return call_user_func_array(static::$macros[$method]->bindTo($this, static::class), $parameters);
            }

            return call_user_func_array(static::$macros[$method], $parameters);
        }

        if ($this->model instanceof Model && method_exists($this->model, $scope = 'scope'.ucfirst($method))) {
            return $this->callScope([$this->model, $scope], $parameters);
        }

        if (in_array($method, $this->passthru, true)) {
            return $this->toBase()
                ->{$method}(...$parameters);
        }

        $this->query->{$method}(...$parameters);

        return $this;
    }

    /**
     * Dynamically handle calls into the query instance.
     *
     * @param string $method
     * @param array  $parameters
     * @return mixed
     *
     * @throws BadMethodCallException
     */
    public static function __callStatic($method, $parameters)
    {
        if ($method === 'macro') {
            static::$macros[$parameters[0]] = $parameters[1];

            return null;
        }

        if (! isset(static::$macros[$method])) {
            throw new BadMethodCallException("Method {$method} does not exist.");
        }

        if (static::$macros[$method] instanceof Closure) {
            return call_user_func_array(Closure::bind(static::$macros[$method], null, static::class), $parameters);
        }

        return call_user_func_array(static::$macros[$method], $parameters);
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     */
    public function __clone()
    {
        $this->query = clone $this->query;
    }

    /**
     * Add a where clause on the primary key to the query.
     *
     * @param mixed $id
     * @return $this
     */
    public function whereKey($id): self
    {
        if (is_array($id) || $id instanceof Arrayable) {
            $this->query->whereIn($this->model->getQualifiedKeyName(), $id);

            return $this;
        }

        return $this->where($this->model->getQualifiedKeyName(), '=', $id);
    }

    /**
     * Add a where clause on the primary key to the query.
     *
     * @param mixed $id
     * @return $this
     */
    public function whereKeyNot($id): self
    {
        if (is_array($id) || $id instanceof Arrayable) {
            $this->query->whereNotIn($this->model->getQualifiedKeyName(), $id);

            return $this;
        }

        return $this->where($this->model->getQualifiedKeyName(), '!=', $id);
    }

    /**
     * Add a basic where clause to the query.
     *
     * @param string|array|\Closure $column
     * @param string                $operator
     * @param mixed                 $value
     * @return $this
     */
    public function where($column, $operator = null, $value = null, string $boolean = 'AND'): self
    {
        if ($column instanceof Closure) {
            $this->query->where(function (QueryBuilder $queryBuilder) use ($column) {
                $eloquentBuilder = $this->model->newEloquentBuilder($queryBuilder);
                $column($eloquentBuilder);
            }, null, null, $boolean);
        } else {
            $this->query->where(...func_get_args());
        }

        return $this;
    }

    /**
     * Add an "or where" clause to the query.
     *
     * @param \Closure|array|string $column
     * @param string                $operator
     * @param mixed                 $value
     * @return $this
     */
    public function orWhere($column, $operator = null, $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * Create a collection of models from plain arrays.
     */
    public function hydrate(iterable $items): Collection
    {
        $instance = $this->newModelInstance();
        $elements = [];
        foreach ($items as $item) {
            $elements[] = $instance->newFromBuilder($item);
        }

        return $instance->newCollection($elements);
    }

    /**
     * Create a collection of models from a raw query.
     *
     * @param string $query
     * @param array  $bindings
     */
    public function fromQuery($query, $bindings = []): Collection
    {
        return $this->hydrate($this->query->getConnection()->select($query, $bindings, true));
    }

    /**
     * Find a model by its primary key.
     *
     * @param mixed $id
     * @return Model|Collection|static[]|static|null
     */
    public function find($id)
    {
        if (is_array($id) || $id instanceof Arrayable) {
            return $this->findMany($id);
        }

        return $this->whereKey($id)
            ->first();
    }

    /**
     * Find multiple models by their primary keys.
     *
     * @param \Illuminate\Contracts\Support\Arrayable|array $ids
     */
    public function findMany($ids): Collection
    {
        if (count($ids) < 1) {
            return $this->model->newCollection();
        }

        return $this->whereKey($ids)
            ->get();
    }

    /**
     * Find a model by its primary key or throw an exception.
     *
     * @param mixed $id
     * @return Model|Collection
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail($id)
    {
        // fixme Arrayable::class
        $result = $this->find($id);

        if (is_array($id)) {
            if (count($result) === count(array_unique($id))) {
                return $result;
            }
        } elseif ($result !== null) {
            return $result;
        }

        throw (new ModelNotFoundException())->setModel(get_class($this->model), $id);
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @throws ModelNotFoundException
     */
    public function firstOrFail(): ?Model
    {
        /** @var Model $model */
        $model = $this->first();
        if ($model === null) {
            throw (new ModelNotFoundException())
                ->setModel(get_class($this->model));
        }

        return $model;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @throws ClientException
     */
    public function get(): Collection
    {
        $builder = $this->applyScopes();

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded, which will solve the
        // n+1 query issue for the developers to avoid running a lot of queries.
        if (count($models = $builder->getModels()) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $builder->getModel()
            ->newCollection($models);
    }

    /**
     * Get the hydrated models without eager loading.
     *
     * @return Model[]
     *
     * @throws ClientException
     */
    public function getModels(): array
    {
        $result = $this->query->get()
            ->all();

        return $this->hydrate($result)
            ->all();
    }

    /**
     * Eager load the relationships for the models.
     */
    public function eagerLoadRelations(array $models): array
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            // For nested eager loads we'll skip loading them here and they will be set as an
            // eager load on the query to retrieve the relation so that they will be eager
            // loaded on that query, because that is where they get hydrated as models.
            if (strpos($name, '.') === false) {
                $models = $this->eagerLoadRelation($models, $name, $constraints);
            }
        }

        return $models;
    }

    /**
     * Get the relation instance for the given relation name.
     */
    public function getRelation(string $name): Relation
    {
        // We want to run a relationship query without any constrains so that we will
        // not have to remove these where clauses manually which gets really hacky
        // and error prone. We don't want constraints because we add eager ones.
        $relation = Relation::noConstraints(function () use ($name) {
            try {
                return $this->getModel()
                    ->{$name}();
            } catch (BadMethodCallException $e) {
                throw RelationNotFoundException::make($this->getModel(), $name);
            }
        });

        $nested = $this->relationsNestedUnder($name);

        // If there are nested relationships set on the query, we will put those onto
        // the query instances so that they can be handled after this relationship
        // is loaded. In this way they will all trickle down as they are loaded.
        if ($nested !== []) {
            $relation->getQuery()
                ->with($nested);
        }

        return $relation;
    }

    /**
     * Chunk the results of a query by comparing numeric IDs.
     *
     * @param null        $column
     * @param string|null $alias
     */
    public function chunkById(int $count, callable $callback, $column = null, $alias = null): bool
    {
        $column = $column === null ? $this->getModel()
            ->getKeyName() : $column;
        $alias = $alias === null ? $column : $alias;
        $lastId = 0;

        do {
            $clone = clone $this;

            // We'll execute the query for the given page and get the results. If there are
            // no results we can just break and return from here. When there are results
            // we will call the callback with the current chunk of these results here.
            $results = $clone->forPageAfterId($count, $lastId, $column)
                ->get();

            $countResults = (int) $results->count();

            if ($countResults === 0) {
                break;
            }

            // On each chunk result set, we will pass them to the callback and then let the
            // developer take care of everything within the callback, which allows us to
            // keep the memory low for spinning through large result sets for working.
            if ($callback($results) === false) {
                return false;
            }

            $lastId = $results->last()
->{$alias};

            unset($results);
        } while ($countResults === $count);

        return true;
    }

    /**
     * Get an array with the values of a given column.
     *
     * @param string|null $key
     */
    public function pluck(string $column, $key = null): \Illuminate\Support\Collection
    {
        $results = $this->toBase()
            ->pluck($column, $key);

        // If the model has a mutator for the requested column, we will spin through
        // the results and mutate the values so that the mutated version of these
        // columns are returned as you would expect from these Eloquent models.
        if (! $this->model->hasGetMutator($column) &&
            ! $this->model->hasCast($column) &&
            ! in_array($column, $this->model->getDates(), true)) {
            return $results;
        }

        return $results->map(function ($value) use ($column) {
            return $this->model->newFromBuilder([
                $column => $value,
            ])->{$column};
        });
    }

    /**
     * Paginate the given query.
     *
     * @param array    $columns
     * @param string   $pageName
     * @param int|null $page
     */
    public function paginate(
        ?int $perPage = null,
        $columns = ['*'],
        $pageName = 'page',
        $page = null
    ): LengthAwarePaginator {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage !== null && $perPage !== 0 ? $perPage : $this->model->getPerPage();
        $results = ($total = $this->toBase()->getCountForPagination())
            ? $this->forPage($page, $perPage)
                ->get($columns)
            : $this->model->newCollection();

        return $this->paginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param array    $columns
     * @param string   $pageName
     * @param int|null $page
     *
     * @throws ClientException
     */
    public function simplePaginate(
        ?int $perPage = null,
        $columns = ['*'],
        $pageName = 'page',
        $page = null
    ): \Illuminate\Contracts\Pagination\Paginator {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage !== null && $perPage !== 0 ? $perPage : $this->model->getPerPage();

        // Next we will set the limit and offset for this query so that when we get the
        // results we get the proper section of results. Then, we'll create the full
        // paginator instances for these results with the given page and per page.
        $this->skip(($page - 1) * $perPage)
            ->take($perPage + 1);

        return $this->simplePaginator($this->get(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Call the given local model scopes.
     *
     * @return mixed
     */
    public function scopes(array $scopes)
    {
        $builder = $this;

        foreach ($scopes as $scope => $parameters) {
            // If the scope key is an integer, then the scope was passed as the value and
            // the parameter list is empty, so we will format the scope name and these
            // parameters here. Then, we'll be ready to call the scope on the model.
            if (is_int($scope)) {
                [$scope, $parameters] = [$parameters, []];
            }

            // Next we'll pass the scope callback to the callScope method which will take
            // care of grouping the "wheres" properly so the logical order doesn't get
            // messed up when adding scopes. Then we'll return back out the builder.
            $builder = $builder->callScope([$this->model, 'scope'.ucfirst($scope)], (array) $parameters);
        }

        return $builder;
    }

    /**
     * Apply the scopes to the Eloquent builder instance and return it.
     *
     * @return Builder|static
     */
    public function applyScopes()
    {
        if (! $this->scopes) {
            return $this;
        }

        $builder = clone $this;

        foreach ($this->scopes as $identifier => $scope) {
            if (! isset($builder->scopes[$identifier])) {
                continue;
            }

            $builder->callScope(function (Builder $builder) use ($scope) {
                // If the scope is a Closure we will just go ahead and call the scope with the
                // builder instance. The "callScope" method will properly group the clauses
                // that are added to this query so "where" clauses maintain proper logic.
                if ($scope instanceof Closure) {
                    $scope($builder);
                }

                // If the scope is a scope object, we will call the apply method on this scope
                // passing in the builder and the model instance. After we run all of these
                // scopes we will return back the builder instance to the outside caller.
                if ($scope instanceof Scope) {
                    $scope->apply($builder, $this->getModel());
                }
            });
        }

        return $builder;
    }

    /**
     * Set the relationships that should be eager loaded.
     *
     * @param mixed $relations
     * @return $this
     */
    public function with($relations)
    {
        $eagerLoad = $this->parseWithRelations(is_string($relations) ? func_get_args() : $relations);

        $this->eagerLoad = array_merge($this->eagerLoad, $eagerLoad);

        return $this;
    }

    /**
     * Prevent the specified relations from being eager loaded.
     *
     * @param mixed $relations
     * @return $this
     */
    public function without($relations)
    {
        $this->eagerLoad = array_diff_key($this->eagerLoad, array_flip(
            is_string($relations) ? func_get_args() : $relations
        ));

        return $this;
    }

    /**
     * Create a new instance of the model being queried.
     *
     * @param array $attributes
     * @return Model
     */
    public function newModelInstance($attributes = [])
    {
        return $this->model->newInstance($attributes)
            ->setConnection($this->query->getConnection()->getName());
    }

    public function create($attributes = [])
    {
        return tap($this->newModelInstance($attributes), static function ($instance) {
            $instance->save();
        });
    }

    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    public function setQuery(QueryBuilder $query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Get a base query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function toBase()
    {
        return $this->applyScopes()
            ->getQuery();
    }

    /**
     * Get the relationships being eagerly loaded.
     *
     * @return array
     */
    public function getEagerLoads()
    {
        return $this->eagerLoad;
    }

    /**
     * Set the relationships being eagerly loaded.
     *
     * @return $this
     */
    public function setEagerLoads(array $eagerLoad)
    {
        $this->eagerLoad = $eagerLoad;

        return $this;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function setModel(Model $model)
    {
        $this->model = $model;

        $this->query->from($model->getTable());

        return $this;
    }

    /**
     * Get the given macro by name.
     *
     * @param string $name
     * @return Closure
     */
    public function getMacro($name)
    {
        return Arr::get($this->localMacros, $name);
    }

    /**
     * Eagerly load the relationship on a set of models.
     */
    protected function eagerLoadRelation(array $models, string $name, Closure $constraints): array
    {
        // First we will "back up" the existing where conditions on the query so we can
        // add our eager constraints. Then we will merge the wheres that were on the
        // query back to it in order that any where conditions might be specified.
        $relation = $this->getRelation($name);

        $relation->addEagerConstraints($models);

        $constraints($relation);

        // Once we have the results, we just match those back up to their parent models
        // using the relationship instance. Then we just return the finished arrays
        // of models which have been eagerly hydrated and are readied for return.
        return $relation->match($relation->initRelation($models, $name), $relation->getEager(), $name);
    }

    /**
     * Get the deeply nested relations for a given top-level relation.
     */
    protected function relationsNestedUnder(string $relation): array
    {
        $nested = [];

        // We are basically looking for any relationships that are nested deeper than
        // the given top-level relationship. We will just check for any relations
        // that start with the given top relations and adds them to our arrays.
        foreach ($this->eagerLoad as $name => $constraints) {
            if ($this->isNestedUnder($relation, $name)) {
                $nested[substr($name, strlen($relation.'.'))] = $constraints;
            }
        }

        return $nested;
    }

    /**
     * Determine if the relationship is nested.
     */
    protected function isNestedUnder(string $relation, string $name): bool
    {
        return Str::contains($name, '.') && Str::startsWith($name, $relation.'.');
    }

    /**
     * Apply the given scope on the current builder instance.
     *
     * @param array    $parameters
     * @return mixed
     */
    protected function callScope(callable $scope, $parameters = [])
    {
        array_unshift($parameters, $this);

        $query = $this->getQuery();

        // We will keep track of how many wheres are on the query before running the
        // scope so that we can properly group the added scope constraints in the
        // query as their own isolated nested where statement and avoid issues.
        $originalWhereCount = $query->getWheres() === null
            ? 0 : count($query->getWheres());

        $result = $scope(...array_values($parameters)) ?? $this;

        if (count($query->getWheres()) > $originalWhereCount) {
            $this->addNewWheresWithinGroup($query, $originalWhereCount);
        }

        return $result;
    }

    /**
     * Nest where conditions by slicing them at the given where count.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param int                                $originalWhereCount
     */
    protected function addNewWheresWithinGroup(QueryBuilder $query, $originalWhereCount)
    {
        // Here, we totally remove all of the where clauses since we are going to
        // rebuild them as nested queries by slicing the groups of wheres into
        // their own sections. This is to prevent any confusing logic order.
        $allWheres = $query->wheres;

        $query->wheres = [];

        $this->groupWhereSliceForScope($query, array_slice($allWheres, 0, $originalWhereCount));

        $this->groupWhereSliceForScope($query, array_slice($allWheres, $originalWhereCount));
    }

    /**
     * Slice where conditions at the given offset and add them to the query as a nested condition.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array                              $whereSlice
     */
    protected function groupWhereSliceForScope(QueryBuilder $query, $whereSlice)
    {
        $whereBooleans = collect($whereSlice)
            ->pluck('boolean');

        // Here we'll check if the given subset of where clauses contains any "or"
        // booleans and in this case create a nested where expression. That way
        // we don't add any unnecessary nesting thus keeping the query clean.
        if ($whereBooleans->contains('or')) {
            $query->wheres[] = $this->createNestedWhere($whereSlice, $whereBooleans->first());
        } else {
            $query->wheres = array_merge($query->wheres, $whereSlice);
        }
    }

    /**
     * Create a where array with nested where conditions.
     *
     * @param array  $whereSlice
     * @param string $boolean
     * @return array
     */
    protected function createNestedWhere($whereSlice, $boolean = 'and')
    {
        $whereGroup = $this->getQuery()
            ->forNestedWhere();

        $whereGroup->wheres = $whereSlice;

        return [
            'type' => 'Nested',
            'query' => $whereGroup,
            'boolean' => $boolean,
        ];
    }

    /**
     * Parse a list of relations into individuals.
     *
     * @return array
     */
    protected function parseWithRelations(array $relations)
    {
        $results = [];

        foreach ($relations as $name => $constraints) {
            // If the "relation" value is actually a numeric key, we can assume that no
            // constraints have been specified for the eager load and we'll just put
            // an empty Closure with the loader so that we can treat all the same.
            if (is_numeric($name)) {
                $name = $constraints;

                [$name, $constraints] = Str::contains($name, ':')
                    ? $this->createSelectWithConstraint($name)
                    : [
                        $name,
                        function () {
                            //
                        },
                    ];
            }

            // We need to separate out any nested includes. Which allows the developers
            // to load deep relationships using "dots" without stating each level of
            // the relationship with its own key in the array of eager load names.
            $results = $this->addNestedWiths($name, $results);

            $results[$name] = $constraints;
        }

        return $results;
    }

    /**
     * Create a constraint to select the given columns for the relation.
     *
     * @param string $name
     * @return array
     */
    protected function createSelectWithConstraint($name)
    {
        return [
            explode(':', $name)[0],
            function ($query) use ($name) {
                $query->select(explode(',', explode(':', $name)[1]));
            },
        ];
    }

    /**
     * Parse the nested relationships in a relation.
     *
     * @param string $name
     * @param array  $results
     * @return array
     */
    protected function addNestedWiths($name, $results)
    {
        $progress = [];

        // If the relation has already been set on the result array, we will not set it
        // again, since that would override any constraints that were already placed
        // on the relationships. We will only set the ones that are not specified.
        foreach (explode('.', $name) as $segment) {
            $progress[] = $segment;

            if (! isset($results[$last = implode('.', $progress)])) {
                $results[$last] = function () {
                    //
                };
            }
        }

        return $results;
    }
}
