<?php

declare(strict_types=1);

namespace Thallo\Commerce\Email;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Events\OrderCanceled;
use Glueful\Extensions\Commerce\Events\OrderFulfilled;
use Glueful\Extensions\Commerce\Events\OrderPaid;
use Glueful\Extensions\Commerce\Events\OrderPlaced;
use Glueful\Extensions\Commerce\Support\CommerceSettings;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Services\NotificationService;
use Psr\Log\LoggerInterface;
use Thallo\Commerce\Settings\CommerceSettingsStore;
use Thallo\Commerce\Shop\ViewModels\ShopMoney;

/**
 * The buyer-facing order emails (store-settings spec §4.2): one listener over Commerce's four
 * order-lifecycle events, sending through the notification pipeline with `template_name` keyed
 * into the email-template registry — so every send renders the merchant's saved override (or
 * the default) from Settings › Email.
 *
 * Discipline, in order of importance:
 *  - A mail failure NEVER fails commerce: every send is wrapped; checkout/fulfillment must
 *    succeed with a broken transport (the notification system's retry queue is the recovery
 *    channel, and the log line here is the trace).
 *  - At-most-once per template×order: `idempotency_key = commerce-email:{key}:{orderUuid}` — a
 *    payment retry re-dispatching OrderPlaced, or an admin double-click on fulfill, cannot
 *    double-send.
 *  - An order with no usable email address skips silently (guest data drift is not an error
 *    worth a 500 anywhere near checkout).
 *
 * OrderPlaced AND OrderPaid firing near-simultaneously for card checkouts is accepted v1
 * behavior (Woo sends two as well); per-template on/off switches are the noted follow-up.
 *
 * This sender is registered ONLY when the engine's own {@see
 * \Glueful\Extensions\Commerce\Mail\OrderMailListener} stands down (`commerce.email.enabled`
 * false — the documented default; see `CommerceIntegrationServiceProvider::registerOrderEmails()`),
 * so it is the ONE OrderPlaced sender an out-of-the-box install actually runs. {@see
 * self::onOrderPlaced()} therefore mirrors that engine listener's admin-origin confirmation gate
 * verbatim, so the toggle behaves identically regardless of which sender is live.
 */
final class SendOrderEmails
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly NotificationService $notifications,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The ONE handler the admin-origin confirmation toggle governs, mirroring
     * {@see \Glueful\Extensions\Commerce\Mail\OrderMailListener::onOrderPlaced()}'s identical
     * gate: an admin-created (walk-in) order is handed over in person, so a merchant may
     * reasonably want no "we received your order" mail for it while the pack's own
     * `thallo-commerce.email.order_confirmation.enabled` switch stays on. The engine-side
     * `commerce.order_confirmation` key (default TRUE — {@see CommerceSettings::orderConfirmation()})
     * is consulted ONLY for `origin === 'admin'`, so storefront/legacy orders (origin absent or
     * `'storefront'`) are byte-identical to before this gate existed.
     */
    public function onOrderPlaced(OrderPlaced $event): void
    {
        $origin = (string) ($event->order['origin'] ?? 'storefront');
        if ($origin === 'admin' && !CommerceSettings::orderConfirmation($this->context)) {
            return;
        }

        $this->send('commerce.order_confirmation', $event->order);
    }

    public function onOrderPaid(OrderPaid $event): void
    {
        $this->send('commerce.order_paid', $event->order);
    }

    public function onOrderFulfilled(OrderFulfilled $event): void
    {
        $this->send('commerce.order_fulfilled', $event->order);
    }

    public function onOrderCanceled(OrderCanceled $event): void
    {
        $this->send('commerce.order_canceled', $event->order);
    }

    /** @param array<string,mixed> $order */
    private function send(string $templateKey, array $order): void
    {
        if (!$this->templateEnabled($templateKey)) {
            return; // Switched off in the Emails tab — deliberate merchant choice, not an error.
        }

        try {
            $email = trim((string) ($order['email'] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return; // No usable buyer address — silently skip, never an error.
            }

            $data = $this->placeholderData($order, $email);
            $subject = $this->subjectFor($templateKey, $data);

            $this->notifications->send(
                'commerce_order_email',
                $this->recipient($email),
                $subject,
                ['template_name' => $templateKey] + $data,
                [
                    'channels' => ['email'],
                    'idempotency_key' => 'commerce-email:' . $templateKey . ':' . (string) ($order['uuid'] ?? ''),
                ],
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('Commerce order email failed (order flow unaffected).', [
                'template' => $templateKey,
                'order_uuid' => (string) ($order['uuid'] ?? ''),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,string>
     */
    private function placeholderData(array $order, string $email): array
    {
        $currency = strtoupper(trim((string) ($order['currency'] ?? 'USD')));
        $grandTotal = (int) ($order['grand_total'] ?? 0);

        return [
            'order_number' => (string) ($order['order_number'] ?? ''),
            'customer_email' => $email,
            'total' => preg_match('/^[A-Z]{3}$/', $currency) === 1
                ? ShopMoney::display($grandTotal, $currency)
                : (string) $grandTotal,
            'status' => (string) ($order['status'] ?? ''),
            'store_name' => $this->storeName(),
        ];
    }

    /**
     * The wire `subject` is a fallback header only — EmailFormatter renders the template's own
     * subject (override or default) when `template_name` resolves; this mirrors it plainly so a
     * registry-less send still reads sensibly.
     *
     * @param array<string,string> $data
     */
    private function subjectFor(string $templateKey, array $data): string
    {
        $label = match ($templateKey) {
            'commerce.order_paid' => 'Payment received for',
            'commerce.order_fulfilled' => 'Update for',
            'commerce.order_canceled' => 'Cancellation of',
            default => 'Order',
        };

        return trim("{$label} {$data['order_number']} — {$data['store_name']}");
    }

    /**
     * The per-template switch (spec §4.2 follow-up): a stored
     * `thallo-commerce.email.{name}.enabled` row wins ('0' disables), else the pack config
     * default (ON). Same discipline as every read near the mail path: a settings problem can
     * only ever fall back to the default, never block or break a send decision.
     */
    private function templateEnabled(string $templateKey): bool
    {
        $name = str_starts_with($templateKey, 'commerce.') ? substr($templateKey, 9) : $templateKey;

        try {
            $container = $this->context->getContainer();
            if ($container->has(CommerceSettingsStore::class)) {
                $stored = $container->get(CommerceSettingsStore::class)
                    ->get("thallo-commerce.email.{$name}.enabled");
                if (is_string($stored)) {
                    $flag = strtolower(trim($stored));
                    if (in_array($flag, ['1', 'true', '0', 'false'], true)) {
                        return in_array($flag, ['1', 'true'], true);
                    }
                }
            }
        } catch (\Throwable) {
            // fall through to the config default
        }

        return (bool) config($this->context, "thallo-commerce.email.{$name}.enabled", true);
    }

    private function storeName(): string
    {
        // Through the pack-owned settings contract the host binds; config is the fallback.
        try {
            $container = $this->context->getContainer();
            if ($container->has(CommerceSettingsStore::class)) {
                $stored = $container->get(CommerceSettingsStore::class)->get('site_name');
                if (is_string($stored) && trim($stored) !== '') {
                    return $stored;
                }
            }
        } catch (\Throwable) {
            // fall through to config
        }

        return (string) config($this->context, 'thallo.site_name', 'Thallo');
    }

    private function recipient(string $email): Notifiable
    {
        return new class ($email) implements Notifiable {
            public function __construct(private readonly string $email)
            {
            }

            public function routeNotificationFor(string $channel): ?string
            {
                return $channel === 'email' ? $this->email : null;
            }

            public function getNotifiableId(): string
            {
                return hash('sha256', strtolower($this->email));
            }

            public function getNotifiableType(): string
            {
                return 'commerce_buyer';
            }

            public function shouldReceiveNotification(string $notificationType, string $channel): bool
            {
                return $channel === 'email';
            }

            /** @return array{email:true} */
            public function getNotificationPreferences(): array
            {
                return ['email' => true];
            }
        };
    }
}
