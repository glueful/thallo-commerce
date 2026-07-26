<?php

declare(strict_types=1);

namespace Thallo\Commerce\Starter;

use Thallo\Contracts\Starter\StarterContentTypeContributor;
use Thallo\Contracts\Starter\StarterContentTypeDefinition;

/**
 * Commerce-Slice-1 Task 11: this pack's contribution to the starter content-type set (design
 * spec §9) — a batteries-included "Product story" content type editors can link to a Commerce
 * product via {@see \Thallo\Commerce\Links\ProductLinkService}. Any suitable content type
 * remains linkable; this is the default, not a requirement. (Named "Product page" until
 * 2026-07-26 — renamed pre-launch because that read as the storefront page the product
 * displays on; slug, name, AND sourceId were all aligned while no published install existed.
 * Post-distribution, a rename would keep the sourceId frozen — see below.)
 *
 * Field-shape mirrors the engine's fixed content-type definitions (the ContentTypeKind starter
 * kind's payloads()) as closely as an editorial-detail type allows: `headline` copies the
 * `string` type the fixed 'pages'/'post' types use for their `title` field; `body` copies the
 * `blocks` type the fixed 'pages'/'post' types use for their `body` field. No fixed CONTENT type
 * currently ships a rich/long-text field, so `summary`'s `type: text, format: rich` instead
 * mirrors the rich-text body/answer fields already shipped by the engine's starter block types
 * (block *types*, not content types, but the same schema vocabulary —
 * `FieldDefinition::TEXT_FORMATS`). `headline`/`summary` are `localized` (per-locale translation,
 * design spec §9's "localized editorial fields"); `body` is left unlocalized, matching the fixed
 * 'pages'/'post' `body` fields (neither marks it localized).
 *
 * Deliberately NO seo/meta fields (design spec §9: "No SEO storage duplication" — SEO metadata
 * stays in thallo-seo's own mechanism, which applies to any entry, this type included).
 *
 * `publicDelivery: true, mountAtRoot: false` mirrors the fixed 'post'/'category' types (an
 * individually-routed detail page, never a singleton root-mounted page like 'pages').
 *
 * The `sourceId` is a stable pack:concept identifier, NOT derived from `slug` — design spec §9
 * requires it to survive a future slug rename (unlike the fixed types, whose sourceId is
 * `content_type:{slug}` precisely because they are never renamed).
 */
final class ProductStoryContributor implements StarterContentTypeContributor
{
    public const SOURCE_ID = 'thallo-commerce:product-story';
    public const SLUG = 'product-story';

    /** @return list<StarterContentTypeDefinition> */
    public function contentTypeDefinitions(): array
    {
        return [new StarterContentTypeDefinition(
            sourceId: self::SOURCE_ID,
            slug: self::SLUG,
            name: 'Product story',
            description: 'Editorial content for a linked commerce product.',
            cacheTtl: null,
            publicDelivery: true,
            mountAtRoot: false,
            schema: [
                ['name' => 'headline', 'type' => 'string', 'required' => true, 'localized' => true],
                ['name' => 'summary', 'type' => 'text', 'format' => 'rich', 'localized' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        )];
    }
}
