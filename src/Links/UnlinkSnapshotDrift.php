<?php

declare(strict_types=1);

namespace Thallo\Commerce\Links;

/**
 * Internal signal ONLY: thrown inside {@see ProductLinkService::unlink()}'s locked
 * transaction when the re-read entry no longer matches the unlocked snapshot that chose the
 * lock set. Forces a real ROLLBACK (releasing the xact-scoped advisory locks) so the caller can
 * retry the whole snapshot/lock/re-read sequence from scratch, rather than acquiring the
 * newly-discovered entry's lock inside the already-open transaction (a late, out-of-order
 * lock). Never escapes {@see ProductLinkService} -- not part of its public contract.
 */
final class UnlinkSnapshotDrift extends \RuntimeException
{
}
