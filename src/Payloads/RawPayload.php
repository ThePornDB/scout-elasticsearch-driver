<?php

namespace ScoutElastic\Payloads;

use Illuminate\Support\Arr;

class RawPayload
{
    /**
     * The payload.
     */
    protected array $payload = [];

    /**
     * Add a value.
     *
     */
    public function add(string $key, $value): self
    {
        if (!is_null($key)) {
            $currentValue = Arr::get($this->payload, $key, []);

            if (!is_array($currentValue)) {
                $currentValue = Arr::wrap($currentValue);
            }

            $currentValue[] = $value;

            Arr::set($this->payload, $key, $currentValue);
        }

        return $this;
    }

    /**
     * Add a value if it's not empty.
     *
     */
    public function addIfNotEmpty(string $key, $value): self
    {
        if (empty($value)) {
            return $this;
        }

        return $this->add($key, $value);
    }

    /**
     * Get value.
     *
     * @param null|string $key
     * @param null|mixed  $default
     */
    public function get($key = null, $default = null)
    {
        return Arr::get($this->payload, $key, $default);
    }

    /**
     * Checks that the payload key has a value.
     */
    public function has(string $key): bool
    {
        return Arr::has($this->payload, $key);
    }

    /**
     * Set a value.
     *
     */
    public function set(string $key, $value)
    {
        Arr::set($this->payload, $key, $value);

        return $this;
    }

    /**
     * Set a value if it's not empty.
     *
     */
    public function setIfNotEmpty(string $key, $value): self
    {
        if (empty($value)) {
            return $this;
        }

        return $this->set($key, $value);
    }

    /**
     * Set a value if it's not null.
     *
     */
    public function setIfNotNull(string $key, $value): self
    {
        if (is_null($value)) {
            return $this;
        }

        return $this->set($key, $value);
    }

    /**
     * Unset a value.
     *
     */
    public function unset($key): self
    {
        Arr::forget($this->payload, $key);

        return $this;
    }
}
