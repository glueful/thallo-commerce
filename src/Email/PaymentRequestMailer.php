<?php

declare(strict_types=1);

namespace Thallo\Commerce\Email;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Notifications\Contracts\Notifiable;
use Thallo\Commerce\Settings\CommerceSettingsStore;

use function config;

/**
 * The dedicated, SYNCHRONOUS payment-request mailer (payment-links spec §2.4).
 *
 * ## Why this does not use the notification pipeline — the custody rule of this task
 *
 * The message this class sends carries a payment link, and a payment link is a BEARER
 * CREDENTIAL: whoever holds the URL can open the payer's page and start a checkout with it.
 * `Glueful\Notifications\Services\NotificationService::send()` persists the full notification
 * payload before dispatching (`NotificationService.php:164` — `$this->repository->save($notification)`),
 * and queues the same payload for any asynchronous channel. Routing this send through it would
 * therefore write a live bearer token into the `notifications` table, into
 * `notification_deliveries`, and into the queue — durable copies that outlive the link, are not
 * covered by the link's own revocation, and are readable by anything with database access.
 *
 * So this class resolves the registered rich `email` channel through
 * {@see RichEmailAvailability} and calls `sendNotification()` on it DIRECTLY — exactly the shape
 * the email extension's own template test-send uses. Nothing is persisted, nothing is queued, and
 * the URL exists only in the request's memory and in the message that leaves the transport.
 * `PaymentRequestMailerTest`'s persistence audit sweeps every notification and queue table for
 * the token after a real send, and pins that this file never so much as names `NotificationService`.
 *
 * ## Substitution happens HERE, at send time, and nowhere else
 *
 * The stored `payment_request` template is token-free: it carries the EXISTING validated
 * `action_url` placeholder ({@see \Glueful\Extensions\EmailNotification\EmailFormatter} already
 * neutralises non-http(s) schemes in that slot, which is exactly why §2.4 forbids inventing a new
 * URL placeholder). The composed URL is injected into `template_data` for the duration of one
 * `sendNotification()` call, so an operator editing the template in Settings › Email can never
 * see, save, or leak a token.
 *
 * ## Never queued, never retried
 *
 * A failure returns a typed {@see PaymentRequestSendResult} and stops. Auto-queueing a retry
 * would mean persisting the payload (the very thing above) and would also mean an email arriving
 * for a link the operator has since regenerated. The recovery path is explicit and operator-driven:
 * a NEW `Idempotency-Key`, or a regenerate.
 */
final class PaymentRequestMailer
{
    /** The registry key for the editable template (registered by {@see CommerceEmailTemplates}). */
    public const TEMPLATE_KEY = 'commerce.payment_request';

    /** The short switch name — `thallo-commerce.email.{name}.enabled`, default FALSE. */
    public const TEMPLATE_NAME = 'payment_request';

    /** The notification `type` this send declares; never a persisted record's type. */
    public const NOTIFICATION_TYPE = 'commerce_payment_request';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly RichEmailAvailability $availability,
    ) {
    }

    /**
     * The merchant's per-template switch: a stored `thallo-commerce.email.payment_request.enabled`
     * row wins, else the pack config default — which spec §2.4 requires to be an EXPLICIT `false`
     * (the generic Emails-tab fallback is `true`, so an omitted config key would silently arm a
     * surface that mails bearer credentials).
     *
     * Mirrors {@see SendOrderEmails::templateEnabled()}'s identical soft-read posture: a settings
     * problem can only ever fall back to the config default, never break a send decision.
     */
    public function enabled(): bool
    {
        try {
            $container = $this->context->getContainer();
            if ($container->has(CommerceSettingsStore::class)) {
                $stored = $container->get(CommerceSettingsStore::class)
                    ->get('thallo-commerce.email.' . self::TEMPLATE_NAME . '.enabled');
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

        return (bool) config($this->context, 'thallo-commerce.email.' . self::TEMPLATE_NAME . '.enabled', false);
    }

    /**
     * Send one payment-request email. Synchronous, at-most-one attempt.
     *
     * @param array<string,string> $placeholders the template's non-URL chips (order number,
     *     total, store name, expiry) — the URL is added here, never passed in pre-merged
     */
    public function send(string $email, string $actionUrl, array $placeholders): PaymentRequestSendResult
    {
        if (!$this->enabled()) {
            return PaymentRequestSendResult::refused(PaymentRequestSendResult::TEMPLATE_DISABLED);
        }

        $channel = $this->availability->richChannel();
        if ($channel === null) {
            return PaymentRequestSendResult::refused(PaymentRequestSendResult::EMAIL_UNAVAILABLE);
        }

        $recipient = trim($email);
        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return PaymentRequestSendResult::refused(PaymentRequestSendResult::NO_RECIPIENT);
        }

        try {
            $result = $channel->sendNotification($this->notifiable($recipient), [
                'type' => self::NOTIFICATION_TYPE,
                'template_name' => self::TEMPLATE_KEY,
                // Substitution at send time only — see the class docblock.
                'template_data' => $placeholders + ['action_url' => $actionUrl],
            ]);
        } catch (\Throwable) {
            // Deliberately unbound and un-inspected: a transport throwable's message and its
            // backtrace arguments can quote the URL just handed to it, the recipient, or the
            // SMTP credentials. It becomes ONE safe code and nothing else.
            return PaymentRequestSendResult::refused(PaymentRequestSendResult::SEND_FAILED);
        }

        return $result->success
            ? PaymentRequestSendResult::sent($result->providerMessageId)
            : PaymentRequestSendResult::refused(PaymentRequestSendResult::SEND_FAILED);
    }

    /**
     * An email-only {@see Notifiable} whose identity is a HASH of the address, mirroring
     * {@see SendOrderEmails::recipient()} — the channel logs `notifiable_id` on some failure
     * paths, and a hash keeps a buyer address out of those lines.
     */
    private function notifiable(string $email): Notifiable
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
                return 'commerce_payer';
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
