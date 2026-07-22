<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

final class SeedCommercePermissions implements MigrationInterface
{
    private const PERMISSIONS = [
        'commerce.view' => 'View commerce',
        'commerce.manage' => 'Manage commerce',
    ];

    public function up(SchemaBuilderInterface $schema): void
    {
        $db = new Connection();
        $existing = [];
        foreach (
            $db->table('permissions')->select(['slug', 'name'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get() as $row
        ) {
            $existing[$row['slug']] = $row['name'];
        }
        $insert = [];
        foreach (self::PERMISSIONS as $slug => $label) {
            if (!isset($existing[$slug])) {
                $insert[] = [
                    'uuid' => Utils::generateNanoID(),
                    'slug' => $slug,
                    'name' => $label,
                    'category' => 'commerce',
                    'description' => $label,
                    'is_system' => true,
                ];
                continue;
            }
            // Already-migrated DBs: converge a drifted name onto the current catalog
            // label (e.g. commerce.manage's pre-commerce.view name).
            if ($existing[$slug] !== $label) {
                $db->table('permissions')->where('slug', '=', $slug)->update(['name' => $label]);
            }
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
        return 'Declare the commerce.view and commerce.manage permissions.';
    }
}
