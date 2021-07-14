<?php
namespace Jalno\API;

use InvalidArgumentException;
use Jalno\Userpanel\Models\User;
use Illuminate\Support\{Str, Arr};
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\{Model, Builder};
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Auth\{AuthenticationException, Access\AuthorizationException};

abstract class API
{
    protected Container $app;
    protected ?User $user = null;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * 
     */
    public function forUser(?User $user = null): void
    {
        $this->user = $user;
    }

    /**
     * @throws AuthenticationException
     */
    public function requireUser(): void
    {
        if (!$this->user) {
            throw new AuthenticationException();
        }
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function getUserOrFail(): User
    {
        $this->requireUser();

        return $this->user();
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function applyFiltersOnModel(Model $model, array $filters): void {
        $this->applyFilters($model->newQuery(), $filters);
    }

    /**
     * @param array<string,mixed> $filters
     * 
     * @throws ValidationException
     */
    public function applyFiltersOnQuery(Builder $query, array $filters): void
    {
        $query->where(function(Builder $query) use (&$filters) {
            $this->applyFilters($query, $filters);
        });
    }

    /**
     * @param string[] $abilities
     */
    public function requireAnyAbility(array $abilities, bool $allowGuest = false): void
    {
        if (!$this->user) {
            if ($allowGuest) {
                return;
            }
            throw new AuthenticationException();
        }
        if (!$this->user instanceof Authorizable or !$this->user->canAny($abilities)) {
            throw new AuthorizationException();
        }
    }

    public function requireAbility(string $ability, bool $allowGuest = false): void
    {
        if (!$this->user) {
            if ($allowGuest) {
                return;
            }
            throw new AuthenticationException();
        }
        if (!$this->user instanceof Authorizable or !$this->user->can($ability)) {
            throw new AuthorizationException();
        }
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function validateSearchKeys(Model $model, array $filters, ?array $attributes = null): void
    {
        if (!$model instanceof Contracts\ISearchableModel) {
            return;
        }

        if (is_null($attributes)) {
            $attributes = $model->getSearchAttributes();
        }

        foreach ($filters as $key => $item) {
            if ($this->isOperator($key)) {
                $this->validateOperatorValue($key, $item);
                if (is_array($item)) {
                    foreach ($item as $key2 => $value) {
                        if (is_numeric($key2)) {
                            $this->validateSearchKeys($model, $value, $attributes);
                        } else {
                            $this->validateSearchKeys($model, [$key2 => $value], $attributes);
                        }
                    }
                }
            } elseif (Str::contains($key, ":")) {
                $relation = Str::before($key, ":");
                if (!isset($model->$relation) and $model->relationLoaded($relation)) {
                    $this->validateSearchKeys($model->$relation()->getQuery()->getModel(), [Str::after($key, ":") => $item]);
                } elseif (!in_array($key, $attributes)) {
                    throw ValidationException::withMessages([$key => "The {$key} search key is not allowed."]);
                }
            } elseif (!in_array($key, $attributes)) {
                throw ValidationException::withMessages([$key => "The {$key} search key is not allowed."]);
            }
        }
    }

    /**
     * @param array<string,mixed> $filters
     * 
     * @throws ValidationException
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        $model = $query->getModel();

        if ($model instanceof Model) {
            $this->validateSearchKeys($model, $filters);
        }

        /**
         * Make sure all search values has operator key.
         */
        $checkOperators = function(array $filters) use (&$checkOperators) {
            foreach ($filters as $field => $operators) {
                if (is_array($operators)) {
                    $filters[$field] = $checkOperators($operators);
                } elseif (!$this->isOperator($field)) {
                    $filters[$field] = ["eq" => $operators];
                }
            }

            return $filters;
        };

        $filters = $checkOperators($filters);

        /**
         * Make sure 'or' and 'and' operators comming after search keys
         */
        $checkLateOperators = function(array $filters) use (&$checkLateOperators) {
            $lateOperators = [];
            foreach ($filters as $operator => $value) {
                if (in_array($operator, ["and", "or"])) {
                    $lateOperators[$operator] = $value;
                    unset($filters[$operator]);
                } elseif (is_array($value)) {
                    $checkLateOperators($value);
                }
            }
    
            return array_merge($filters, $lateOperators);
        };

        $filters = $checkLateOperators($filters);

        $orAndOperators = [];

        foreach ($filters as $operator => $value) {
            if (in_array($operator, ["and", "or"])) {
                $orAndOperators[$operator] = $value;
            }
        }

        $applyFilter = function(array $filters, Builder $query, string $boolean = 'and', ?string $field = null) use (&$applyFilter) {
            foreach ($filters as $operator => $value) {
                if ($this->isOperator($operator)) {
                    if (in_array($operator, ["and", "or"])) {
                        $query->where(function(Builder $nested) use (&$applyFilter, &$value, &$field, &$operator) {
                            $applyFilter($value, $nested, $operator, $field);
                        }, null, null, $operator);
                    } else {
                        $this->applyFilterOnQuery($query, $field, $operator, $value, $boolean);
                    }
                } else {
                    $applyFilter($value, $query, $boolean, $operator);
                }
            }
        };

        $applyFilter($filters, $query);
    }

    /**
     * @param string|int|array<string,mixed> $value
     * 
     * @throws ValidationException
     */
    protected function applyFilterOnQuery(Builder $query, string $field, string $operator, $value, string $boolean = 'and'): void
    {
        $this->validateOperatorValue($operator, $value, $field);

        $model = $query->getModel();

        if ($model instanceof Contracts\ISearchableModel and Str::contains($field, ":")) {
            $query->has(Str::before($field, ":"), ">=", 1, $boolean, function(Builder $query) use($field, $operator, &$value) {
                $this->applyFilterOnQuery($query, Str::after($field, ":"), $operator, $value);
            });
            return;
        }

        $operator = $this->getOperator($operator);

        switch ($operator) {
            case "=":
            case "!=":
            case "<":
            case "<=":
            case ">":
            case ">=":
            case "like":
                $query->where($field, $operator, $value, $boolean);
                break;
            case "startswith":
                $query->where($field, "like", $value . "%", $boolean);
                break;
            case "contains":
                $query->where($field, "like", "%" . $value . "%", $boolean);
                break;
            case "endswith":
                $query->where($field, "like", "%" . $value, $boolean);
                break;
            case "in":
                $query->whereIn($field, $value, $boolean);
                break;
            case "nin":
                $query->whereNotIn($field, $value, $boolean);
                break;
        }
    }

    protected function getOperator(string $operator): ?string
    {
        switch ($operator) {
            case "eq": $operator = '='; break;
            case "neq": $operator = '!='; break;
            case "lt": $operator = '<'; break;
            case "lte": $operator = '<='; break;
            case "gt": $operator = '>'; break;
            case "gte": $operator = '>='; break;
        }

        switch ($operator) {
            case "=":
            case "!=":
            case "<":
            case "<=":
            case ">":
            case ">=":
            case "like":
            case "in":
            case "nin":
            case "and":
            case "or":
            case "startswith":
            case "contains":
            case "endswith":
                return $operator;
            default:
                return null;
        }
    }

    protected function isOperator(string $operator): bool
    {
        return !is_null($this->getOperator($operator));
    }

    /**
     * @param string|int|array<string,mixed> $value
     */
    protected function validateOperatorValue(string $operator, $value, ?string $field = null): void
    {
        if (is_null($field)) {
            $field = $operator;
        }
        if (!$this->isOperator($operator)) {
            throw ValidationException::withMessages([$field => "The {$operator} operator is invalid."]);
        }

        switch ($this->getOperator($operator)) {
            case "=":
            case "!=":
            case "<":
            case "<=":
            case ">":
            case ">=":
            case "like":
            case "startswith":
            case "contains":
            case "endswith":
                $this->insurePremitiveValue($field, $value);
                break;
            case "in":
                $this->insureArrayOfPremitiveValue($field, $value);
                break;
            case "nin":
                $this->insureArrayOfPremitiveValue($field, $value);
                break;
            case "and":
            case "or":
                $this->insureLogicalArray($field, $value);
                break;
            default:
                throw ValidationException::withMessages([$field => "The {$operator} operator is invalid."]);
        }
    }

    /**
     * @param mixed $value
     */
    protected function insurePremitiveValue(string $field, $value): void
    {
        if (!is_numeric($value) and !is_string($value)) {
            throw ValidationException::withMessages([$field => "The selected value is invalid."]);
        }
    }

    /**
     * @param mixed $value
     */
    protected function insureArrayOfPremitiveValue(string $field, $value): void
    {
        if (!is_array($value)) {
            throw ValidationException::withMessages([$field => "The {$field} must be an array."]);
        }
        foreach ($value as $key => $index) {
            $this->insurePremitiveValue("{$field}.{$key}", $index);
        }
    }

    /**
     * @param mixed $value
     */
    protected function insureLogicalArray(string $field, $value): void
    {
        if (!is_array($value)) {
            throw ValidationException::withMessages([$field => "The {$field} must be an array."]);
        }
    }
}
