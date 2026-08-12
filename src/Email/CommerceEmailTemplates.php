<?php

declare(strict_types=1);

namespace Thallo\Commerce\Email;

use Glueful\Extensions\Contracts\Email\EmailTemplateDefinition;
use Glueful\Extensions\Contracts\Email\EmailTemplatePlaceholder;

/**
 * The four order-lifecycle email templates (store-settings spec §4.1) plus the payment-request
 * template (payment-links spec §2.4), registered with the email-notification extension's
 * EmailTemplateRegistry so they appear — editable, with placeholder chips and test-send — in the
 * EXISTING Settings › Email page. Owner is this pack; re-registering the same keys under the same
 * owner is allowed by the registry, so per-request provider boots are safe.
 *
 * The four ORDER templates carry no links (an honest omission): the storefront confirmation page
 * authenticates by guest-order cookie, so an emailed link would not open for the buyer.
 * `commerce.payment_request` is the exception and the reason it can be one is that its link is a
 * bearer credential composed and injected at SEND time only — see that definition's own comment.
 */
final class CommerceEmailTemplates
{
    public const KEYS = [
        'commerce.order_confirmation',
        'commerce.order_paid',
        'commerce.order_fulfilled',
        'commerce.order_canceled',
        'commerce.payment_request',
    ];

    public const OWNER = 'thallo-commerce';

    /** @return list<EmailTemplateDefinition> */
    public static function definitions(): array
    {
        return [
            new EmailTemplateDefinition(
                key: 'commerce.order_confirmation',
                label: 'Order confirmation',
                description: 'Sent to the buyer the moment an order is placed.',
                defaultSubject: 'Order {{order_number}} received — {{store_name}}',
                defaultBody: self::body(
                    'Thanks for your order!',
                    'We\'ve received order <strong>{{order_number}}</strong> for '
                    . '<strong>{{total}}</strong>. We\'ll email you again as it progresses.',
                ),
                placeholders: self::placeholders(),
                owner: self::OWNER,
            ),
            new EmailTemplateDefinition(
                key: 'commerce.order_paid',
                label: 'Payment received',
                description: 'Sent to the buyer when an order\'s payment completes.',
                defaultSubject: 'Payment received for {{order_number}} — {{store_name}}',
                defaultBody: self::body(
                    'Payment received',
                    'Your payment of <strong>{{total}}</strong> for order '
                    . '<strong>{{order_number}}</strong> has been received. Thank you!',
                ),
                placeholders: self::placeholders(),
                owner: self::OWNER,
            ),
            new EmailTemplateDefinition(
                key: 'commerce.order_fulfilled',
                label: 'Order fulfilled',
                description: 'Sent to the buyer when an order is fulfilled/shipped.',
                defaultSubject: 'Order {{order_number}} is on its way — {{store_name}}',
                defaultBody: self::body(
                    'Your order is on its way',
                    'Order <strong>{{order_number}}</strong> has been fulfilled. '
                    . 'If it ships physically, it\'s now with the carrier.',
                ),
                placeholders: self::placeholders(),
                owner: self::OWNER,
            ),
            new EmailTemplateDefinition(
                key: 'commerce.order_canceled',
                label: 'Order canceled',
                description: 'Sent to the buyer when an order is canceled.',
                defaultSubject: 'Order {{order_number}} canceled — {{store_name}}',
                defaultBody: self::body(
                    'Your order was canceled',
                    'Order <strong>{{order_number}}</strong> ({{total}}) has been canceled. '
                    . 'If you were charged, the amount will be refunded through your payment method.',
                ),
                placeholders: self::placeholders(),
                owner: self::OWNER,
            ),
            // Payment links Task 12 (payment-links spec §2.4). The ONE template here that is not
            // an order-lifecycle notice: it asks a customer to PAY, and the thing that makes it
            // work is a live bearer credential.
            //
            // The link uses the EXISTING, already-validated `action_url` placeholder — never a
            // new URL placeholder of this pack's own. That is a security decision, not a
            // convention: the email extension's own formatter neutralises non-http(s) schemes in
            // exactly the `action_url`/`reset_url` slots ({@see
            // \Glueful\Extensions\EmailNotification\EmailFormatter::format()}), so a
            // `javascript:`/`data:` payload edited into a template can never reach an href here.
            //
            // The STORED template is token-free by construction: it holds only `{{action_url}}`,
            // and {@see PaymentRequestMailer} substitutes the composed URL at SEND time, for the
            // duration of one channel call. A merchant editing this template in Settings › Email
            // therefore never sees, saves, or leaks a payment token.
            new EmailTemplateDefinition(
                key: 'commerce.payment_request',
                label: 'Payment request',
                description: 'Sent to the customer with a secure link to pay an admin-created order.',
                defaultSubject: 'Payment requested for {{order_number}} — {{store_name}}',
                defaultBody: self::body(
                    'Your payment link is ready',
                    'Order <strong>{{order_number}}</strong> is awaiting payment of '
                    . '<strong>{{total}}</strong>.',
                )
                . "<p><a href=\"{{action_url}}\">Pay for order {{order_number}}</a></p>\n"
                . "<p>This link expires on <strong>{{expires_at}}</strong>. "
                . "Please don't forward it — anyone holding it can pay this order.</p>\n",
                placeholders: self::paymentRequestPlaceholders(),
                owner: self::OWNER,
            ),
        ];
    }

    /**
     * The payment-request chips. Deliberately NOT {@see self::placeholders()}: this template has
     * no `status` (the order is always awaiting payment) and no `customer_email` (echoing the
     * recipient's own address back into the body earns nothing and widens what the rendered
     * message carries), and it has two of its own — the validated `action_url` link slot and the
     * `expires_at` chip §2.4 requires so the customer can see the deadline they are working to.
     *
     * @return list<EmailTemplatePlaceholder>
     */
    private static function paymentRequestPlaceholders(): array
    {
        return [
            new EmailTemplatePlaceholder('order_number', 'The order\'s number', 'ORD-1042'),
            new EmailTemplatePlaceholder('total', 'The amount due, formatted', '$89.00'),
            new EmailTemplatePlaceholder('store_name', 'The site/store name', 'Thallo'),
            new EmailTemplatePlaceholder('expires_at', 'When the payment link expires (UTC)', '2026-08-19 09:00:00'),
            new EmailTemplatePlaceholder(
                'action_url',
                'The secure payment link (substituted at send time; never stored)',
                'https://example.com/checkout/pay/EXAMPLE',
            ),
        ];
    }

    /** @return list<EmailTemplatePlaceholder> */
    private static function placeholders(): array
    {
        return [
            new EmailTemplatePlaceholder('order_number', 'The order\'s number', 'ORD-1042'),
            new EmailTemplatePlaceholder('customer_email', 'The buyer\'s email address', 'buyer@example.com'),
            new EmailTemplatePlaceholder('total', 'The order\'s grand total, formatted', '$89.00'),
            new EmailTemplatePlaceholder('status', 'The order\'s status after this event', 'paid'),
            new EmailTemplatePlaceholder('store_name', 'The site/store name', 'Thallo'),
        ];
    }

    /** Short, neutral HTML consistent with the email extension's own built-ins. */
    private static function body(string $heading, string $paragraph): string
    {
        return "<h2>{$heading}</h2>\n"
            . "<p>{$paragraph}</p>\n"
            . "<p>— {{store_name}}</p>\n";
    }
}
