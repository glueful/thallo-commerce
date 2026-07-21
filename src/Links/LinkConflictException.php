<?php

declare(strict_types=1);

namespace Thallo\Commerce\Links;

use Glueful\Http\Exceptions\Client\ConflictException;

/**
 * The product<->entry link mutation conflicts with the current state (design spec §5.2):
 * the product is already linked without a matching `expected_entry_uuid`, a relink's
 * expectation is stale, the target entry already belongs to a different product, or a
 * unique-constraint race lost. Extends the framework's {@see ConflictException} so it renders
 * a 409 automatically through the global exception handler with no controller-level catch
 * required, while remaining independently catchable by its own type.
 */
final class LinkConflictException extends ConflictException
{
}
