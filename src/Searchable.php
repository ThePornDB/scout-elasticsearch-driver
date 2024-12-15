<?php

namespace ScoutElastic;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Eloquent\Model;
use ScoutElastic\Builders\FilterBuilder;
use ScoutElastic\Builders\SearchBuilder;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Searchable as SourceSearchable;
use Illuminate\Support\Collection as BaseCollection;
use ScoutElastic\Interfaces\IndexConfiguratorInterface;

trait Searchable
{
    use SourceSearchable {
        SourceSearchable::getScoutKeyName as sourceGetScoutKeyName;
    }

    /**
     * The score returned from elasticsearch.
     */
    public ?float $_score = null;

    /**
     * The highlights.
     */
    private ?Highlight $highlight = null;

    /**
     * @return mixed|string[]
     */
    public function getAggregateRules()
    {
        return isset($this->aggregateRules) && count($this->aggregateRules) > 0 ? $this->aggregateRules : [AggregateRule::class];
    }

    /**
     * Get the highlight attribute.
     *
     * @return null|Highlight
     */
    public function getHighlightAttribute()
    {
        return $this->highlight;
    }

    /**
     * Get the index configurator.
     *
     * @throws Exception
     */
    public function getIndexConfigurator(): IndexConfiguratorInterface
    {
        static $indexConfigurator;

        if (!$indexConfigurator) {
            if (!isset($this->indexConfigurator) || empty($this->indexConfigurator)) {
                throw new Exception(sprintf(
                    'An index configurator for the %s model is not specified.',
                    self::class
                ));
            }

            $indexConfiguratorClass = $this->indexConfigurator;
            $indexConfigurator = new $indexConfiguratorClass();
        }

        return $indexConfigurator;
    }

    /**
     * Get the mapping.
     *
     * @return array
     */
    public function getMapping()
    {
        $mapping = $this->mapping ?? [];

        if ($this::usesSoftDelete() && Config::get('scout.soft_delete', false)) {
            Arr::set($mapping, 'properties.__soft_deleted', ['type' => 'integer']);
        }

        return $mapping;
    }

    /**
     * Get the key name used to index the model.
     *
     */
    public function getScoutKeyName()
    {
        return $this->getKeyName();
    }

    /**
     * Get the search rules.
     *
     * @return array
     */
    public function getSearchRules()
    {
        return isset($this->searchRules) && count($this->searchRules) > 0 ? $this->searchRules : [SearchRule::class];
    }

    public function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with($this->searchableWith());
    }

    public function makeSearchableUsing(BaseCollection $models)
    {
        return $models->each(fn (Model $model) => $model->loadMissing($model->searchableWith()));
    }

    /**
     * Execute the search.
     *
     * @param  null|callable                    $callback
     * @return FilterBuilder|SearchBuilder|void
     */
    public static function search(string $query, $callback = null)
    {
        $softDelete = static::usesSoftDelete() && Config::get('scout.soft_delete', false);

        if ($query === '*') {
            return new FilterBuilder(new static(), $callback, $softDelete);
        }

        return new SearchBuilder(new static(), $query, $callback, $softDelete);
    }

    public function searchableWith(): array
    {
        return [];
    }

    /**
     * Execute a raw search.
     *
     * @return array
     */
    public static function searchRaw(array $query)
    {
        $model = new static();

        return $model->searchableUsing()
            ->searchRaw($model, $query);
    }

    /**
     * Set the highlight attribute.
     *
     */
    public function setHighlightAttribute(Highlight $value): void
    {
        $this->highlight = $value;
    }
}
