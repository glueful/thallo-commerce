<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateProductLinkTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('thallo_commerce_product_links')) {
            return;
        }
        $schema->createTable('thallo_commerce_product_links', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            // Link identity (nanoID) — no standalone DB unique constraint (spec §5.1 lists
            // only the two composite uniques below); global uniqueness is by construction.
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12)->default('');
            // Commerce product uuid — no DB FK into Commerce tables (cross-package boundary;
            // lifecycle events + reconciliation keep this coherent instead, spec §6).
            $table->string('product_uuid', 12);
            // Thallo entry uuid — no FK either, same reason.
            $table->string('entry_uuid', 12);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            // Rows are active links only (no status/retirement column): a retained retired row
            // would collide with the entry unique and block relinking that entry elsewhere.
            $table->unique(['tenant_uuid', 'product_uuid'], 'uniq_commerce_product_link_tenant_product');
            $table->unique(['tenant_uuid', 'entry_uuid'], 'uniq_commerce_product_link_tenant_entry');
        });
        $schema->alterTable('thallo_commerce_product_links', static function ($table): void {
            // Detail-route lookup index (spec §5.1) — duplicates the first unique's columns
            // deliberately; kept as its own named index rather than relying on the constraint.
            $table->index(['tenant_uuid', 'product_uuid'], 'idx_commerce_product_link_tenant_product');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_commerce_product_links');
    }

    public function getDescription(): string
    {
        return 'Create thallo_commerce_product_links (canonical product-to-entry enrichment links).';
    }
}
