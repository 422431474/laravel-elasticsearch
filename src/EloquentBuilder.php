<?php

namespace DesignMyNight\Elasticsearch;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder as BaseBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Class EloquentBuilder
 * @method QueryBuilder filter($filters) Support for Searchable::scopeFilter()
 * @package DesignMyNight\Elasticsearch
 */
class EloquentBuilder extends BaseBuilder
{
    protected $type;

    /**
     * Set a model instance for the model being queried.
     *
     * @param Model $model
     * @return $this
     */
    public function setModel(Model $model)
    {
        $this->model = $model;

        $this->query->from($model->getSearchIndex());

        $this->query->type($model->getSearchType());

        return $this;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function get($columns = ['*'])
    {
        $builder = $this->applyScopes();

        $models = $builder->getModels($columns);

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded, which will solve the
        // n+1 query issue for the developers to avoid running a lot of queries.
        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $builder->getModel()->newCollection($models);
    }

    /**
     * @param string $columns
     * @return int
     */
    public function count($columns = '*'): int
    {
        return $this->toBase()->getCountForPagination($columns);
    }
    
     /**
     * @return bool
     */
    public function exists(): bool
    {
        return (bool)$this->toBase()->getCountForPagination('*');
    }
    
    /**
     * Script 更新
     * @return bool
     */
    public function updateScript(string $script, array $params){
        return $this->toBase()->updateScript($script,$params);
    }
    
    /**
     * 刷新索引可见
     * @return array|mixed
     */
    public function refresh(){
        return $this->model->getConnection()->indices()->refresh(['index'=>$this->model->getTable()])['_shards'] ?? [];
    }

    /**
     * @param string $collectionClass
     * @return Collection
     */
    public function getAggregations(string $collectionClass = ''): Collection
    {
        $collectionClass = $collectionClass ?: Collection::class;
        $aggregations = $this->query->getAggregationResults();

        return new $collectionClass($aggregations);
    }

    /**
     * Get the hydrated models without eager loading.
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Model[]
     */
    public function getModels($columns = ['*'])
    {
        return $this->model->hydrate(
            $this->query->get($columns)->all()
        )->all();
    }

    /**
     * @inheritdoc
     */
    public function hydrate(array $items)
    {
        $instance = $this->newModelInstance();

        return $instance->newCollection(array_map(function ($item) use ($instance) {
            return $instance->newFromBuilder($item, $this->getConnection()->getName());
        }, $items));
    }

    /**
     * Get a generator for the given query.
     *
     * @return Generator
     */
    public function cursor()
    {
        foreach ($this->applyScopes()->query->cursor() as $record) {
            yield $this->model->newFromBuilder($record);
        }
    }

    /**
     * Paginate the given query.
     *
     * @param  int  $perPage
     * @param  array  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     *
     * @throws \InvalidArgumentException
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        if($this->query->distinct){
            $distinct = is_string($this->query->distinct) ? $this->query->distinct : collect($this->query->distinct)->first();
            $total = Arr::get((clone $this)
                ->aggregation('query_distinct_total', 'cardinality', ['field' => $distinct,'precision_threshold'=>40000])
                ->getQuery()->getAggregationResults(),'query_distinct_total.value');
            $results = $this->forPage($page, $perPage)->get($columns);
        } else{
            $results = $this->forPage($page, $perPage)->get($columns);
            $total = $this->toBase()->getCountForPagination($columns);
        }

        return Container::getInstance()->makeWith(LengthAwarePaginator::class, [
            'items' => $results, 'total' => $total, 'perPage' => $perPage, 'currentPage' => $page,
            'options' => [
                'path'     => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        ]);
    }
}
