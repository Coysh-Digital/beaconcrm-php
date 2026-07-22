<?php

declare(strict_types=1);

namespace CoyshDigital\Beacon\Http;

/**
 * A decoded Beacon response.
 *
 * Single-record endpoints answer with an envelope: `entity` holds the record,
 * `references` holds populated linked-record data when `populate` asked for it.
 * List endpoints answer with `results`. Both are exposed here, along with the
 * raw body for anything this library does not model.
 */
final class Response
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly int $status,
        public readonly array $data,
    ) {
    }

    /**
     * The record from a single-record response.
     *
     * @return array<string, mixed>|null
     */
    public function entity(): ?array
    {
        $entity = $this->data['entity'] ?? null;

        return is_array($entity) ? $entity : null;
    }

    /**
     * The Beacon record ID from a single-record response. Worth persisting:
     * it is what lets a submission be reconciled against the CRM later.
     */
    public function entityId(): ?int
    {
        $id = $this->entity()['id'] ?? null;

        return is_numeric($id) ? (int)$id : null;
    }

    /**
     * Linked-record data, populated only when the request asked for it.
     *
     * @return list<mixed>
     */
    public function references(): array
    {
        $references = $this->data['references'] ?? [];

        return is_array($references) ? array_values($references) : [];
    }

    /**
     * The raw rows of a list response. Each is an `{entity, references}`
     * envelope of its own — {@see entities()} for just the records.
     *
     * @return list<mixed>
     */
    public function results(): array
    {
        $results = $this->data['results'] ?? [];

        return is_array($results) ? array_values($results) : [];
    }

    /**
     * The records from a list response, unwrapped from their envelopes.
     *
     * @return list<array<string, mixed>>
     */
    public function entities(): array
    {
        $entities = [];

        foreach ($this->results() as $result) {
            if (!is_array($result)) {
                continue;
            }

            // A list row wraps its record; a plain record is taken as-is.
            $entity = $result['entity'] ?? $result;

            if (is_array($entity)) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /**
     * How many records match in total, not just on this page.
     */
    public function total(): int
    {
        $total = $this->data['total'] ?? null;

        return is_numeric($total) ? (int)$total : count($this->results());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
