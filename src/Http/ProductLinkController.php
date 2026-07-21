<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Links\LinkConflictException;
use Thallo\Commerce\Links\ProductLinkService;

/**
 * Admin product<->entry linkage API (design spec §5.3). Route-gated (capability → auth →
 * tenant → content_permission:commerce.manage). Error mapping: unknown/cross-tenant/
 * tombstoned product or unknown/cross-tenant entry → 404 (the service's NotFoundException,
 * caught here — mirrors every other Thallo admin controller's explicit-catch convention, and
 * keeps the mapping correct whether this method is dispatched through the HTTP kernel or
 * called directly, as this pack's own tests do); malformed body → 422 (uncaught
 * {@see ProductLinkRequestDTO} ValidationException, rendered by the framework's global
 * exception handler when routed through the kernel); link/relink/unique conflicts → 409
 * (LinkConflictException, caught here for the same reason as NotFoundException).
 */
final class ProductLinkController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProductLinkService $links,
    ) {
    }

    public function link(Request $request, string $productUuid): Response
    {
        /** @var array<string,mixed> $body */
        $body = (array) json_decode((string) $request->getContent(), true);
        $dto = ProductLinkRequestDTO::fromRequest($body); // throws 422

        // Status-code hint only (200 replace vs 201 create) — the authoritative
        // create-vs-relink decision is made inside the service's locked transaction; a benign
        // race against this pre-check can at most pick the wrong status code, never wrong data.
        $existedBefore = $this->links->resolveByProduct($this->context, $productUuid) !== null;

        try {
            $row = $this->links->link($this->context, $productUuid, $dto->entryUuid, $dto->expectedEntryUuid);
        } catch (NotFoundException $e) {
            return Response::error($e->getMessage(), 404);
        } catch (LinkConflictException $e) {
            return Response::error($e->getMessage(), 409);
        }

        return new Response(
            ['success' => true, 'message' => 'Success', 'data' => $this->toArray($row)],
            $existedBefore ? 200 : 201,
        );
    }

    public function unlink(Request $request, string $productUuid): Response
    {
        try {
            $this->links->unlink($this->context, $productUuid);
        } catch (LinkConflictException $e) {
            return Response::error($e->getMessage(), 409);
        }

        return Response::success(['product_uuid' => $productUuid]);
    }

    public function showByProduct(Request $request, string $productUuid): Response
    {
        $row = $this->links->resolveByProduct($this->context, $productUuid);
        if ($row === null) {
            return Response::error('Link not found.', 404);
        }

        return Response::success($this->toArray($row));
    }

    public function showByEntry(Request $request, string $entryUuid): Response
    {
        $row = $this->links->resolveByEntry($this->context, $entryUuid);
        if ($row === null) {
            return Response::error('Link not found.', 404);
        }

        return Response::success($this->toArray($row));
    }

    /**
     * @param array<string,mixed> $row
     * @return array{uuid:string,product_uuid:string,entry_uuid:string,created_at:?string,updated_at:?string}
     */
    private function toArray(array $row): array
    {
        return [
            'uuid' => (string) $row['uuid'],
            'product_uuid' => (string) $row['product_uuid'],
            'entry_uuid' => (string) $row['entry_uuid'],
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }
}
