<?php

namespace Dbugit\Scout\Engines;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Dbugit\Scout\DbugitSearchable;

class DbugitSearchEngine extends Engine
{
    /**
     * @var DbugitSearch
     */
    protected $dbugitsearchable;

    /**
     * @var Builder
     */
    protected $builder;

    /**
     * Create a new engine instance.
     *
     * @param DbugitSearch $tnt
     */
    public function __construct(DbugitSearchable $dbugitsearchable)
    {
        $this->dbugitsearchable = $dbugitsearchable;
    }

    /**
     * Update the given model in the index.
     *
     * @param Collection $models
     *
     * @return void
     */
    public function update($models)
    {
        $dbugitsearchable = $this->dbugitsearchable;

        $models->each(function ($model) use ($dbugitsearchable){
            $array              = $model->toSearchableArray();
            $modelclass         = get_class($model);
            $dbugitsearchable   = DbugitSearchable::where('searchable_id',$model->getKey())->where("searchable_model",$modelclass)->first() ?? new DbugitSearchable();
            $searchable_data    = mb_strtolower(implode(" ", $model->toSearchableArray()));

            if (empty($array)) {
                return;
            }

            $dbugitsearchable->fill([
                "searchable_id"     => $model->getKey(),
                "searchable_model"  => $modelclass,
                "searchable_data"   => $searchable_data,
            ]);
            $dbugitsearchable->save();
        });
    }

    /**
     * Remove the given model from the index.
     *
     * @param Collection $models
     *
     * @return void
     */
    public function delete($models)
    {
        $models->each(function ($model) {
            $dbugitsearchable   = DbugitSearchable::where('searchable_id',$model->getKey())->where("searchable_model",$modelclass)->first();

            if($dbugitsearchable){
                $dbugitsearchable->delete();
            }
        });
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        try {
            return $this->performSearch($builder);
        } catch (IndexNotFoundException $e) {
            $this->initIndex($builder->model);
        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @param int     $perPage
     * @param int     $page
     *
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $results = $this->performSearch($builder);

        if ($builder->limit) {
            $results['hits'] = $builder->limit;
        }

        $filtered = $this->discardIdsFromResultSetByConstraints($builder, $results['ids']);

        $results['hits'] = $filtered->count();

        $chunks = array_chunk($filtered->toArray(), $perPage);

        if (empty($chunks)) {
            return $results;
        }

        if (array_key_exists($page - 1, $chunks)) {
            $results['ids'] = $chunks[$page - 1];
        } else {
            $results['ids'] = end($chunks);
        }

        return $results;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     *
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $index              = $builder->index ?: $builder->model->searchableAs();
        $limit              = $builder->limit ?: 10000;
        $searchable_model   = get_class($builder->model);
        $search             = strtolower($builder->query);
        $this->builder      = $builder;
        
        $dbugitsearchable   = DbugitSearchable::where('searchable_model',$searchable_model)->where('searchable_data','like',"%" . $search . "%")->pluck('searchable_id');
        
        return $dbugitsearchable;
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param mixed                               $results
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (is_null($results) || count($results) === 0) {
            return Collection::make();
        }

        $keys       = collect($results)->values()->all();
        $builder    = $this->getBuilder($model);

        if ($this->builder->queryCallback) {
            call_user_func($this->builder->queryCallback, $builder);
        }

        $models     = $builder->whereIn(
            $model->getQualifiedKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        // sort models by user choice
        if (!empty($this->builder->orders)) {
            return $models->values();
        }

        // sort models by tnt search result set
        return collect($results)->map(function ($hit) use ($models) {
            if (isset($models[$hit])) {
                return $models[$hit];
            }
        })->filter()->values();
    }

    /**
     * Return query builder either from given constraints, or as
     * new query. Add where statements to builder when given.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return Builder
     */
    public function getBuilder($model)
    {
        // get query as given constraint or create a new query
        $builder = isset($this->builder->constraints) ? $this->builder->constraints : $model->newQuery();

        $builder = $this->handleSoftDeletes($builder, $model);

        $builder = $this->applyWheres($builder);

        $builder = $this->applyOrders($builder);

        return $builder;
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param mixed $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['ids'])->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param mixed $results
     *
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['hits'];
    }

    public function initIndex($model)
    {
        $indexName = $model->searchableAs();

        if (!file_exists($this->dbsearch->config['storage']."/{$indexName}.index")) {
            $indexer = $this->dbsearch->createIndex("$indexName.index");
            $indexer->setDatabaseHandle($model->getConnection()->getPdo());
            $indexer->setPrimaryKey($model->getKeyName());
        }
    }

    /**
     * The search index results ($results['ids']) need to be compared against our query
     * that contains the constraints.
     *
     * To get the correct results and counts for the pagination, we remove those ids
     * from the search index results that were found by the search but are not part of
     * the query ($sub) that is constrained.
     *
     * This is achieved with self joining the constrained query as subquery and selecting
     * the ids which are not matching to anything (i.e., is null).
     *
     * The constraints usually remove only a small amount of results, which is why the non
     * matching results are looked up and removed, instead of returning a collection with
     * all the valid results.
     */
    private function discardIdsFromResultSetByConstraints($builder, $searchResults)
    {
        $qualifiedKeyName    = $builder->model->getQualifiedKeyName(); // tableName.id
        $subQualifiedKeyName = 'sub.'.$builder->model->getKeyName(); // sub.id

        $sub = $this->getBuilder($builder->model)->whereIn(
            $qualifiedKeyName, $searchResults
        ); // sub query for left join

        $discardIds = $builder->model->newQuery()
            ->select($qualifiedKeyName)
            ->leftJoin(DB::raw('('.$sub->getQuery()->toSql().') as '. DB::connection()->getTablePrefix() .'sub'), $subQualifiedKeyName, '=', $qualifiedKeyName)
            ->addBinding($sub->getQuery()->getBindings(), 'join')
            ->whereIn($qualifiedKeyName, $searchResults)
            ->whereNull($subQualifiedKeyName)
            ->pluck($builder->model->getKeyName());

        // returns values of $results['ids'] that are not part of $discardIds
        return collect($searchResults)->diff($discardIds);
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }

    /**
     * Determine if soft delete is active and depending on state return the
     * appropriate builder.
     *
     * @param  Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return Builder
     */
    private function handleSoftDeletes($builder, $model)
    {
        // remove where statement for __soft_deleted when soft delete is not active
        // does not show soft deleted items when trait is attached to model and
        // config('scout.soft_delete') is false
        if (!$this->usesSoftDelete($model) || !config('scout.soft_delete', true)) {
            unset($this->builder->wheres['__soft_deleted']);
            return $builder;
        }

        /**
         * Use standard behaviour of Laravel Scout builder class to support soft deletes.
         *
         * When no __soft_deleted statement is given return all entries
         */
        if (!in_array('__soft_deleted', $this->builder->wheres)) {
            return $builder->withTrashed();
        }

        /**
         * When __soft_deleted is 1 then return only soft deleted entries
         */
        if ($this->builder->wheres['__soft_deleted']) {
            $builder = $builder->onlyTrashed();
        }

        /**
         * Returns all undeleted entries, default behaviour
         */
        unset($this->builder->wheres['__soft_deleted']);
        return $builder;
    }

    /**
     * Apply where statements as constraints to the query builder.
     *
     * @param Builder $builder
     * @return \Illuminate\Support\Collection
     */
    private function applyWheres($builder)
    {
        // iterate over given where clauses
        return collect($this->builder->wheres)->map(function ($value, $key) {
            // for reduce function combine key and value into array
            return [$key, $value];
        })->reduce(function ($builder, $where) {
            // separate key, value again
            list($key, $value) = $where;
            return $builder->where($key, $value);
        }, $builder);
    }

    /**
     * Apply order by statements as constraints to the query builder.
     *
     * @param Builder $builder
     * @return \Illuminate\Support\Collection
     */
    private function applyOrders($builder)
    {
        //iterate over given orderBy clauses - should be only one
        return collect($this->builder->orders)->map(function ($value, $key) {
            // for reduce function combine key and value into array
            return [$value["column"], $value["direction"]];
        })->reduce(function ($builder, $orderBy) {
            // separate key, value again
            list($column, $direction) = $orderBy;
            return $builder->orderBy($column, $direction);
        }, $builder);
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $indexName   = $model->searchableAs();
        $pathToIndex = $this->dbsearch->config['storage']."/{$indexName}.index";
        if (file_exists($pathToIndex)) {
            unlink($pathToIndex);
        }
    }
}
