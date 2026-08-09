<?php

declare(strict_types=1);

namespace Thallo\Commerce\Orders;

/**
 * TEMPORARY OWNERSHIP (orders-invoices-receipts plan, Task 4): renders one
 * {@see \Glueful\Extensions\Commerce\Http\Admin\OrderProjection::forAdmin()}-projected
 * `commerce_orders` row into its CSV cells for {@see \Thallo\Commerce\Http\
 * AdminOrderExportController} -- the allowlisted export columns, in this EXACT order, minor
 * units, no locale formatting. Neutralizes formula-injection triggers (a leading `=`, `+`, `-`,
 * `@`, tab, or CR) with a leading `'`, AFTER scalar serialization and BEFORE the controller's own
 * `fputcsv()` RFC-4180 quoting -- so a neutralized value that also needs quoting (e.g. it
 * contains the delimiter) still gets both protections, in that order.
 */
final class OrderCsvWriter
{
    /** The export's column allowlist, in table order -- also the CSV header row. */
    public const COLUMNS = [
        'order_number',
        'status',
        'fulfillment_status',
        'email',
        'currency',
        'subtotal',
        'discount_total',
        'shipping_total',
        'tax_total',
        'refunded_total',
        'grand_total',
        'discount_code',
        'shipping_method',
        'placed_at',
    ];

    /**
     * @param array<string,mixed> $projected an OrderProjection::forAdmin() row
     * @return list<string>
     */
    public static function row(array $projected): array
    {
        $cells = [];
        foreach (self::COLUMNS as $column) {
            $cells[] = self::neutralize(self::scalar($projected[$column] ?? null));
        }

        return $cells;
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /** Verbatim per the task brief -- see the class docblock for the ordering rationale. */
    private static function neutralize(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }
}
