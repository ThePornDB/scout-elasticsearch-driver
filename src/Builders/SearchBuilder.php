<?php

namespace ScoutElastic\Builders;

use Illuminate\Database\Eloquent\Model;

class SearchBuilder extends FilterBuilder
{
    /**
     * The rules array.
     */
    public array $rules = [];

    /**
     * SearchBuilder constructor.
     *
     * @param  string        $query
     * @param  null|callable $callback
     * @param  bool          $softDelete
     * @return void
     */
    public function __construct(Model $model, $query, $callback = null, $softDelete = false)
    {
        parent::__construct($model, $callback, $softDelete);

        $this->query = $query;
    }

    /**
     * Add a rule.
     *
     * @param callable|string $rule Search rule class name or function
     */
    public function rule($rule): self
    {
        $this->rules[] = $rule;

        return $this;
    }
}
