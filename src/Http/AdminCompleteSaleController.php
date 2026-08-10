<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Http\Admin\OrderProjection;
use Glueful\Extensions\Commerce\Orders\OrderRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Orders\CompleteSaleCoordinator;

/**
 * Admin-order-creation cycle 2, Task 13 (design spec §2.8):
 * `POST /v1/admin/commerce/orders/{uuid}/complete-sale` — the walk-in counter's one-click finish.
 * Manage authority (`commerce.manage`, route middleware): it performs two real state transitions.
 *
 * ORDER OF DECISIONS, which is itself part of the contract:
 *
 *  1. **Resolve the tenant-scoped order FIRST.** {@see OrderRepository::findByUuid()} is
 *     tenant-scoped and draft-blind by default (this controller never passes `includeDrafts`), so
 *     an unknown, cross-tenant, OR draft uuid is one non-revealing **404** — produced before any
 *     body is examined and before any service is consulted.
 *  2. **Validate the body** ⇒ **422**. The contract is closed and tiny: an absent/empty body, or a
 *     JSON object whose only permitted key is a nullable `tracking_ref` string of at most 191
 *     characters (the column's own width). Anything else — a non-string value, an over-long one,
 *     an unknown key, a non-object, unparseable JSON — is malformed. Validation stays inline here,
 *     matching {@see ProductLinkController::searchEntries()}'s established convention in this pack.
 *  3. **Hand the resolved row to {@see CompleteSaleCoordinator}**, which owns the pre-gates
 *     (`pending_payment` + `in_store`, else **409** with zero transitions) and the chained
 *     `markPaid()` → `fulfill()` outcomes.
 *
 * RESPONSE SHAPE: every non-404 answer carries the same
 * `{steps: [{step, status, error?}, ...], order}` payload, so the SPA renders one component for
 * success, conflict, and partial failure alike. `order` ALWAYS crosses the engine's closed
 * {@see OrderProjection::forAdmin()} — {@see self::respond()} is the single place that happens, so
 * a refreshed error shape can never leak the tenant, guest token, revision counters, or any draft
 * internal that a raw row carries. Exception text never appears anywhere in the payload; the
 * coordinator's fixed `error` constants are all the wire ever sees.
 */
final class AdminCompleteSaleController
{
    /** The single permitted body key, and the `commerce_orders.tracking_ref` column width. */
    private const TRACKING_REF_KEY = 'tracking_ref';
    private const TRACKING_REF_MAX = 191;

    /** Bounds on how much of a rejected body is echoed back — see {@see self::echoKeys()}. */
    private const ECHO_KEY_LIMIT = 5;
    private const ECHO_KEY_MAX_LENGTH = 32;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly OrderRepository $orders,
        private readonly CompleteSaleCoordinator $coordinator,
        private readonly CommerceTenantResolution $tenants,
    ) {
    }

    #[ApiOperation(summary: 'Complete an in-store sale', tags: ['Thallo Commerce'])]
    public function completeSale(Request $request, string $uuid): Response
    {
        $tenant = $this->tenants->tenantUuid($this->context);

        $order = $this->orders->findByUuid($this->context, $tenant, $uuid);
        if ($order === null) {
            return Response::error('Resource not found.', 404);
        }

        $trackingRef = $this->trackingRef($request);

        return $this->respond($this->coordinator->complete($tenant, $order, $trackingRef));
    }

    /**
     * The pack's ONE serialization boundary for this endpoint: the coordinator's raw result in,
     * the wire payload out, with `order` passed through the engine's closed admin projection on
     * every single outcome (200, 409, and 500 alike). Public so the coordinator's own contract can
     * be exercised against the REAL wire shape rather than a re-implementation of it.
     *
     * @param array{
     *     status: int,
     *     message: string,
     *     steps: list<array{step:string,status:string,error?:string}>,
     *     order: array<string,mixed>|null
     * } $result
     */
    public function respond(array $result): Response
    {
        $order = $result['order'];

        return new Response(
            [
                'success' => $result['status'] === 200,
                'message' => $result['message'],
                'data' => [
                    'steps' => $result['steps'],
                    'order' => $order === null ? null : OrderProjection::forAdmin($order),
                ],
            ],
            $result['status'],
        );
    }

    /**
     * @throws ValidationException (422) the body is not the closed `{tracking_ref?}` object
     */
    private function trackingRef(Request $request): ?string
    {
        $raw = trim((string) $request->getContent());
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ValidationException(['body' => ['The request body must be a JSON object.']]);
        }

        $unknown = array_diff(array_keys($decoded), [self::TRACKING_REF_KEY]);
        if ($unknown !== []) {
            throw new ValidationException(['body' => [
                'Unknown field(s): ' . $this->echoKeys($unknown) . '.',
            ]]);
        }

        $value = $decoded[self::TRACKING_REF_KEY] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ValidationException([self::TRACKING_REF_KEY => ['The tracking ref must be a string.']]);
        }

        $value = trim($value);
        if (mb_strlen($value) > self::TRACKING_REF_MAX) {
            throw new ValidationException([self::TRACKING_REF_KEY => [
                'The tracking ref may not be longer than ' . self::TRACKING_REF_MAX . ' characters.',
            ]]);
        }

        return $value === '' ? null : $value;
    }

    /**
     * Bounded echo of the offending keys. A 422 exists to tell an operator WHICH field was wrong,
     * not to mirror an arbitrarily large attacker-supplied body back into the response (and the
     * log line that renders it): at most {@see self::ECHO_KEY_LIMIT} keys, each truncated to
     * {@see self::ECHO_KEY_MAX_LENGTH} characters.
     *
     * @param array<int|string,int|string> $keys
     */
    private function echoKeys(array $keys): string
    {
        $shown = array_slice(array_values($keys), 0, self::ECHO_KEY_LIMIT);
        $rendered = array_map(
            static fn (int|string $key): string => mb_substr((string) $key, 0, self::ECHO_KEY_MAX_LENGTH),
            $shown,
        );
        if (count($keys) > self::ECHO_KEY_LIMIT) {
            $rendered[] = '…';
        }

        return implode(', ', $rendered);
    }
}
