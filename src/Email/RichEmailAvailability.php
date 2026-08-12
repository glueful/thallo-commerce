<?php

declare(strict_types=1);

namespace Thallo\Commerce\Email;

use Glueful\Notifications\Contracts\RichNotificationChannel;
use Glueful\Notifications\Services\ChannelManager;

/**
 * The ONE authority on "can this install send a payment-request email at all?" (payment-links
 * spec §2.4), shared by {@see PaymentRequestMailer} (which refuses a send without it) and by
 * `CommerceMetaController`'s MANDATORY `email_available` flag (which is how the admin SPA renders
 * a disabled-with-reason Send control instead of one that 503s on click).
 *
 * "Available" means exactly one thing: the framework's channel registry has an `email` channel
 * AND that channel implements {@see RichNotificationChannel}. The rich contract is required, not
 * preferred — the legacy `NotificationChannel::send(): bool` cannot report a provider message id
 * or a typed, retryability-bearing failure, and the delivery ledger's whole value is recording
 * WHICH of those happened. A bool-only channel is therefore reported unavailable rather than used
 * with a degraded receipt.
 *
 * This class NEVER hard-types `glueful/email-notification`: the concrete `EmailChannel` is an
 * optional extension, and a first-party pack that named it would turn an optional dependency into
 * a required one. It speaks only to the framework registry and the framework contract.
 *
 * ## Nothing here throws, ever
 *
 * The channel manager itself is soft (null when the framework's notification provider is not
 * bound at all), `hasChannel()` gates the lookup, and the lookup is additionally wrapped: a
 * missing or unusable email channel is an explicit `false`, evaluated AT REQUEST TIME. Spec §2.4
 * is explicit that this state is "a send refusal, never a boot failure" — and `/meta` must keep
 * answering 200 with `email_available: false` on exactly the installs where a throw would be
 * easiest to write.
 */
final class RichEmailAvailability
{
    /** The registry name the framework and every mail extension agree on. */
    public const CHANNEL = 'email';

    public function __construct(private readonly ?ChannelManager $channels = null)
    {
    }

    public function isAvailable(): bool
    {
        return $this->richChannel() !== null;
    }

    /** The registered rich `email` channel, or null when this install has none. */
    public function richChannel(): ?RichNotificationChannel
    {
        if ($this->channels === null) {
            return null;
        }

        try {
            if (!$this->channels->hasChannel(self::CHANNEL)) {
                return null;
            }
            $channel = $this->channels->getChannel(self::CHANNEL);
        } catch (\Throwable) {
            return null;
        }

        return $channel instanceof RichNotificationChannel ? $channel : null;
    }
}
