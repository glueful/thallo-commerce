<?php

declare(strict_types=1);

namespace Thallo\Commerce\Console;

use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Commerce\Http\Shop\GuestOrderCookie;

/**
 * Retention sweep for `thallo_commerce_checkout_attempts` (storefront-rendering spec §7):
 * "Attempt rows expire with the `guest_confirmation_days` retention sweep." Deletes every row
 * whose `created_at` is older than the window, REGARDLESS of `status` — a `pending` row this old
 * is orphaned (its owning transaction either committed to `completed` long ago or rolled back
 * entirely; nothing legitimately stays `pending` past one request). Tenant-safe: `--tenant`
 * scopes the sweep to one tenant (mirrors {@see \Thallo\Commerce\Console\ReconcileLinksCommand}'s
 * identical option); with none, every tenant's expired rows are removed in one pass. No
 * constructor override — {@see Connection} is resolved lazily via
 * {@see BaseCommand::getService()} inside execute(), matching this pack's other discovered
 * commands.
 */
#[AsCommand(
    name: 'thallo:commerce:checkout:purge-attempts',
    description: 'Delete checkout-attempt ledger rows older than the guest-confirmation retention window.',
)]
final class PurgeCheckoutAttemptsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limit the purge to a single tenant uuid.');
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Override the retention window in days (default: thallo-commerce.guest_confirmation_days, clamped 1-90).'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();

        $daysOption = $input->getOption('days');
        $days = is_string($daysOption) && $daysOption !== ''
            ? max(1, min(90, (int) $daysOption))
            : GuestOrderCookie::confirmationDays($context);

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$days} days")
            ->format('Y-m-d H:i:s');

        $connection = $this->getService(Connection::class);
        $query = $connection->table('thallo_commerce_checkout_attempts')
            ->where('created_at', '<', $cutoff);

        $tenantOption = $input->getOption('tenant');
        $tenant = is_string($tenantOption) && $tenantOption !== '' ? $tenantOption : null;
        if ($tenant !== null) {
            $query->where('tenant_uuid', '=', $tenant);
        }

        $removed = $query->delete();

        $this->success(sprintf(
            'Purge complete: %d checkout attempt(s) older than %d day(s) removed%s.',
            $removed,
            $days,
            $tenant !== null ? " (tenant {$tenant})" : '',
        ));

        return self::SUCCESS;
    }
}
