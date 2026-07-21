<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Commerce-Slice-2 Task 8 (storefront-rendering spec §4): the slug-history ledger backing
 * {@see \Thallo\Commerce\Shop\PackSlugLifecycleAuthority} and the product route's old-slug
 * 301 redirect. Every OLD slug a product renames away from is reserved here, keyed
 * `(tenant_uuid, slug)` — the SAME composite the shared advisory lock namespace locks on
 * (`thallo_commerce_slug:{tenant}:{slug}`), so the unique constraint below arbitrates
 * history/history collisions while the lock closes the current/history cross-table race
 * (design spec §4).
 */
final class CreateProductSlugLedger implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('thallo_commerce_product_slugs')) {
            return;
        }
        $schema->createTable('thallo_commerce_product_slugs', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('tenant_uuid', 12)->default('');
            // Matches commerce_products.slug's own column width (glueful/commerce migrations).
            $table->string('slug', 191);
            // Commerce product uuid — no DB FK into Commerce's tables (cross-package boundary,
            // same convention as thallo_commerce_product_links).
            $table->string('product_uuid', 12);
            $table->timestamp('created_at')->nullable();
            $table->unique(['tenant_uuid', 'slug'], 'uniq_commerce_product_slug_tenant_slug');
        });
        $schema->alterTable('thallo_commerce_product_slugs', static function ($table): void {
            // Reverse lookup (a product's full slug history) — a separate named index rather
            // than relying on the composite unique above.
            $table->index(['tenant_uuid', 'product_uuid'], 'idx_commerce_product_slug_tenant_product');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_commerce_product_slugs');
    }

    public function getDescription(): string
    {
        return 'Create thallo_commerce_product_slugs (slug-history ledger for 301 redirects, '
            . 'storefront-rendering spec §4).';
    }
}
