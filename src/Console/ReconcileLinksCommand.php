<?php

declare(strict_types=1);

namespace Thallo\Commerce\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Commerce\Links\LinkReconciler;

/**
 * Design spec §6.2 reconcile row: removes links whose product is tombstoned/absent or whose
 * entry is gone; healthy links are untouched. Batch-limited by
 * `thallo-commerce.reconcile.batch_size` (a PER-INVOCATION cap across every tenant this run
 * processes, not per-tenant -- re-run the command to continue past the cap). Tenant-safe: with
 * no `--tenant`, tenants are discovered from DISTINCT `thallo_commerce_product_links` rows
 * (this pack's own table), never a full tenant registry scan.
 *
 * No constructor override (mirrors
 * {@see \Thallo\Tenancy\Console\SingleStoreRepairCommand}/
 * {@see \Thallo\Tenancy\Console\TenancyDiagnoseCommand}): {@see LinkReconciler} is resolved
 * lazily via {@see BaseCommand::getService()} inside execute(), never eagerly at construction --
 * this is what lets this pack's provider auto-discover the command (like every sibling pack's
 * commands) without risking a crash on ANY `php glueful ...` invocation when Commerce's own
 * provider happens to be inactive (see {@see LinkReconciler}'s own docblock).
 */
#[AsCommand(
    name: 'thallo:commerce:links:reconcile',
    description: 'Remove links whose product is tombstoned/absent or whose entry is gone.',
)]
final class ReconcileLinksCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit the sweep to a single tenant uuid.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reconciler = $this->getService(LinkReconciler::class);

        if (!$reconciler->isCommerceActive()) {
            $this->warning('Commerce is not active in this installation -- nothing to reconcile.');
            return self::SUCCESS;
        }

        $context = $this->getContext();
        $batchSize = max(0, (int) config($context, 'thallo-commerce.reconcile.batch_size', 500));

        $tenantOption = $input->getOption('tenant');
        $tenants = is_string($tenantOption) && $tenantOption !== ''
            ? [$tenantOption]
            : $reconciler->discoverTenants();

        $remaining = $batchSize;
        $totalRemoved = 0;
        foreach ($tenants as $tenant) {
            if ($remaining <= 0) {
                break;
            }
            $stale = $reconciler->scanTenant($context, $tenant, $remaining);
            $removed = $reconciler->remove($context, $stale);
            $remaining -= $removed;
            $totalRemoved += $removed;
            $this->line(sprintf(
                '%s: removed %d stale link(s).',
                $tenant === '' ? '(sentinel)' : $tenant,
                $removed,
            ));
        }

        $this->success(sprintf(
            'Reconcile complete: %d stale link(s) removed across %d tenant(s) (batch limit %d).',
            $totalRemoved,
            count($tenants),
            $batchSize,
        ));

        return self::SUCCESS;
    }
}
