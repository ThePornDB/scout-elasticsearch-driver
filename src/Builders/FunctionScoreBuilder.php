<?php

namespace ScoutElastic\Builders;

use ScoutElastic\Payloads\RawPayload;

class FunctionScoreBuilder
{
    private ?string $boost_mode = null;
    private array $field_value_factory = [];
    private array $functions = [];
    private ?float $max_boost = null;
    private ?float $min_score = null;
    private array $random_score = [];
    private ?string $score_mode = null;
    private array $script_score = [];
    private ?float $weight = null;

    public function buildPayload(): RawPayload
    {
        $payload = new RawPayload();
        $payload->setIfNotNull('score_mode', $this->score_mode)
            ->setIfNotNull('boost_mode', $this->boost_mode)
            ->setIfNotEmpty('functions', $this->functions)
            ->setIfNotEmpty('script_score', $this->script_score)
            ->setIfNotEmpty('random_score', $this->random_score)
            ->setIfNotEmpty('field_value_factor', $this->field_value_factory)
            ->setIfNotNull('max_boost', $this->max_boost)
            ->setIfNotNull('min_score', $this->min_score)
            ->setIfNotNull('weight', $this->weight);

        return $payload;
    }

    public function getBoostMode(): ?string
    {
        return $this->boost_mode;
    }

    /**
     * @return mixed[]
     */
    public function getFieldValueFactory(): array
    {
        return $this->field_value_factory;
    }

    /**
     * @return mixed[]
     */
    public function getFunctions(): array
    {
        return $this->functions;
    }

    public function getMaxBoost(): ?float
    {
        return $this->max_boost;
    }

    public function getMinScore(): ?float
    {
        return $this->min_score;
    }

    /**
     * @return mixed[]
     */
    public function getRandomScore(): array
    {
        return $this->random_score;
    }

    public function getScoreMode(): ?string
    {
        return $this->score_mode;
    }

    /**
     * @return mixed[]
     */
    public function getScriptScore(): array
    {
        return $this->script_score;
    }

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function setBoostMode(string $boost_mode): FunctionScoreBuilder
    {
        $this->boost_mode = $boost_mode;
        return $this;
    }

    /**
     * @param mixed[] $field_value_factory
     */
    public function setFieldValueFactory(array $field_value_factory): FunctionScoreBuilder
    {
        $this->field_value_factory = $field_value_factory;
        return $this;
    }

    /**
     * @param mixed[] $functions
     */
    public function setFunctions(array $functions): FunctionScoreBuilder
    {
        $this->functions = $functions;
        return $this;
    }

    public function setMaxBoost(?float $max_boost): FunctionScoreBuilder
    {
        $this->max_boost = $max_boost;
        return $this;
    }

    public function setMinScore(?float $min_score): FunctionScoreBuilder
    {
        $this->min_score = $min_score;
        return $this;
    }

    /**
     * @param mixed[] $random_score
     */
    public function setRandomScore(array $random_score): FunctionScoreBuilder
    {
        $this->random_score = $random_score;
        return $this;
    }

    public function setScoreMode(string $score_mode): FunctionScoreBuilder
    {
        $this->score_mode = $score_mode;
        return $this;
    }

    /**
     * @param mixed[] $script_score
     */
    public function setScriptScore(array $script_score): FunctionScoreBuilder
    {
        $this->script_score = $script_score;
        return $this;
    }

    public function setWeight(?float $weight): FunctionScoreBuilder
    {
        $this->weight = $weight;
        return $this;
    }
}
