<?php

declare(strict_types=1);

namespace Thallo\Commerce\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/thallo-commerce (schema policy spec B7): create migrations prove the
 * complete table set they create; permission-seed migrations prove their seeded slugs at the
 * data level — table existence never certifies a seed. Unknown basenames are never adoptable.
 */
final class CommerceLinkSchemaVerifier implements StructuralVerifierInterface
{
    /** @var array<string, list<string>> create migration => every table it creates */
    private const CREATED_TABLES = [
        '001_CreateProductLinkTable.php' => ['thallo_commerce_product_links'],
        '003_CreateProductSlugLedger.php' => ['thallo_commerce_product_slugs'],
        '004_CreateCheckoutAttempts.php' => ['thallo_commerce_checkout_attempts'],
        '005_CreatePaymentLinkDeliveries.php' => ['thallo_commerce_payment_link_deliveries'],
    ];

    /** @var array<string, list<string>> seed migration => permission slugs it guarantees */
    private const SEEDED_SLUGS = [
        '002_SeedCommercePermissions.php' => ['commerce.view', 'commerce.manage'],
    ];

    public function source(): string
    {
        return 'glueful/thallo-commerce';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        $names = array_merge(array_keys(self::CREATED_TABLES), array_keys(self::SEEDED_SLUGS));
        sort($names);
        return $names;
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        if (isset(self::CREATED_TABLES[$migrationBasename])) {
            $schema = $db->getSchemaBuilder();
            foreach (self::CREATED_TABLES[$migrationBasename] as $table) {
                if (!$schema->hasTable($table)) {
                    return false;
                }
            }
            return true;
        }
        if (isset(self::SEEDED_SLUGS[$migrationBasename])) {
            if (!$db->getSchemaBuilder()->hasTable('permissions')) {
                return false;
            }
            $slugs = self::SEEDED_SLUGS[$migrationBasename];
            $rows = $db->table('permissions')->select(['slug'])->whereIn('slug', $slugs)->get();
            return count(array_unique(array_column($rows, 'slug'))) === count($slugs);
        }
        return false;
    }
}
