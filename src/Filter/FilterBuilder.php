<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Filter;

/**
 * A small fluent builder for a filter body.
 *
 * @experimental Beacon documents a filtering system in its guide, but the API
 *               request shape for it is not in any documentation available when
 *               this was written. The structure produced here — a boolean
 *               operator over a list of field/operator/value conditions — is a
 *               reasonable guess, not a verified contract. Check it against
 *               your account's generated docs, and use `toArray()` output as a
 *               starting point rather than gospel.
 *
 * ```php
 * $filter = FilterBuilder::all()
 *     ->where('emails', 'eq', 'alex@example.org')
 *     ->where('type', 'contains', 'Member')
 *     ->toArray();
 * ```
 */
final class FilterBuilder
{
    /** @var list<array<string, mixed>> */
    private array $conditions = [];

    private function __construct(private readonly string $operator)
    {
    }

    /**
     * Every condition must match.
     */
    public static function all(): self
    {
        return new self('and');
    }

    /**
     * Any condition may match.
     */
    public static function any(): self
    {
        return new self('or');
    }

    public function where(string $field, string $operator, mixed $value = null): self
    {
        $condition = ['field' => $field, 'operator' => $operator];

        if (func_num_args() > 2) {
            $condition['value'] = $value;
        }

        $this->conditions[] = $condition;

        return $this;
    }

    /**
     * Nests another builder's conditions inside this one.
     */
    public function group(self $group): self
    {
        $this->conditions[] = $group->toArray();

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operator' => $this->operator,
            'conditions' => $this->conditions,
        ];
    }
}
