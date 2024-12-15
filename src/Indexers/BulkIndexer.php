<?php

namespace ScoutElastic\Indexers;

use Exception;
use Illuminate\Support\Arr;
use ScoutElastic\Migratable;
use ScoutElastic\Payloads\RawPayload;
use Illuminate\Support\Facades\Config;
use ScoutElastic\Facades\ElasticClient;
use ScoutElastic\Payloads\IndexPayload;
use Illuminate\Database\Eloquent\Collection;
use ScoutElastic\Interfaces\IndexerInterface;

class BulkIndexer implements IndexerInterface
{
    /**
     * {@inheritDoc}
     */
    public function delete(Collection $models): void
    {
        $model = $models->first();
        $indexConfigurator = $model->getIndexConfigurator();

        $bulkPayload = new IndexPayload($indexConfigurator);

        $models->each(function ($model) use ($bulkPayload): void {
            $actionPayload = (new RawPayload())
                ->set('delete._id', $model->getScoutKey());

            $bulkPayload->add('body', $actionPayload->get());
        });

        if ($documentRefresh = Config::get('scout_elastic.document_refresh')) {
            $bulkPayload->set('refresh', $documentRefresh);
        }

        $bulkPayload->set('client.ignore', 404);

        $rsp = ElasticClient::bulk($bulkPayload->get());
        $this->handleResponse($rsp->asArray());
    }

    /**
     * {@inheritDoc}
     */
    public function update(Collection $models): void
    {
        $model = $models->first();
        $indexConfigurator = $model->getIndexConfigurator();

        $bulkPayload = new IndexPayload($indexConfigurator);

        if (in_array(Migratable::class, class_uses_recursive($indexConfigurator))) {
            $bulkPayload->useAlias('write');
        }

        if ($documentRefresh = Config::get('scout_elastic.document_refresh')) {
            $bulkPayload->set('refresh', $documentRefresh);
        }

        $models->each(function ($model) use ($bulkPayload) {
            if ($model::usesSoftDelete() && Config::get('scout.soft_delete', false)) {
                $model->pushSoftDeleteMetadata();
            }

            $modelData = array_merge(
                $model->toSearchableArray(),
                $model->scoutMetadata()
            );

            if (empty($modelData)) {
                return true;
            }

            $actionPayload = (new RawPayload())
                ->set('index._id', $model->getScoutKey());

            $bulkPayload
                ->add('body', $actionPayload->get())
                ->add('body', $modelData);
        });

        $rsp = ElasticClient::bulk($bulkPayload->get());
        $this->handleResponse($rsp->asArray());
    }

    private function handleResponse(array $response)
    {
        if (Arr::get($response, 'errors') !== true) {
            return;
        }

        $errors = array_filter(
            array_map(function ($row) {
                $operation = array_values($row)[0];

                if (array_key_exists('error', $operation)) {
                    return $operation['error'];
                }

                return null;
            }, $response['items'])
        );

        $exception = null;
        foreach ($errors as $error) {
            $exception = new Exception(
                sprintf(
                    '%s - %s',
                    $error['type'],
                    $error['reason']
                ),
                0,
                $exception
            );
        }

        throw new Exception('ElasticSearch responded with an error', 0, $exception);
    }
}
