<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Links\EntryLinkSearch;
use Thallo\Commerce\Links\LinkConflictException;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Commerce\Shop\StorefrontPreviewUrlBuilder;

/**
 * Admin product<->entry linkage API (design spec §5.3). Route-gated (capability → auth →
 * tenant → content_permission:commerce.view,commerce.manage for reads, commerce.manage for
 * writes -- task 7 regrades the two GET lookups + the new entry-search endpoint below to admit
 * view-only operators, while PUT/DELETE stay manage-only). Error mapping: unknown/cross-tenant/
 * tombstoned product or unknown/cross-tenant entry → 404 (the service's NotFoundException,
 * caught here — mirrors every other Thallo admin controller's explicit-catch convention, and
 * keeps the mapping correct whether this method is dispatched through the HTTP kernel or
 * called directly, as this pack's own tests do); malformed body/query → 422 (uncaught
 * {@see ProductLinkRequestDTO}/{@see ValidationException}, rendered by the framework's global
 * exception handler when routed through the kernel); link/relink/unique conflicts → 409
 * (LinkConflictException, caught here for the same reason as NotFoundException).
 */
final class ProductLinkController
{
    /** Task 7: the entry-search endpoint's hard result cap (design spec §5.3). */
    private const ENTRY_SEARCH_LIMIT = 20;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ProductLinkService $links,
        private readonly StorefrontPreviewUrlBuilder $previewUrls,
        private readonly EntryLinkSearch $entrySearch,
    ) {
    }

    #[ApiOperation(summary: 'Link a product to a content entry', tags: ['Thallo Commerce'])]
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

    #[ApiOperation(summary: 'Unlink a product from its content entry', tags: ['Thallo Commerce'])]
    public function unlink(Request $request, string $productUuid): Response
    {
        try {
            $this->links->unlink($this->context, $productUuid);
        } catch (LinkConflictException $e) {
            return Response::error($e->getMessage(), 409);
        }

        return Response::success(['product_uuid' => $productUuid]);
    }

    /**
     * Task 7: distinguishes "no such product" (404) from "a real, accessible product with no
     * active link" (200, `link: null`) -- the pre-task-7 behavior folded both into a 404 by
     * deriving everything from the link row alone. Product accessibility is resolved
     * independently via {@see ProductLinkService::resolveProductSlug()} (the SAME
     * unknown/cross-tenant/tombstoned check {@see self::link()} uses), and `storefront_url` is
     * always present for an accessible product regardless of link state.
     */
    #[ApiOperation(summary: 'Product link projection (by product uuid)', tags: ['Thallo Commerce'])]
    public function showByProduct(Request $request, string $productUuid): Response
    {
        $slug = $this->links->resolveProductSlug($this->context, $productUuid);
        if ($slug === null) {
            return Response::error('Product not found.', 404);
        }

        $row = $this->links->resolveByProduct($this->context, $productUuid);

        return Response::success([
            'product_uuid' => $productUuid,
            'storefront_url' => $this->previewUrls->productUrl($this->context, $slug),
            'link' => $row === null ? null : $this->toArray($row),
        ]);
    }

    #[ApiOperation(summary: 'Product link lookup (by entry uuid)', tags: ['Thallo Commerce'])]
    public function showByEntry(Request $request, string $entryUuid): Response
    {
        $row = $this->links->resolveByEntry($this->context, $entryUuid);
        if ($row === null) {
            return Response::error('Link not found.', 404);
        }

        return Response::success($this->toArray($row));
    }

    /**
     * Task 7: the admin linkage picker's entry search (design spec §5.3). Validation stays
     * inline here (rather than a router-hydrated {@see \Glueful\Validation\Contracts\RequestData}
     * DTO) to match this controller's established convention -- {@see self::link()}'s manual
     * {@see ProductLinkRequestDTO} validation -- and to keep it exercisable via a direct
     * controller call the same way this pack's own tests already drive every other action here.
     * `EntryLinkSearch` owns the tenant-scoped query, the locale-determinism rule, and the
     * five-field projection; this method only validates `q` and applies the hard result cap.
     *
     * @throws ValidationException (422) `q` is missing or shorter than 2 characters
     */
    #[ApiOperation(summary: 'Search content entries for the linkage picker', tags: ['Thallo Commerce'])]
    public function searchEntries(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2) {
            throw new ValidationException(['q' => ['The q parameter must be at least 2 characters.']]);
        }

        $localeParam = $request->query->get('locale');
        $locale = is_string($localeParam) && $localeParam !== '' ? $localeParam : null;

        return Response::success(
            $this->entrySearch->search($this->context, $q, $locale, self::ENTRY_SEARCH_LIMIT),
            'Entries retrieved.',
        );
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
