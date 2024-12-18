<?php

namespace ScoutElastic;

use stdClass;
use Laravel\Scout\Builder;
use Illuminate\Support\Arr;
use Laravel\Scout\Engines\Engine;
use ScoutElastic\Payloads\RawPayload;
use Illuminate\Support\LazyCollection;
use ScoutElastic\Payloads\TypePayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use ScoutElastic\Facades\ElasticClient;
use ScoutElastic\Builders\FilterBuilder;
use ScoutElastic\Builders\SearchBuilder;
use Illuminate\Database\Eloquent\Collection;
use ScoutElastic\Interfaces\IndexerInterface;

class ElasticEngine extends Engine
{
    /**
     * The indexer interface.
     */
    protected IndexerInterface $indexer;
    /**
     * The updated mappings.
     */
    protected static array $updatedMappings = [];
    /**
     * Should the mapping be updated.
     */
    protected bool $updateMapping = false;

    /**
     * ElasticEngine constructor.
     *
     * @param  bool $updateMapping
     * @return void
     */
    public function __construct(IndexerInterface $indexer, $updateMapping)
    {
        $this->indexer = $indexer;

        $this->updateMapping = $updateMapping;
    }

    /**
     * Build the payload collection.
     * @param Builder|FilterBuilder|SearchBuilder $builder
     */
    public function buildSearchQueryPayloadCollection(
        Builder $builder,
        array   $options = []
    ): \Illuminate\Support\Collection {
        $payloadCollection = collect();

        if ($builder instanceof SearchBuilder) {
            $searchRules = $builder->rules ?: $builder->model->getSearchRules();

            foreach ($searchRules as $rule) {
                $payload = new TypePayload($builder->model);

                if (is_callable($rule)) {
                    $payload->setIfNotEmpty('body.query.bool', call_user_func($rule, $builder));
                } else {
                    /** @var SearchRule $ruleEntity */
                    $ruleEntity = new $rule($builder);

                    if ($ruleEntity->isApplicable()) {
                        $payload->setIfNotEmpty('body.query.bool', $ruleEntity->buildQueryPayload());

                        if ($options['highlight'] ?? true) {
                            $payload->setIfNotEmpty('body.highlight', $ruleEntity->buildHighlightPayload());
                        }
                    } else {
                        continue;
                    }
                }

                $payloadCollection->push($payload);
            }
        } else {
            $payload = (new TypePayload($builder->model))
                ->setIfNotEmpty('body.query.bool.must.match_all', new stdClass());

            $payloadCollection->push($payload);
        }

        return $payloadCollection->map(function (TypePayload $payload) use ($builder, $options) {
            $payload
                ->setIfNotEmpty('body._source', $builder->select)
                ->setIfNotEmpty('body.collapse.field', $builder->collapse)
                ->setIfNotEmpty('body.sort', $builder->orders)
                ->setIfNotEmpty('body.explain', $options['explain'] ?? null)
                ->setIfNotEmpty('body.profile', $options['profile'] ?? null)
                ->setIfNotEmpty('body.aggs', $builder->aggregates)
                ->setIfNotEmpty('body.min_score', $builder->minScore)
                ->setIfNotNull('body.from', $builder->offset)
                ->setIfNotNull('body.size', $builder->limit);

            if ($builder->withTotalHits) {
                $payload->set('body.track_total_hits', true);
            }

            foreach ($builder->wheres as $clause => $filters) {
                $clauseKey = 'body.query.bool.filter.bool.' . $clause;

                $clauseValue = array_merge(
                    $payload->get($clauseKey, []),
                    $filters
                );

                $payload->setIfNotEmpty($clauseKey, $clauseValue);
            }

            if ($builder->functionScoreBuilder !== null) {
                $functionPayload = $builder->functionScoreBuilder->buildPayload();
                $functionPayload->set('query', $payload->get('body.query'));
                $payload->unset('body.query');
                $payload->set('body.query.function_score', $functionPayload->get());
            }

            return $payload->get();
        });
    }

    /**
     * Return the number of documents found.
     */
    public function count(Builder $builder): int
    {
        $count = 0;

        $this
            ->buildSearchQueryPayloadCollection($builder, ['highlight' => false])
            ->each(function ($payload) use (&$count) {
                $result = ElasticClient::count($payload);

                $count = $result['count'];

                if ($count > 0) {
                    return false;
                }
            });

        return $count;
    }

    /**
     * {@inheritDoc}
     */
    public function createIndex($name, array $options = []): void
    {
        $payload = (new RawPayload())
            ->set('index', $name)
            ->get();

        ElasticClient::indices()
            ->create($payload);
    }

    /**
     * {@inheritDoc}
     */
    public function delete($models): void
    {
        $this->indexer->delete($models);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteIndex($name): void
    {
        $payload = (new RawPayload())
            ->set('index', $name)
            ->get();

        ElasticClient::indices()
            ->delete($payload);
    }

    /**
     * Explain the search.
     *
     * @return array|mixed
     */
    public function explain(Builder $builder)
    {
        return $this->performSearch($builder, [
            'explain' => true,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function flush($model): void
    {
        $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();

        $query
            ->orderBy($model->getScoutKeyName())
            ->unsearchable();
    }

    /**
     * {@inheritDoc}
     * @return int|mixed
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total']['value'] ?? 0;
    }

    /**
     * {@inheritDoc}
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        if ($this->getTotalCount($results) === 0) {
            return LazyCollection::make();
        }

        $scoutKeyName = $model->getScoutKeyName();

        $columns = Arr::get($results, '_payload.body._source');

        if (is_null($columns)) {
            $columns = ['*'];
        } else {
            $columns[] = $scoutKeyName;
        }

        $ids = $this->mapIds($results, $scoutKeyName)->all();

        $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();

        $models = $query
            ->whereIn($scoutKeyName, $ids)
            ->when($builder->queryCallback, fn ($query, $callback) => $callback($query))
            ->get($columns)
            ->keyBy($scoutKeyName);

        $withScores = $builder->withScores;

        $values = LazyCollection::make($results['hits']['hits'])
            ->map(function ($hit) use ($models, $withScores) {
                $id = $hit['_id'];

                if (isset($models[$id])) {
                    $model = $models[$id];

                    if ($withScores && isset($hit['_score'])) {
                        $model->_score = $hit['_score'];
                    }

                    if (isset($hit['highlight'])) {
                        $model->highlight = new Highlight($hit['highlight']);
                    }

                    if (isset($hit['sort'])) {
                        $model->sortPayload = $hit['sort'];
                    }

                    return $model;
                }
            })
            ->filter()
            ->values();

        return LazyCollection::wrap($values);
    }

    /**
     * {@inheritDoc}
     */
    public function map(Builder $builder, $results, $model): Collection
    {
        if ($this->getTotalCount($results) === 0) {
            return Collection::make();
        }

        $scoutKeyName = $model->getScoutKeyName();

        $columns = Arr::get($results, '_payload.body._source');

        if (is_null($columns)) {
            $columns = ['*'];
        } else {
            $columns[] = $scoutKeyName;
        }

        $ids = $this->mapIds($results, $scoutKeyName)->all();

        $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();

        $models = $query
            ->whereIn($scoutKeyName, $ids)
            ->when($builder->queryCallback, fn ($query, $callback) => $callback($query))
            ->get($columns)
            ->keyBy($scoutKeyName);

        $withScores = $builder->withScores;

        $values = Collection::make($results['hits']['hits'])
            ->map(function ($hit) use ($models, $withScores) {
                $id = $hit['_id'];

                if (isset($models[$id])) {
                    $model = $models[$id];

                    if ($withScores && isset($hit['_score'])) {
                        $model->_score = $hit['_score'];
                    }

                    if (isset($hit['highlight'])) {
                        $model->highlight = new Highlight($hit['highlight']);
                    }

                    if (isset($hit['sort'])) {
                        $model->sortPayload = $hit['sort'];
                    }

                    return $model;
                }
            })
            ->filter()
            ->values();

        return $values instanceof Collection ? $values : Collection::make($values);
    }

    /**
     * {@inheritDoc}
     */
    public function mapIds($results, string $key = 'id'): \Illuminate\Support\Collection
    {
        return collect($results['hits']['hits'])->pluck('_source.' . $key);
    }

    /**
     * {@inheritDoc}
     * @return array<string, mixed>|mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $builder
            ->from(($page - 1) * $perPage)
            ->take($perPage);

        return $this->performSearch($builder);
    }

    /**
     * Profile the search.
     *
     * @return array|mixed
     */
    public function profile(Builder $builder)
    {
        return $this->performSearch($builder, [
            'profile' => true,
        ]);
    }

    /**
     * {@inheritDoc}
     * @return array<string, mixed>|mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder);
    }

    /**
     * Make a raw search.
     *
     * @param mixed[] $query
     */
    public function searchRaw(Model $model, array $query)
    {
        $payload = (new TypePayload($model))
            ->setIfNotEmpty('body', $query)
            ->get();

        return ElasticClient::search($payload);
    }

    /**
     * {@inheritDoc}
     */
    public function update($models): void
    {
        if ($this->updateMapping) {
            $self = $this;

            $models->each(function ($model) use ($self) {
                $modelClass = get_class($model);

                if (in_array($modelClass, $self::$updatedMappings)) {
                    return true;
                }

                Artisan::call(
                    'elastic:update-mapping',
                    ['model' => $modelClass]
                );

                $self::$updatedMappings[] = $modelClass;
            });
        }

        $this
            ->indexer
            ->update($models);
    }

    /**
     * Perform the search.
     *
     * @return array|mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        if ($builder->callback !== null) {
            return call_user_func(
                $builder->callback,
                ElasticClient::getFacadeRoot(),
                $builder->query,
                $options
            );
        }

        $results = [];

        $this
            ->buildSearchQueryPayloadCollection($builder, $options)
            ->each(function ($payload) use (&$results) {
                $http = ElasticClient::search($payload);
                $results['_payload'] = $payload;
                $results = array_merge($results, $http->asArray());

                if ($this->getTotalCount($results) > 0) {
                    return false;
                }
            });

        return $results;
    }
}
