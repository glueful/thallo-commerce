<?php

declare(strict_types=1);

namespace Thallo\Commerce\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Commerce\Diagnostics\CommerceIntegrationDiagnostics;

/**
 * Exposes {@see CommerceIntegrationDiagnostics} on the CLI, mirroring
 * {@see \Thallo\Tenancy\Console\TenancyDiagnoseCommand} (the established per-pack diagnose
 * command convention) -- distinct from Commerce's own `commerce:diagnose`, which diagnoses the
 * Commerce extension itself, not this pack's integration layer.
 *
 * No constructor override, same rationale as {@see ReconcileLinksCommand}: the diagnostics
 * service is resolved lazily inside execute(), never eagerly at construction.
 */
#[AsCommand(
    name: 'thallo:commerce:diagnose',
    description: 'Run read-only Commerce integration diagnostics.',
)]
final class CommerceDiagnoseCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->getService(CommerceIntegrationDiagnostics::class)->report();
        foreach ($report['sections'] as $name => $section) {
            $this->line(strtoupper($section['status']) . ' ' . $name . ': '
                . json_encode($section['detail'], JSON_UNESCAPED_SLASHES));
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
