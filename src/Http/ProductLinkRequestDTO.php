<?php

declare(strict_types=1);

namespace Thallo\Commerce\Http;

use Glueful\Validation\Rules\Length;
use Glueful\Validation\Rules\Required;
use Glueful\Validation\Rules\Sanitize;
use Glueful\Validation\Rules\Type;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Validator;

/** Validates the PUT /commerce/products/{productUuid}/link body. */
final class ProductLinkRequestDTO
{
    public function __construct(
        public readonly string $entryUuid,
        public readonly ?string $expectedEntryUuid,
    ) {
    }

    /**
     * @param array<string,mixed> $body
     * @throws ValidationException
     */
    public static function fromRequest(array $body): self
    {
        $rules = [
            'entry_uuid' => [new Required(), new Sanitize(['trim']), new Type('string'), new Length(1, 191)],
            'expected_entry_uuid' => [new Sanitize(['trim']), new Type('string'), new Length(0, 191)],
        ];

        $validator = new Validator($rules);
        $errors = $validator->validate([
            'entry_uuid' => $body['entry_uuid'] ?? null,
            'expected_entry_uuid' => $body['expected_entry_uuid'] ?? null,
        ]);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $clean = $validator->filtered();
        $expected = is_string($clean['expected_entry_uuid'] ?? null) && $clean['expected_entry_uuid'] !== ''
            ? $clean['expected_entry_uuid']
            : null;

        return new self((string) $clean['entry_uuid'], $expected);
    }
}
