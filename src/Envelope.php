<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks;

use JsonException;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\Exception\WebhookRejected;

/**
 * The JSON payload a delivery carries: the recommended `type`, `timestamp` and `data` of Standard Webhooks,
 * plus an optional `audience`.
 *
 * **Read only after the signature verified**, from the same raw body.
 *
 * `audience` is the extension a sender needs when one key signs for many receivers: without a recipient
 * under the signature, a delivery received by one receiver still verifies at another for as long as the
 * timestamp tolerance lasts. A receiver that expects an audience refuses an envelope that does not name it.
 *
 * The body and `data` are JSON objects; a non-empty list is refused. Decoded to arrays, `{}` and `[]` are the
 * same value, so an empty body fails on its missing `type` and an empty `data` is accepted as an empty object.
 */
final readonly class Envelope
{
    /**
     * @param array<mixed> $data a JSON object; an empty one decodes the same as an empty list
     */
    private function __construct(
        public string $type,
        public ?string $timestamp,
        public array $data,
        public ?string $audience,
    ) {
    }

    public static function fromJson(string $rawBody): self
    {
        try {
            $decoded = json_decode($rawBody, true, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw InvalidWebhookValue::because(\sprintf('The webhook body is not JSON: %s.', $jsonException->getMessage()));
        }

        if (!\is_array($decoded) || array_is_list($decoded) && [] !== $decoded) {
            throw InvalidWebhookValue::because('The webhook body is a JSON object.');
        }

        $type = $decoded['type'] ?? null;

        if (!\is_string($type) || '' === $type) {
            throw InvalidWebhookValue::because('The webhook envelope carries a non-empty string "type".');
        }

        $timestamp = $decoded['timestamp'] ?? null;
        $data = $decoded['data'] ?? [];
        $audience = $decoded['audience'] ?? null;

        if (null !== $timestamp && !\is_string($timestamp)) {
            throw InvalidWebhookValue::because('The webhook envelope "timestamp" is a string.');
        }

        if (!\is_array($data) || array_is_list($data) && [] !== $data) {
            throw InvalidWebhookValue::because('The webhook envelope "data" is an object.');
        }

        if (null !== $audience && !\is_string($audience)) {
            throw InvalidWebhookValue::because('The webhook envelope "audience" is a string.');
        }

        return new self($type, $timestamp, $data, $audience);
    }

    /**
     * Strict equality: an audience is an identifier, never a pattern.
     */
    public function assertAddressedTo(string $audience): void
    {
        if ($this->audience !== $audience) {
            throw WebhookRejected::audienceMismatch($this->audience, $audience);
        }
    }
}
