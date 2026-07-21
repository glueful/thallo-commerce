<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

final class SeedCommercePermissions implements MigrationInterface
{
    private const PERMISSIONS = [
        'commerce.manage' => 'Manage commerce product-content links',
    ];

    public function up(SchemaBuilderInterface $schema): void
    {
        $db = new Connection();
        $existing = [];
        foreach (
            $db->table('permissions')->select(['slug'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get() as $row
        ) {
            $existing[$row['slug']] = true;
        }
        $insert = [];
        foreach (self::PERMISSIONS as $slug => $label) {
            if (isset($existing[$slug])) {
                continue;
            }
            $insert[] = [
                'uuid' => Utils::generateNanoID(),
                'slug' => $slug,
                'name' => $label,
                'category' => 'commerce',
                'description' => $label,
                'is_system' => true,
            ];
        }
        if ($insert !== []) {
            $db->table('permissions')->insertBatch($insert);
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // NO-OP: removing the pack must not strip permission rows roles may reference.
    }

    public function getDescription(): string
    {
        return 'Declare the commerce.manage permission.';
    }
}
