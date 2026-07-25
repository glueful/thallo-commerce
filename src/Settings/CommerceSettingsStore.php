<?php

declare(strict_types=1);

namespace Thallo\Commerce\Settings;

/**
 * The pack-owned storage contract for runtime store settings (store-settings spec §3.3) — the
 * HOST app binds an implementation (its instance-settings key/value store); this package never
 * names an app class (InertnessTest's rule). Same direction as the delivery contracts the app
 * already implements (e.g. MediaUrlResolver): pack defines, app provides.
 */
interface CommerceSettingsStore
{
    /** The stored value for a key, or null when no row exists. */
    public function get(string $key): ?string;

    /** @param array<string,string> $pairs upsert each pair */
    public function putMany(array $pairs): void;

    /** DELETE the row (never blank it) so the config/env fallback shows through. */
    public function forget(string $key): void;
}
