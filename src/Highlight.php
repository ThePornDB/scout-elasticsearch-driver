<?php

namespace ScoutElastic;

class Highlight
{
    /**
     * The highlight array.
     */
    private array $highlight = [];

    /**
     * Highlight constructor.
     *
     * @param  mixed[] $highlight
     * @return void
     */
    public function __construct(array $highlight)
    {
        $this->highlight = $highlight;
    }

    /**
     * Get a value.
     *
     * @param  string            $key
     * @return null|mixed|string
     */
    public function __get($key)
    {
        $field = str_replace('AsString', '', $key);

        if (isset($this->highlight[$field])) {
            $value = $this->highlight[$field];

            return $field === $key ? $value : implode(' ', $value);
        }
    }
}
