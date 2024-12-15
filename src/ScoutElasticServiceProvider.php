<?php

namespace ScoutElastic;

use Laravel\Scout\EngineManager;
use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Config;
use ScoutElastic\Indexers\BulkIndexer;
use Illuminate\Support\ServiceProvider;
use Elastic\Elasticsearch\ClientBuilder;
use ScoutElastic\Indexers\SingleIndexer;
use ScoutElastic\Console\SearchRuleMakeCommand;
use ScoutElastic\Console\ElasticIndexDropCommand;
use ScoutElastic\Console\AggregateRuleMakeCommand;
use ScoutElastic\Console\ElasticIndexCreateCommand;
use ScoutElastic\Console\ElasticIndexUpdateCommand;
use ScoutElastic\Console\ElasticCompareModelCommand;
use ScoutElastic\Console\ElasticMigrateModelCommand;
use ScoutElastic\Console\ElasticUpdateMappingCommand;
use ScoutElastic\Console\IndexConfiguratorMakeCommand;
use Illuminate\Contracts\Container\BindingResolutionException;

class ScoutElasticServiceProvider extends ServiceProvider
{
    /**
     * Boot the service provider.
     *
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/scout_elastic.php' => $this->app->configPath('scout_elastic.php'),
        ]);

        $this->commands([
            // make commands
            IndexConfiguratorMakeCommand::class,
            AggregateRuleMakeCommand::class,
            SearchRuleMakeCommand::class,

            // elastic commands
            ElasticIndexCreateCommand::class,
            ElasticIndexUpdateCommand::class,
            ElasticIndexDropCommand::class,
            ElasticUpdateMappingCommand::class,
            ElasticMigrateModelCommand::class,
            ElasticCompareModelCommand::class,
        ]);

        $this
            ->app
            ->make(EngineManager::class)
            ->extend('elastic', function (): ElasticEngine {
                $indexerType = Config::get('scout_elastic.indexer', 'single');
                $updateMapping = Config::get('scout_elastic.update_mapping', true);

                return match ($indexerType) {
                    'bulk' => new ElasticEngine(new BulkIndexer(), $updateMapping),
                    default => new ElasticEngine(new SingleIndexer(), $updateMapping),
                };
            });
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this
            ->app
            ->singleton('scout_elastic.client', function (): Client {
                $config = Config::get('scout_elastic.client');

                return ClientBuilder::fromConfig($config);
            });
    }
}
