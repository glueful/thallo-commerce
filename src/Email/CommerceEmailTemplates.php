<?php

declare(strict_types=1);

namespace Thallo\Commerce\Email;

use Glueful\Extensions\Contracts\Email\EmailTemplateDefinition;
use Glueful\Extensions\Contracts\Email\EmailTemplatePlaceholder;

/**
 * The four order-lifecycle email templates (store-settings spec §4.1), registered with the
 * email-notification extension's EmailTemplateRegistry so they appear — editable, with
 * placeholder chips and test-send — in the EXISTING Settings › Email page. Owner is this pack;
 * re-registering the same keys under the same owner is allowed by the registry, so per-request
 * provider boots are safe.
 *
 * No order links in v1 (an honest omission): the storefront confirmation page authenticates by
 * guest-order cookie, so an emailed link would not open for the buyer. Tokenized order-status
 * links are the noted follow-up.
 */
final class CommerceEmailTemplates
{
    public const KEYS = [
        'commerce.order_confirmation',
        'commerce.order_paid',
        'commerce.order_fulfilled',
        'commerce.order_canceled',
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
