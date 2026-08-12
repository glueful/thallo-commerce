<?php

declare(strict_types=1);

namespace Thallo\Commerce\Email;

/**
 * The typed, SAFE result of one {@see PaymentRequestMailer} attempt (payment-links spec §2.4:
 * "delivery failure returns a typed safe result").
 *
 * Three fields, and the omissions are the contract. There is no transport message, no exception,
 * no rendered subject or body, no recipient, and no URL — every one of those can quote either the
 * bearer token that was just mailed or the SMTP credentials that mailed it, and this object is
 * what the delivery ledger and the HTTP receipt are both built from. `errorCode` is drawn from
 * the closed vocabulary below, so a caller branches on a constant rather than on prose.
 *
 * `SEND_FAILED` deliberately collapses every transport-side failure into one code. The channel's
 * own richer codes (`transport_misconfigured`, `blocked_domain`, `transport_exception`, ...) are
 * useful in a log and hostile in a receipt: they distinguish states an operator cannot act on
 * differently (the remedy for all of them is the same — fix the mail configuration and send
 * again with a NEW key) while widening what an admin response says about the install.
 */
final readonly class PaymentRequestSendResult
{
    /** No rich `email` channel is registered — an install-level fact ({@see RichEmailAvailability}). */
    public const EMAIL_UNAVAILABLE = 'email_unavailable';

    /** The merchant's `payment_request` switch is off — a deliberate choice, not a fault. */
    public const TEMPLATE_DISABLED = 'template_disabled';

    /** The order carries no usable address, so there is nobody to send to. */
    public const NO_RECIPIENT = 'no_recipient';

    /** The transport refused or threw. One code for every such mode — see the class docblock. */
    public const SEND_FAILED = 'send_failed';

    private function __construct(
        public bool $sent,
        public ?string $errorCode,
        public ?string $providerMessageId,
    ) {
    }

    public static function sent(?string $providerMessageId): self
    {
        return new self(true, null, $providerMessageId);
    }

    public static function refused(string $errorCode): self
    {
        return new self(false, $errorCode, null);
    }

    /** @return array{sent:bool, error_code:string|null, provider_message_id:string|null} */
    public function toArray(): array
    {
        return [
            'sent' => $this->sent,
            'error_code' => $this->errorCode,
            'provider_message_id' => $this->providerMessageId,
        ];
    }
}
