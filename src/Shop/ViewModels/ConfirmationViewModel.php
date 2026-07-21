<?php

declare(strict_types=1);

namespace Thallo\Commerce\Shop\ViewModels;

use Glueful\Extensions\Commerce\Support\Money;

/**
 * Closed order-confirmation projection (storefront-rendering spec §6/§8/§11): built from an
 * already-ownership-checked `commerce_orders` row (never a raw row passed to the template) plus
 * that order's most recent payment-related event, which is what distinguishes a genuinely
 * `pending_payment` order from one whose payment FAILED TO INITIATE (`payment_init_failed`,
 * {@see \Glueful\Extensions\Commerce\Orders\CheckoutService::initiatePayment()}'s own recorded
 * event) — both share the identical `pending_payment` order status, so the status column alone
 * cannot tell them apart. The confirmation route NEVER re-calls the payment collector to derive
 * this — it is read entirely from already-persisted state (spec §8: "never a browser-supplied
 * paid/failed verdict").
 */
final class ConfirmationViewModel
{
    private function __construct(
        public readonly string $orderRef,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly string $email,
        public readonly int $grandTotal,
        public readonly string $grandTotalFormatted,
        public readonly string $currency,
        public readonly ?string $placedAt,
    ) {
    }

    /**
     * @param array<string,mixed> $order an ownership-checked `commerce_orders` row
     * @param list<array<string,mixed>> $events that order's `commerce_order_events`, in
     *     ascending (oldest-first) order —
     *     {@see \Glueful\Extensions\Commerce\Orders\OrderRepository::eventsForOrder()}'s shape
     */
    public static function fromOrder(array $order, array $events): self
    {
        $status = (string) $order['status'];
        $currency = (string) $order['currency'];
        $grandTotal = (int) $order['grand_total'];

        return new self(
            orderRef: (string) $order['order_number'],
            status: $status,
            statusLabel: self::label($status, $events),
            email: (string) $order['email'],
            grandTotal: $grandTotal,
            grandTotalFormatted: Money::format($grandTotal, $currency),
            currency: $currency,
            placedAt: isset($order['placed_at']) ? (string) $order['placed_at'] : null,
        );
    }

    /** @param list<array<string,mixed>> $events ascending (oldest-first) */
    private static function label(string $status, array $events): string
    {
        if ($status === 'pending_payment' && self::latestPaymentEventFailed($events)) {
            return 'Payment failure';
        }

        return match ($status) {
            'pending_payment' => 'Payment pending',
            'paid' => 'Paid',
            'fulfilled' => 'Fulfilled',
            'canceled' => 'Canceled',
            'refunded' => 'Refunded',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /**
     * The most recent event whose type is `payment_initiated` OR `payment_init_failed` decides
     * this — an order with NEITHER (payment initiation hasn't run at all yet) is genuinely
     * pending, not failed.
     *
     * @param list<array<string,mixed>> $events ascending (oldest-first)
     */
    private static function latestPaymentEventFailed(array $events): bool
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            $type = (string) ($events[$i]['type'] ?? '');
            if ($type === 'payment_init_failed') {
                return true;
            }
            if ($type === 'payment_initiated') {
                return false;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'order_ref' => $this->orderRef,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'email' => $this->email,
            'grand_total' => $this->grandTotal,
            'grand_total_formatted' => $this->grandTotalFormatted,
            'currency' => $this->currency,
            'placed_at' => $this->placedAt,
        ];
    }
}
