<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use Phil\StandardWebhooks\Exception\InvalidWebhookValue;

/**
 * The three headers of a Standard Webhooks delivery.
 */
final readonly class WebhookHeaders
{
    public const string ID = 'webhook-id';

    public const string TIMESTAMP = 'webhook-timestamp';

    public const string SIGNATURE = 'webhook-signature';

    public function __construct(
        public WebhookId $id,
        public WebhookTimestamp $timestamp,
        public SignatureList $signatures,
    ) {
    }

    /**
     * Reads the headers as received. A missing header makes the delivery malformed.
     */
    public static function fromStrings(?string $id, ?string $timestamp, ?string $signature): self
    {
        return new self(
            WebhookId::fromString(self::required(self::ID, $id)),
            WebhookTimestamp::fromHeader(self::required(self::TIMESTAMP, $timestamp)),
            SignatureList::fromHeader(self::required(self::SIGNATURE, $signature)),
        );
    }

    /**
     * @return array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}
     */
    public function toArray(): array
    {
        return [
            self::ID => $this->id->value,
            self::TIMESTAMP => $this->timestamp->toHeader(),
            self::SIGNATURE => $this->signatures->toHeader(),
        ];
    }

    private static function required(string $name, ?string $value): string
    {
        if (null === $value) {
            throw InvalidWebhookValue::because(\sprintf('The "%s" header is missing.', $name));
        }

        return $value;
    }
}
