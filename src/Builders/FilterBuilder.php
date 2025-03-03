<?php

namespace ScoutElastic\Builders;

use Closure;
use Exception;
use Laravel\Scout\Builder;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use ScoutElastic\ElasticEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Support\Arrayable;
use ScoutElastic\Interfaces\AggregateRuleInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as ModelCollection;

/**
 * @method ElasticEngine engine()
 */
class FilterBuilder extends Builder
{
    /**
     * List of operators that are allowed in ES
     */
    private const OPERATORS = [
        '=',
        '>',
        '<',
        '!=',
        '>=',
        '<=',
        '<>',
    ];

    public array $aggregates = [];

    /**
     * The collapse parameter.
     */
    public ?string $collapse = null;

    public ?FunctionScoreBuilder $functionScoreBuilder = null;

    /**
     * The min_score parameter.
     */
    public ?float $minScore = null;

    /**
     * The offset.
     */
    public ?int $offset = null;
    public $scriptScoreBuilder;

    /**
     * The select array.
     */
    public array $select = [];
    /**
     * The condition array.
     *
     * @var array
     */
    public $wheres = [
        'must' => [],
        'must_not' => [],
    ];

    /**
     * The with array.
     *
     * @var array|string
     */
    public $with;

    /**
     * Determines if the score should be returned with the model.
     *
     * @var bool - false
     */
    public bool $withScores = false;

    public bool $withTotalHits = false;

    /**
     * FilterBuilder constructor.
     *
     * @param  null|callable $callback
     * @param  bool          $softDelete
     * @return void
     */
    public function __construct(Model $model, $callback = null, $softDelete = false)
    {
        $this->model = $model;
        $this->callback = $callback;

        if ($softDelete) {
            $this->wheres['must'][] = [
                'term' => [
                    '__soft_deleted' => 0,
                ],
            ];
        }
    }

    /**
     * Adds rule to the aggregate rules of the builder.
     * @param AggregateRuleInterface|Closure $rule
     */
    public function addAggregate($rule): self
    {
        if ($rule instanceof AggregateRuleInterface) {
            $ruleEntity = new $rule();
            if ($aggregatePayload = $ruleEntity->buildAggregatePayload()) {
                $this->aggregates = array_merge($this->aggregates, $aggregatePayload);
            }

            return $this;
        }

        if ($rule instanceof Closure) {
            $ruleEntity = call_user_func($rule);
            if (is_array($ruleEntity)) {
                $this->aggregates = array_merge($this->aggregates, $ruleEntity);
            }
        }

        return $this;
    }

    /**
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/returning-only-agg-results.html
     *
     * @return $this
     */
    public function aggregate(int $size = 0): array
    {
        $this->take($size);

        return $this->engine()->search($this);
    }

    /**
     * Build the payload.
     *
     * @return mixed[]
     */
    public function buildPayload(): Collection
    {
        return $this
            ->engine()
            ->buildSearchQueryPayloadCollection($this);
    }

    /**
     * Collapse by a field.
     */
    public function collapse(string $field): self
    {
        $this->collapse = $field;

        return $this;
    }

    /**
     * Get the count.
     */
    public function count(): int
    {
        return $this
            ->engine()
            ->count($this);
    }

    /**
     * Explain the request.
     *
     * @return mixed[]
     */
    public function explain(): array
    {
        return $this
            ->engine()
            ->explain($this);
    }

    /**
     * Set the query offset.
     */
    public function from(int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function get(): ModelCollection
    {
        $collection = parent::get();

        if (isset($this->with) && $collection->count() > 0) {
            $collection->load($this->with);
        }

        return $collection;
    }

    public function getRaw(): Collection
    {
        $results = $this->engine()->search($this);

        if ($results['hits']['total'] === 0) {
            return new Collection();
        }

        return (new Collection($results['hits']['hits']))
            ->map(fn ($row) => array_merge(
                $row['_source'],
                [
                    'score' => $row['_score'],
                ]
            ));
    }

    /**
     * Bypasses Eloquent and directly hydrates the Models
     */
    public function hydrate(): Collection
    {
        $className = get_class($this->model);

        return $this->getRaw()->map(fn ($row) => (new $className())->forceFill($row));
    }

    /**
     * Set the min_score on the filter.
     */
    public function minScore(float $score): self
    {
        $this->minScore = $score;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function onlyTrashed()
    {
        return tap($this->withTrashed(), function (): void {
            $this->wheres['must'][] = ['term' => ['__soft_deleted' => 1]];
        });
    }

    /**
     * Add a orderBy clause.
     *
     * @param string $field
     * @param string $direction
     */
    public function orderBy($field, $direction = 'asc'): self
    {
        $this->orders[] = [
            $field => strtolower($direction) === 'asc' ? 'asc' : 'desc',
        ];

        return $this;
    }

    /**
     * Add a raw order clause.
     */
    public function orderRaw(array $payload): self
    {
        $this->orders[] = $payload;

        return $this;
    }

    /**
     * @param  null                                     $operator
     * @param  null                                     $value
     * @return $this|\Illuminate\Database\Query\Builder
     */
    public function orWhere(
        $column,
        ?string $operator = null,
        ?string $value = null
    ): FilterBuilder {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        return $this->where($column, $operator, $value, 'should');
    }

    public function orWhereBetween(string $field, array $value): self
    {
        return $this->whereBetween($field, $value);
    }

    public function orWhereExists(string $field): self
    {
        return $this->whereExists($field, 'should');
    }

    public function orWhereGeoBoundingBox(string $field, array $value): self
    {
        return $this->whereGeoBoundingBox($field, $value, 'should');
    }

    public function orWhereGeoDistance(string $field, $value, $distance): self
    {
        return $this->whereGeoDistance($field, $value, $distance, 'should');
    }

    public function orWhereGeoPolygon(string $field, array $points): self
    {
        return $this->whereGeoPolygon($field, $points, 'should');
    }

    public function orWhereGeoShape(string $field, array $shape, string $relation = 'INTERSECTS'): self
    {
        return $this->whereGeoShape($field, $shape, $relation, 'should');
    }

    public function orWhereIn($field, array $value): self
    {
        return $this->whereIn($field, $value, 'should');
    }

    public function orWhereMatch(string $field, string $value, array $parameters = []): self
    {
        return $this->whereMatch($field, $value, 'should', $parameters);
    }

    public function orWhereNotBetween(string $field, array $value): self
    {
        return $this->whereNotBetween($field, $value, 'should');
    }

    /**
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-exists-query.html Exists query
     *
     * @return $this|FilterBuilder
     */
    public function orWhereNotExists(string $field): self
    {
        return $this->whereNotExists($field, 'should');
    }

    public function orWhereNotIn(string $field, array $value): self
    {
        return $this->whereNotIn($field, $value, 'should');
    }

    public function orWhereNotMatch(string $field, string $value): self
    {
        return $this->whereNotMatch($field, $value, 'should');
    }

    public function orWhereRegexp(string $field, string $value, string $flags = 'ALL'): self
    {
        return $this->whereRegexp($field, $value, $flags, 'should');
    }

    public function orWhereWildcard(string $field, string $value, array $parameters = []): self
    {
        return $this->whereWildcard($field, $value, 'should', $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function paginate($perPage = null, $pageName = 'page', $page = null): LengthAwarePaginator
    {
        $paginator = parent::paginate($perPage, $pageName, $page);

        if (isset($this->with) && $paginator->total() > 0) {
            $paginator
                ->getCollection()
                ->load($this->with);
        }

        return $paginator;
    }

    /**
     * Profile the request.
     *
     * @return mixed[]
     */
    public function profile(): array
    {
        return $this
            ->engine()
            ->profile($this);
    }

    /**
     * Select one or many fields.
     *
     */
    public function select($fields): self
    {
        $this->select = array_merge(
            $this->select,
            Arr::wrap($fields)
        );

        return $this;
    }

    public function setNegativeCondition($condition, string $boolean = 'must'): void
    {
        if ($boolean == 'should') {
            $cond['bool']['must_not'][] = $condition;

            $this->wheres[$boolean][] = $cond;
        } else {
            $this->wheres['must_not'][] = $condition;
        }
    }

    /**
     * @return $this
     */
    public function sum(string $field): float
    {
        $this->aggregates = [
            $field => [
                'sum' => [
                    'field' => $field,
                ],
            ],
        ];

        $result = $this->aggregate();

        return $result['aggregations'][$field]['value'];
    }

    /**
     * @return mixed|mixed[]|string
     * @throws Exception
     */
    public function toQuery(bool $json = false)
    {
        $queries = $this->buildPayload()->map(fn ($query) => $query['body']);

        if ($queries->isEmpty()) {
            throw new Exception('no query found');
        }

        if ($queries->count() === 1) {
            return $json ? json_encode($queries->first()) : $queries->first();
        }

        return $json ? $queries->toJson() : $queries->toArray();
    }

    /**
     * Add a where condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-term-query.html Term query
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-range-query.html Range query
     *
     * Supported operators are =, &gt;, &lt;, &gt;=, &lt;=, &lt;&gt;
     *
     * @param  Closure|string      $field
     * @param  string              $boolean
     * @return $this|FilterBuilder
     */
    public function where($field, $operator = null, $value = null, $boolean = 'must'): self
    {
        if ($field instanceof Closure) {
            return $this->whereNested($field, $boolean);
        }

        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        if ($this->invalidOperator($operator)) {
            $value = $operator;
            $operator = '=';
        }

        switch ($operator) {
            case '=':
                $this->wheres[$boolean][] = [
                    'term' => [
                        $field => $value,
                    ],
                ];
                break;

            case '>':
                $this->wheres[$boolean][] = [
                    'range' => [
                        $field => [
                            'gt' => $value,
                        ],
                    ],
                ];
                break;

            case '<':
                $this->wheres[$boolean][] = [
                    'range' => [
                        $field => [
                            'lt' => $value,
                        ],
                    ],
                ];
                break;

            case '>=':
                $this->wheres[$boolean][] = [
                    'range' => [
                        $field => [
                            'gte' => $value,
                        ],
                    ],
                ];
                break;

            case '<=':
                $this->wheres[$boolean][] = [
                    'range' => [
                        $field => [
                            'lte' => $value,
                        ],
                    ],
                ];
                break;

            case '!=':
            case '<>':
                $term = [
                    'term' => [
                        $field => $value,
                    ],
                ];
                $this->setNegativeCondition($term, $boolean);
                break;
        }

        return $this;
    }

    /**
     * Add a whereBetween condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-range-query.html Range query
     */
    public function whereBetween(string $field, array $value, string $boolean = 'must'): self
    {
        $this->wheres[$boolean][] = [
            'range' => [
                $field => [
                    'gte' => $value[0],
                    'lte' => $value[1],
                ],
            ],
        ];

        return $this;
    }

    /**
     * Add a whereExists condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-exists-query.html Exists query
     */
    public function whereExists(string $field, string $boolean = 'must'): self
    {
        $this->wheres[$boolean][] = [
            'exists' => [
                'field' => $field,
            ],
        ];

        return $this;
    }

    /**
     * Add a whereGeoBoundingBox condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-geo-bounding-box-query.html Geo
     *     bounding box query
     */
    public function whereGeoBoundingBox(string $field, array $value, string $boolean = 'must'): self
    {
        $this->wheres[$boolean][] = [
            'geo_bounding_box' => [
                $field => $value,
            ],
        ];

        return $this;
    }

    /**
     * Add a whereGeoDistance condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-geo-distance-query.html Geo
     *     distance query
     *
     * @param array|string $value
     * @param int|string   $distance
     */
    public function whereGeoDistance(string $field, $value, $distance, string $boolean = 'must'): self
    {
        $this->wheres[$boolean][] = [
            'geo_distance' => [
                'distance' => $distance,
                $field => $value,
            ],
        ];

        return $this;
    }

    /**
     * Add a whereGeoPolygon condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-geo-polygon-query.html Geo
     *     polygon query
     */
    public function whereGeoPolygon(string $field, array $points, string $boolean = 'must'): self
    {
        $this->wheres[$boolean][] = [
            'geo_polygon' => [
                $field => [
                    'points' => $points,
                ],
            ],
        ];

        return $this;
    }

    /**
     * Add a whereGeoShape condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-geo-shape-query.html Querying Geo
     *     Shapes
     */
    public function whereGeoShape(
        string $field,
        array  $shape,
        string $relation = 'INTERSECTS',
        string $boolean = 'must'
    ): self {
        $this->wheres[$boolean][] = [
            'geo_shape' => [
                $field => [
                    'shape' => $shape,
                    'relation' => $relation,
                ],
            ],
        ];

        return $this;
    }

    /**
     * Adds Nested query
     */
    public function whereHas(string $path, Closure $callback, string $boolean = 'must'): self
    {
        /** @var $filter FilterBuilder */
        call_user_func($callback, $filter = $this->model::search('*'));

        $payload = $filter->buildPayload();
        $this->wheres[$boolean][] = [
            'nested' => [
                'path' => $path,
                'query' => $payload[0]['body']['query']['bool']['filter'],
            ],
        ];

        return $this;
    }

    /**
     * Add a whereIn condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-terms-query.html Terms query
     *
     * @param string          $field
     * @param Arrayable|array $values
     */
    public function whereIn($field, $values, string $boolean = 'must'): self
    {
        $this->wheres[$boolean][] = [
            'terms' => [
                $field => $values,
            ],
        ];

        return $this;
    }

    /**
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-match-query.html Match query
     */
    public function whereMatch(string $field, string $value, string $boolean = 'must', array $parameters = []): self
    {
        $parameters = $this->validatedParameters($parameters, [
            'analyzer',
            'auto_generate_synonyms_phrase_query',
            'fuzziness',
            'max_expansions',
            'prefix_length',
            'fuzzy_transpositions',
            'fuzzy_rewrite',
            'lenient',
            'operator',
            'minimum_should_match',
            'zero_terms_query',
        ]);

        $this->wheres[$boolean][] = [
            'match' => [
                $field => array_merge($parameters, [
                    'query' => $value,
                ]),
            ],
        ];

        return $this;
    }

    /**
     * Runs Match against multiple fields
     *
     * @param string[] $fields
     */
    public function whereMultiMatch(array $fields, string $value, string $boolean = 'must', array $parameters = []): self
    {
        $parameters = $this->validatedParameters($parameters, [
            'analyzer',
            'auto_generate_synonyms_phrase_query',
            'fuzziness',
            'max_expansions',
            'prefix_length',
            'fuzzy_transpositions',
            'fuzzy_rewrite',
            'lenient',
            'operator',
            'minimum_should_match',
            'zero_terms_query',
            'type',
        ]);

        foreach ($fields as $field) {
            if (!is_string($field)) {
                throw new Exception('Invalid field in multi match');
            }
        }

        $this->wheres[$boolean][] = [
            'multi_match' => array_merge($parameters, [
                'fields' => $fields,
                'query' => $value,
            ]),
        ];

        return $this;
    }

    public function whereNested(Closure $callback, string $boolean = 'must'): self
    {
        /** @var $filter FilterBuilder */
        call_user_func($callback, $filter = $this->model::search('*'));

        $payload = $filter->buildPayload();
        $this->wheres[$boolean][] = $payload[0]['body']['query']['bool']['filter'];

        return $this;
    }

    /**
     * Add a whereNotBetween condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-range-query.html Range query
     */
    public function whereNotBetween(string $field, array $value, string $boolean = 'must'): self
    {
        $term = [
            'range' => [
                $field => [
                    'gte' => $value[0],
                    'lte' => $value[1],
                ],
            ],
        ];
        $this->setNegativeCondition($term, $boolean);

        return $this;
    }

    /**
     * Add a whereNotExists condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-exists-query.html Exists query
     */
    public function whereNotExists(string $field, string $boolean = 'must'): self
    {
        $term = [
            'exists' => [
                'field' => $field,
            ],
        ];
        $this->setNegativeCondition($term, $boolean);

        return $this;
    }

    /**
     * Add a whereNotIn condition.
     * @param string          $field
     * @param Arrayable|array $values
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-terms-query.html Terms query
     */
    public function whereNotIn($field, $values, string $boolean = 'must'): self
    {
        $term = [
            'terms' => [
                $field => $values,
            ],
        ];
        $this->setNegativeCondition($term, $boolean);

        return $this;
    }

    /**
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-match-query.html Match query
     */
    public function whereNotMatch(string $field, string $value, string $boolean = 'must'): self
    {
        $term = [
            'match' => [
                $field => $value,
            ],
        ];
        $this->setNegativeCondition($term, $boolean);

        return $this;
    }

    /**
     * Add a whereRegexp condition.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-regexp-query.html Regexp query
     */
    public function whereRegexp(string $field, string $value, string $flags = 'ALL', string $boolean = 'must'): self
    {
        $this->wheres[$boolean][] = [
            'regexp' => [
                $field => [
                    'value' => $value,
                    'flags' => $flags,
                ],
            ],
        ];

        return $this;
    }

    /**
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-wildcard-query.html
     */
    public function whereWildcard(string $field, string $value, string $boolean = 'must', array $parameters = []): self
    {
        $parameters = $this->validatedParameters($parameters, [
            'boost',
            'case_insensitive',
            'rewrite',
        ]);

        $this->wheres[$boolean][] = [
            'wildcard' => [
                $field => array_merge($parameters, [
                    'value' => $value,
                ]),
            ],
        ];

        return $this;
    }

    /**
     * Eager load some some relations.
     *
     * @param array|string $relations
     */
    public function with($relations): self
    {
        $this->with = $relations;

        return $this;
    }

    /**
     * Adds function score to the query
     */
    public function withFunctionScore(callable $callback): self
    {
        $builder = new FunctionScoreBuilder();
        $callback($builder);
        $this->functionScoreBuilder = $builder;

        return $this;
    }

    /**
     * Set the withScores property.
     *
     * @param bool $withScores - true
     */
    public function withScores(bool $withScores = true): self
    {
        $this->withScores = $withScores;

        return $this;
    }

    /**
     * Adds function score to the query
     */
    public function withScriptScore(callable $callback): self
    {
        $builder = new FunctionScoreBuilder();
        $callback($builder);
        $this->functionScoreBuilder = $builder;

        return $this;
    }

    public function withTotalHits(bool $withTotalHits = true): self
    {
        $this->withTotalHits = $withTotalHits;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function withTrashed(): self
    {
        $this->wheres['must'] = collect($this->wheres['must'])
            ->filter(fn ($item): bool => Arr::get($item, 'term.__soft_deleted') !== 0)
            ->values()
            ->all();

        return $this;
    }

    /**
     * Determine if the given operator is supported.
     */
    protected function invalidOperator(string $operator): bool
    {
        return !in_array(strtolower($operator), self::OPERATORS, true);
    }

    /**
     * Determine if the given operator and value combination is legal.
     *
     * Prevents using Null values with invalid operators.
     *
     */
    protected function invalidOperatorAndValue(?string $operator, $value): bool
    {
        return is_null($value) && in_array($operator, self::OPERATORS) && !in_array($operator, ['=', '!=', '<>']);
    }

    /**
     * Prepare the value and operator for a where clause.
     * @param                           $value    mixed|null
     * @param                           $operator mixed
     * @throws InvalidArgumentException
     */
    protected function prepareValueAndOperator($value, $operator, bool $useDefault = false): array
    {
        if ($useDefault) {
            return [$operator, '='];
        }

        if ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    private function validatedParameters(array $parameters, array $validParameters): array
    {
        $validated = [];
        foreach (array_keys($parameters) as $key) {
            if (!in_array($key, $validParameters)) {
                Log::debug(sprintf('Invalid Elasticsearch parameter: %s', $key));

                continue;
            }

            $validated[$key] = $parameters[$key];
        }

        return $validated;
    }
}
