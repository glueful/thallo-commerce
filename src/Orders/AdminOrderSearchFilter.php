<?php

declare(strict_types=1);

namespace Thallo\Commerce\Orders;

use DateTimeImmutable;
use DateTimeZone;
use Glueful\Api\Filtering\QueryFilter;
use Glueful\Database\QueryBuilder;
use Glueful\Validation\ValidationException;

/**
 * TEMPORARY OWNERSHIP (orders-invoices-receipts plan, Task 3): see {@see AdminOrderSearchQuery}'s
 * docblock for the retirement condition (upstream filter parity on Commerce's own admin orders
 * endpoint).
 *
 * Extends {@see QueryFilter} for the shared type (route/DI code can depend on the abstraction),
 * but {@see self::apply()} is a COMPLETE override that never calls `parent::apply()`. The base
 * class's `apply()` parses `?filter[...]`, `?search=`, and `?sort=` via `FilterParser` — none of
 * that is this endpoint's public contract. `GET /orders/search` instead exposes five direct,
 * closed query parameters: `status`, `fulfillment_status`, `placed_from`, `placed_to`, `q`. Any
 * `sort`/`search`/`filter[...]` a caller sends is silently inert — this class never reads them,
 * so the framework's general filter vocabulary can never leak into (or widen) this endpoint.
 *
 * Validation failures throw {@see ValidationException} (422). Predicates only — ordering is
 * {@see AdminOrderSearchQuery::applyOrder()}'s job, applied by the controller AFTER this filter.
 */
final class AdminOrderSearchFilter extends QueryFilter
{
    private const STATUSES = ['pending_payment', 'paid', 'fulfilled', 'canceled', 'refunded'];
    private const FULFILLMENT_STATUSES = ['unfulfilled', 'partial', 'fulfilled'];
    private const Q_MAX_LENGTH = 200;
    /** Portable LIKE escape char: `!` itself, then the two LIKE wildcards, all doubled/escaped. */
    private const ESCAPE_MAP = ['!' => '!!', '%' => '!%', '_' => '!_'];

    public function apply(QueryBuilder $query): QueryBuilder
    {
        $this->query = $query;

        $this->applyStatus();
        $this->applyFulfillmentStatus();
        $this->applyDateRange();
        $this->applySearchTerm();

        return $this->query;
    }

    private function applyStatus(): void
    {
        $status = $this->stringParam('status');
        if ($status === null) {
            return;
        }
        $this->validateEnum('status', $status, self::STATUSES);
        $this->query->where('status', $status);
    }

    private function applyFulfillmentStatus(): void
    {
        $value = $this->stringParam('fulfillment_status');
        if ($value === null) {
            return;
        }
        $this->validateEnum('fulfillment_status', $value, self::FULFILLMENT_STATUSES);
        $this->query->where('fulfillment_status', $value);
    }

    /**
     * Half-open UTC interval: `from >= 00:00`, `to < next-day 00:00`. Both bounds are
     * independently optional. When both are present, `placed_from > placed_to` is 422 (compared
     * on the RAW calendar dates, before `placed_to` is shifted to its exclusive next-day form).
     *
     * The predicate is the two-branch indexable form (grouped OR over two AND-groups), NEVER
     * `WHERE COALESCE(placed_at, created_at) ...` — a computed expression neither
     * `(tenant_uuid, placed_at)` nor `(tenant_uuid, created_at)` can serve as an index range scan.
     * Each bound clause is added only when that bound is actually present, so a caller supplying
     * just one of `placed_from`/`placed_to` still gets a sargable single-sided range.
     */
    private function applyDateRange(): void
    {
        $from = null;
        $placedFrom = $this->stringParam('placed_from');
        if ($placedFrom !== null) {
            $from = $this->parseUtcDate('placed_from', $placedFrom);
        }

        $to = null;
        $toExclusive = null;
        $placedTo = $this->stringParam('placed_to');
        if ($placedTo !== null) {
            $to = $this->parseUtcDate('placed_to', $placedTo);
            $toExclusive = $to->modify('+1 day');
        }

        if ($from !== null && $to !== null && $from > $to) {
            throw new ValidationException(['placed_to' => ['placed_from must not be after placed_to.']]);
        }

        if ($from === null && $toExclusive === null) {
            return;
        }

        $fromValue = $from?->format('Y-m-d H:i:s');
        $toValue = $toExclusive?->format('Y-m-d H:i:s');

        $this->query->where(function ($w) use ($fromValue, $toValue): void {
            $w->where(function ($a) use ($fromValue, $toValue): void {
                $a->whereNotNull('placed_at');
                if ($fromValue !== null) {
                    $a->where('placed_at', '>=', $fromValue);
                }
                if ($toValue !== null) {
                    $a->where('placed_at', '<', $toValue);
                }
            })->orWhere(function ($b) use ($fromValue, $toValue): void {
                $b->whereNull('placed_at');
                if ($fromValue !== null) {
                    $b->where('created_at', '>=', $fromValue);
                }
                if ($toValue !== null) {
                    $b->where('created_at', '<', $toValue);
                }
            });
        });
    }

    /**
     * Strict UTC `!Y-m-d` parse AND round-trip: `createFromFormat('!Y-m-d', ...)` resets every
     * field to the UTC epoch before parsing (no leftover time-of-day bits), but PHP's date parser
     * still arithmetically overflows an out-of-range day (e.g. `2026-02-31` silently becomes
     * `2026-03-03`) instead of failing. Re-formatting the parsed result and comparing it back to
     * the original string catches exactly that shape-valid-but-impossible case, on top of the
     * regex rejecting anything that isn't already `\d{4}-\d{2}-\d{2}`.
     */
    private function parseUtcDate(string $field, string $value): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new ValidationException([$field => ["The {$field} parameter must be a valid Y-m-d date."]]);
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new ValidationException([$field => ["The {$field} parameter must be a valid Y-m-d date."]]);
        }

        return $parsed;
    }

    /**
     * Prefix match, one explicit portable escape contract (framework `whereLike()` emits no
     * `ESCAPE` clause, so it cannot express a literal-`%`/`_` search). Applied as grouped raw
     * predicates so a caller's literal `!`, `%`, or `_` in `q` matches only literally, never as a
     * wildcard, on either SQLite or PostgreSQL. `order_number` matches case-sensitively;
     * `email` is matched case-normalized (`LOWER(email)` against a lower-cased pattern).
     */
    private function applySearchTerm(): void
    {
        $q = $this->stringParam('q');
        if ($q === null) {
            return;
        }
        $q = trim($q);
        if (mb_strlen($q) > self::Q_MAX_LENGTH) {
            throw new ValidationException(['q' => ['The q parameter must not exceed 200 characters.']]);
        }
        if ($q === '') {
            return;
        }

        $escaped = strtr($q, self::ESCAPE_MAP);
        $orderNumberPattern = $escaped . '%';
        $emailPattern = strtolower($orderNumberPattern);

        $this->query->where(function ($w) use ($orderNumberPattern, $emailPattern): void {
            $w->whereRaw("order_number LIKE ? ESCAPE '!'", [$orderNumberPattern]);
            $w->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$emailPattern]);
        });
    }

    /** @param list<string> $allowed */
    private function validateEnum(string $field, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new ValidationException([
                $field => ['The ' . $field . ' parameter must be one of: ' . implode(', ', $allowed) . '.'],
            ]);
        }
    }

    /** Reads a direct (non-`filter[...]`) query parameter as a string, or null when absent. */
    private function stringParam(string $name): ?string
    {
        $value = $this->getRequest()->query->get($name);
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ValidationException([$name => ["The {$name} parameter must be a string."]]);
        }

        return $value;
    }
}
