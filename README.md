# glueful/thallo-commerce

Commerce adoption + product-to-entry content linkage for [Thallo](https://thallo.dev), packaged as
a **removable capability pack**. It installs `glueful/commerce` (commercial truth — products,
variants, price, stock, cart, checkout, orders) and adds an optional Thallo-side editorial layer:
a Thallo entry (blocks, localized fields) linked to a Commerce product. A product with no linked
entry still renders from Commerce data alone.

## What it provides

- **Tenant resolution** (`CommerceTenantResolution`) — Commerce's tenant context tracks Thallo's
  three tenancy modes (sentinel / widened-default / enforced), so Commerce data stays scoped
  correctly through every stage of tenancy adoption.
- **The link table + `ProductLinkService`** — one canonical `product_uuid <-> entry_uuid` row per
  linkage, tenant-scoped, concurrency-safe (advisory locks + compare-and-swap relink), audited via
  after-commit events only. Admin JSON API under `/v1/admin/commerce` (`PUT`/`DELETE`/`GET .../link`),
  gated by `commerce.manage`.
- **Lifecycle cleanup** — a deleted Thallo entry or a deleted Commerce product removes the link row
  automatically; a reconcile sweep (`thallo:commerce:links:reconcile`) converges any drift. These
  listeners and the sweep stay active even while the capability is disabled, so previously-created
  links never rot.
- **Tenancy adoption + purge** — registers this pack's link table (and delegates to Commerce's own
  tables) with the tenancy adoption and purge seams, so enabling/purging a workspace carries
  Commerce data along correctly.
- **Starter "Product story" content type** — a batteries-included content type (`product-story`:
  localized `headline` + `summary`, a blocks region, no SEO fields — thallo-seo owns SEO) that
  participates in fresh-tenant provisioning like any fixed content type. See below.
- **Diagnostics** (`thallo:commerce:diagnose`) — stale/cross-tenant link counts, an unsupported
  marketplace-enabled flag, and inactive-Commerce detection.
- **Storefront** — rendered shop pages, cart, and checkout over Commerce, in Thallo's own theme
  system. See [Storefront](#storefront) below.

## The capability

The provider registers a single capability in `boot()`: `thallo.commerce`, enabled by default
(toggle via `'thallo.commerce' => false` in `config/thallo.php`'s `capabilities` switchboard).
Disabling it removes the user-facing admin routes, the storefront routes (shop/cart/checkout/
`/_shop/*`), and the starter content-type + block-type contributions; migrations, the link
table's tenancy registration, cleanup listeners, the purge handler, and the shop prefix's
reserved-path reservation all stay active regardless — data created before a switch-off remains
coherent and purgeable, and the reserved paths can never be shadowed by a builder page even while
disabled.

## Starter content type: install/enable step

Registering the `product-story` starter contributor only makes it *eligible* — it never mutates an
existing tenant on its own. Two provisioning paths follow from that:

- **Fresh and future tenants** pick it up automatically the next time they're provisioned; no
  extra step.
- **Pre-existing workspaces** need one explicit, idempotent sync after installing/enabling the
  pack:

  ```bash
  php glueful thallo:tenant:sync --all --kind=content_type
  ```

  Safe to re-run (a second run is a no-op for tenants that already have `product-story`). Treat a
  failed run as an incomplete activation step — it's retryable.

## Storefront

Commerce data rendered as real, themed pages — catalog browsing, cart, and checkout — plus four
builder blocks. Commerce stays authoritative for every business fact (price, stock, orders); a
linked Thallo entry is optional editorial enrichment only, never routing authority.

### Routes

Registered only while `thallo.commerce` is enabled, ahead of Render's `/{path}` catch-all (a
static/literal-first-segment route always wins over the dynamic catch-all bucket, regardless of
provider boot order):

```
GET  /{shop-prefix}                       shop index
GET  /{shop-prefix}/products/{slug}       product detail (301s from an old slug, see below)
GET  /{shop-prefix}/categories/{slug}     category archive
GET  /cart                                 cart page
GET  /checkout                             checkout page
GET  /checkout/return/{ref}                payment-provider return (read-only, redirects)
GET  /checkout/cancel/{ref}                payment-provider cancel (read-only, redirects)
GET  /checkout/confirmation/{ref}          order confirmation (ownership-protected)
```

`/{shop-prefix}` defaults to `/shop` (config `shop_prefix`, below); `/cart`, `/checkout`, and the
confirmation/return/cancel routes are stable root-level paths independent of the prefix. All four
prefixes/paths (`{shop-prefix}`, `cart`, `checkout`, `_shop`) stay **reserved** against Render's
catch-all even while the capability is disabled, so a builder page can never accidentally shadow
(or be shadowed by) the storefront. Every catalog/cart/checkout URL — in templates, blocks,
canonicals, and JSON-LD — comes from one `Thallo\Commerce\Shop\ShopUrlGenerator` service; nothing
else concatenates a shop path by hand.

Slug renames are safe: a Commerce-local `SlugLifecycleAuthority` seam reserves the old and new
slug transactionally inside Commerce's own create/rename transaction (a dedicated
`thallo_commerce_product_slugs` ledger, PostgreSQL advisory-lock serialized), so a second product
can never race-claim a slug a rename just vacated. A request for an old slug 301s to the product's
current URL; a slug with no live product (tombstoned, cross-tenant, or genuinely unknown) is a
single non-revealing 404 in every case.

### `/_shop` endpoints (JSON + PRG)

```
POST /_shop/cart/add | update | remove | discount
GET  /_shop/cart                           JSON cart view model (mini-cart hydration, no-store)
POST /_shop/checkout/quote                 read-only checkout preview
POST /_shop/checkout/place                 durable, idempotent order placement
GET  /_shop/blocks/product-grid | featured-product | add-to-cart   block hydration data
GET  /_shop/assets/{file}                  fingerprinted pack assets (shop.js)
```

Every mutating `/_shop` POST is a real HTML form: without JavaScript it mutates and 303-redirects
(PRG); `shop.js` intercepts the identical form and re-submits it with `Accept: application/json`
for an inline update — there are never two separate endpoints for the same action. Every `/_shop`
response is a **closed view model** (never a raw Commerce row) and carries
`Cache-Control: private, no-store` + `X-Robots-Tag: noindex`.

Every mutating request is checked against a same-origin CSRF rule with no token: `Sec-Fetch-Site`
(when present) must be exactly `same-origin`; the request's `Origin` (or, if absent, `Referer`)
must exactly match the canonical public origin; a request with neither header is rejected. The
expected origin comes from the shared `Thallo\Contracts\Delivery\CanonicalPublicOriginResolver`
contract — the SAME authority the engine's tenant-owned-blob media-URL provider resolves through,
so media links and storefront CSRF can never disagree about what the current tenant's public
origin is.

Cart adds/updates/removes go through Commerce's `putLine(...)` primitive: replaying an identical
request converges on one line at the submitted quantity instead of adding twice. The cart token is
minted on first mutation into a `Secure; HttpOnly; SameSite=Lax` cookie (never in markup, JS
storage, or a URL), TTL from `commerce.cart.ttl_days`.

### Checkout & payments

Placement (`POST /_shop/checkout/place`) is durably idempotent: a
`thallo_commerce_checkout_attempts` ledger plus an optional Commerce `CheckoutAttemptAuthority`
seam means a retried request with the same idempotency key + payload replays the exact same
order/credential (never a second order, never a lost payment-collector call); the same key with a
*different* payload is a 409. The pack never imports a specific payment provider — it depends only
on Commerce's payment abstraction via a `CheckoutPresentation` mapper that closes any provider's
result into one of four view models: `manual` (Commerce's own instructions — the default with no
collector installed), `redirect` (a validated `https` URL), `reference` (an opaque provider
reference), or `unavailable` (the safe fallback for anything malformed — never a raw payload).
`/checkout/return/{ref}` and `/checkout/cancel/{ref}` NEVER mark an order paid from the browser —
both are pure reads that re-check ownership, re-read the order's server-side state, and redirect
to confirmation; only a verified webhook (Commerce's own confirmation path) can move an order out
of `pending_payment`. Guest order credentials live in a **separate**, `EncryptionService`-encrypted
`Secure; HttpOnly; SameSite=Lax` cookie holding at most 5 `(order_ref, token)` pairs (oldest
evicted); `thallo:commerce:checkout:purge-attempts` sweeps expired attempt rows on the same
`guest_confirmation_days` window.

### Blocks

Four starter block types ship via the same starter-contributor pattern slice 1 established for
content types, generalized to block types:

- **`product-grid`** — a category, tag, manual list (newline-delimited product slugs, deduped and
  capped at 50), or "newest" grid with server-side pagination.
- **`featured-product`** — spotlights one product by slug, or falls back to the current entry's
  linked product on a Product story.
- **`add-to-cart`** — submits directly for a simple product; renders required variant/add-on
  controls (or a link to the full product page) rather than ever building an invalid cart line.
- **`mini-cart`** — a stable, cacheable shell whose contents hydrate client-side via
  `GET /_shop/cart`; a plain `/cart` link without JavaScript.

All four render a themed, parameter-carrying shell server-side and hydrate live data via
`shop.js` + the `/_shop/blocks/*`/`/_shop/cart` endpoints above — never a live Commerce lookup at
Twig-render time, so a builder page carrying one of these blocks stays safely cacheable by
Render's own page cache.

### `shop.js` and assets

One dependency-free `shop.js` — no framework, no build step — served at
`GET /_shop/assets/shop-{fingerprint}.js` (a content hash computed at boot, so the URL changes
automatically whenever the shipped file changes; `public, max-age=31536000, immutable` response
headers). It intercepts every `/_shop` form, re-submits with JSON negotiation, updates the cart
drawer/count/line totals/quote results inline, manages focus + `aria-live` announcements, and
disables double-submits. Once a request is in flight, an ambiguous network failure never
auto-retries with a second native POST — the user gets an explicit retry action that reuses the
original checkout idempotency key. Templates only ever reference it via
`ShopUrlGenerator::assets()`.

### Caching

Catalog pages (shop index, product detail, category archive) are cached in a dedicated shop cache,
keyed on `(tenant, resolved locale, active theme, appearance fingerprint, path, page)` — `page` is
the only allowed query parameter (an integer `1..1000`; anything else is a non-revealing 404 with
no cache write, and any OTHER query parameter bypasses the cache entirely). It purges on every
storefront-visible Commerce mutation — product/variant/price, stock (including checkout, refund,
and cancel adjustments), media, category/tag/attribute, and add-on changes — via a broad
`StorefrontCatalogChanged` Commerce event, plus the existing global theme/appearance-change events.
`/cart`, `/checkout`, return/cancel/confirmation, and every `/_shop` response are never cached
(`private, no-store`). TTL (`shop_cache.ttl`, below) is defense-in-depth only; the purge events are
the real freshness mechanism.

### Configuration

```php
// config/thallo-commerce.php (or THALLO_COMMERCE_* env vars)
'shop_prefix' => 'shop',                 // THALLO_COMMERCE_SHOP_PREFIX — one path segment, validated at boot
'shop_cache' => [
    'enabled' => true,                   // THALLO_COMMERCE_SHOP_CACHE_ENABLED
    'ttl' => 3600,                       // THALLO_COMMERCE_SHOP_CACHE_TTL (seconds)
],
'guest_confirmation_days' => 30,         // THALLO_COMMERCE_GUEST_CONFIRMATION_DAYS — clamped 1-90;
                                          // the guest-order-cookie lifetime AND the checkout-attempt
                                          // ledger's retention window (Commerce orders have no
                                          // general expiry, so this pack defines its own instead)
```

### Payment links: the public surface, and REDACTING THE TOKEN FROM LOGS

The pack serves Commerce's payment links at four public paths:

| Path | What it is |
| --- | --- |
| `GET /checkout/pay/{token}` | the payer's landing page (summary + a no-JS Pay form) |
| `POST /checkout/pay/{token}/initiate` | starts a gateway checkout; `303` only to a re-validated absolute-HTTPS URL |
| `GET /checkout/pay/return/{linkUuid}/{signature}` | a signed, **non-authorizing** receipt — reveals nothing |
| `GET /checkout/pay/cancel/{linkUuid}/{signature}` | the cancel sibling, under its own signing purpose |

Every response carries `Cache-Control: no-store`, `Referrer-Policy: strict-origin`, and
`X-Robots-Tag: noindex, nofollow, noarchive`. Under `strict-origin` a cross-origin navigation
discloses only the merchant's ORIGIN (`https://shop.example/`) and never the path, so the token
cannot ride out in a `Referer` — while the same-origin form POST still sends a real `Origin`,
which is what lets the stock `ShopCsrfGuard` stand alone here. The receipt handles carry a link uuid and a
signature — **never** a token.

**`{token}` is a bearer credential in a URL path.** Whoever reads it can open the page and start
a checkout, so it must never be written to an access log, an APM trace, or an error report. The
application never logs it (see `ShopPaymentLinkController` and
`ThalloPaymentLinkPublicUrlProvider`: the parameter is overwritten the moment it is consumed, and
it appears in exactly one place in the markup — the Pay form's own `action`). **Your reverse
proxy does not know that**, and its access log is written before any PHP runs. Redact it there:

**nginx** — rewrite the logged path, keeping everything else:

```nginx
# Replace the 64-hex token segment with a marker BEFORE the line is written.
map $request_uri $request_uri_redacted {
    "~^(?<pre>/checkout/pay/)[a-f0-9]{64}(?<post>.*)$"  "${pre}[REDACTED]${post}";
    default                                             $request_uri;
}

log_format redacted '$remote_addr - $remote_user [$time_local] '
                    '"$request_method $request_uri_redacted $server_protocol" '
                    '$status $body_bytes_sent "$http_referer" "$http_user_agent"';

access_log /var/log/nginx/access.log redacted;
```

**Apache 2.4** — build the request line from a pre-redacted environment variable. Apache has no
"rewrite the logged URI" primitive, so the substitute path is set explicitly per shape and the
format never uses `%r` or `%U` (both of which would print the real path, token and all):

```apache
# mod_setenvif. Anchored, so exactly one of these can match; the value is the URI to log.
SetEnvIfExpr "%{REQUEST_URI} =~ m#^/checkout/pay/[a-f0-9]{64}$#"          redacted_uri=/checkout/pay/[REDACTED]
SetEnvIfExpr "%{REQUEST_URI} =~ m#^/checkout/pay/[a-f0-9]{64}/initiate$#" redacted_uri=/checkout/pay/[REDACTED]/initiate

# NOTE: %r / %U are deliberately absent — this line is assembled from method + env var + protocol.
LogFormat "%h %l %u %t \"%m %{redacted_uri}e %H\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\"" redacted

CustomLog logs/access_log combined env=!redacted_uri
CustomLog logs/access_log redacted  env=redacted_uri
```

What it covers and what it does not: it covers the two token-bearing paths only (the receipt
handles carry a link uuid and a signature, never a token, so they log normally through
`combined`). A request that is neither shape is logged verbatim by the first `CustomLog`. It does
NOT touch the error log, `mod_security`'s audit log, or anything an APM agent records — those need
their own rules.

**Caddy** — rewrite the logged URI with the `filter` encoder's `regexp` action. This is
**site-wide** (Caddy's `log` directive accepts no request matcher — a `@matcher` next to it would
be dead config), but it is a *substitution*, so non-payment-link URIs pass through untouched:

```caddy
log {
    format filter {
        wrap console
        fields {
            request>uri regexp "^/checkout/pay/[0-9a-f]+" "/checkout/pay/[REDACTED]"
        }
    }
}
```

The pattern is `[0-9a-f]+` rather than an exact `{64}` quantifier because braces are placeholder
syntax in a Caddyfile; the widened match only ever redacts MORE of this path prefix, never less.
Only the matched prefix is replaced, so `/checkout/pay/<token>/initiate` still logs as
`/checkout/pay/[REDACTED]/initiate`. If you would rather not log these requests at all, use
`log_skip` with a `path_regexp` matcher instead — that directive does take one.

Also worth pinning on any install that serves payment links:

- `zend.exception_ignore_args=On` in php.ini — PHP records call arguments in exception
  backtraces, and the framework's error handler writes `getTraceAsString()` to the error log.
- No third-party analytics/RUM script on these pages. The templates ship zero third-party assets
  and `Referrer-Policy: strict-origin` stops the token reaching the payment gateway through
  `Referer` — only the merchant origin is disclosed, never the payment-link path; a tag manager
  added later would undo both.

## Boundary

Depends on `glueful/thallo-contracts`, `glueful/thallo-tenancy`, and (hard dependency, per the
adoption + linkage design) `glueful/commerce` — never on `glueful/thallo` (the application). The
repo's `composer boundaries` check enforces this at both the Composer-dependency and source level.

## Install

1. `composer require glueful/thallo-commerce`
2. `./thallo extensions:enable thallo-commerce` (writes the provider into the
   `config/extensions.php` allow-list and recompiles the extension cache)
3. `./thallo migrate:run` to create the link table.
4. For workspaces that already existed before this step, run the sync command above.

## Remove

`./thallo extensions:disable thallo-commerce`, then `composer remove glueful/thallo-commerce`. The
CMS core boots unchanged; the `thallo.commerce` capability disappears from
`GET /v1/admin/capabilities`.
