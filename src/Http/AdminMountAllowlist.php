<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

/**
 * The Thallo admin mount's explicit, fail-closed allowlist of Commerce admin catalog keys
 * (Task 6, admin-commerce-area plan slice 3). `AdminMountProfile::restricted()` refuses an
 * empty list, and `AdminRouteCatalog::mount()` throws on any key it doesn't recognise — so
 * this list is the ONLY thing standing between a newly added Commerce admin endpoint and it
 * silently becoming reachable at `/v1/admin/commerce` the moment `glueful/commerce` is
 * upgraded. Every key is written out by hand, grouped by the catalog's own `domain` —
 * no wildcard, no programmatic derivation from the catalog itself.
 *
 * `AdminMountParityTest` is the enforcement mechanism: it fails loudly (naming the new key)
 * the moment the vendored catalog grows a key this list — and the checked-in
 * `tests/fixtures/commerce_admin_mount_inventory.json` approval fixture — doesn't yet know
 * about. Approving a new endpoint means consciously adding it to both this list and the
 * fixture, not just bumping a version pin.
 */
final class AdminMountAllowlist
{
    /** @return non-empty-list<string> */
    public static function keys(): array
    {
        return [
            // — Products / variants —
            'products.index',
            'products.store',
            'products.show',
            'products.update',
            'products.variants.store',
            'variants.update',
            'products.children.index',
            'products.children.set',
            'products.destroy',
            'products.bulk_status',
            'variants.bulk_price',
            // — Product media —
            'products.media.index',
            'products.media.attach',
            'products.media.reorder',
            'media.update',
            'media.detach',
            // — Product add-ons —
            'products.addons.index',
            'products.addons.store',
            'addons.update',
            'addons.destroy',

            // — Digital downloads —
            'variants.downloads.index',
            'variants.downloads.attach',
            'downloads.update',
            'downloads.detach',
            // — Grants —
            'grants.revoke',
            'grants.refund_override.set',
            'grants.refund_override.clear',

            // — Customers (read-only) —
            'customers.index',
            'customers.show',

            // — Categories —
            'categories.index',
            'categories.show',
            'categories.store',
            'categories.update',
            'categories.destroy',
            'products.categories.index',
            'products.categories.set',
            // — Tags —
            'tags.index',
            'tags.show',
            'tags.store',
            'tags.update',
            'tags.destroy',
            'products.tags.index',
            'products.tags.set',
            // — Attributes —
            'attributes.index',
            'attributes.show',
            'attributes.store',
            'attributes.update',
            'attributes.destroy',
            'attributes.values.store',
            'attribute_values.update',
            'attribute_values.destroy',
            'products.attributes.index',
            'products.attributes.set',

            // — Inventory —
            'products.stock.index',
            'stock.adjust',

            // — Discounts —
            'discounts.index',
            'discounts.store',
            'discounts.show',
            'discounts.update',
            'discounts.destroy',

            // — Orders —
            'orders.index',
            'orders.show',
            'orders.cancel',
            'orders.mark_paid',
            'orders.fulfill',
            'orders.refunds.store',
            'orders.refunds.index',
            'orders.notes.store',
            'orders.notes.index',
            'orders.invoice_data',
            'refunds.list',
            'refunds.show',

            // — Reviews —
            'reviews.index',
            'reviews.show',
            'reviews.store',
            'reviews.approve',
            'reviews.spam',
            'reviews.destroy',
            'reviews.bulk',

            // — Shipping zones + methods —
            'shipping.zones.index',
            'shipping.zones.show',
            'shipping.zones.store',
            'shipping.zones.update',
            'shipping.zones.destroy',
            'shipping.zones.locations.set',
            'shipping.zones.methods.index',
            'shipping.zones.methods.store',
            'shipping.methods.show',
            'shipping.methods.update',
            'shipping.methods.destroy',
            // — Shipping classes —
            'shipping.classes.index',
            'shipping.classes.show',
            'shipping.classes.store',
            'shipping.classes.update',
            'shipping.classes.destroy',

            // — Tax rates —
            'tax.rates.index',
            'tax.rates.show',
            'tax.rates.store',
            'tax.rates.update',
            'tax.rates.destroy',

            // — Reports —
            'reports.sales',
            'reports.products',
            'reports.customers',
            'reports.stock',
        ];
    }
}
