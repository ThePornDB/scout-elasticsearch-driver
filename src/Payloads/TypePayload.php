<?php

namespace ScoutElastic\Payloads;

use Exception;
use ScoutElastic\Searchable;
use Illuminate\Database\Eloquent\Model;

class TypePayload extends IndexPayload
{
    /**
     * The model.
     */
    protected Model $model;

    /**
     * TypePayload constructor.
     *
     * @return void
     * @throws Exception
     */
    public function __construct(Model $model)
    {
        if (!in_array(Searchable::class, class_uses_recursive($model))) {
            throw new Exception(sprintf(
                'The %s model must use the %s trait.',
                get_class($model),
                Searchable::class
            ));
        }

        $this->model = $model;

        parent::__construct($model->getIndexConfigurator());

        $this->payload['type'] = $model->searchableAs();
        $this->protectedKeys[] = 'type';
    }
}
