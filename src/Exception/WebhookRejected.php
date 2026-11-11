<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Exception;

use RuntimeException;

/**
 * A well-formed delivery that the receiver refuses. Nothing in it may be processed, and the response is
 * never `2xx`.
 */
final class WebhookRejected extends RuntimeException implements WebhookException
{
    private function __construct(
        public readonly RejectionReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function timestampOutsideTolerance(int $skewSeconds, int $toleranceSeconds): self
    {
        return new self(
            RejectionReason::TimestampOutsideTolerance,
            \sprintf('The webhook timestamp is %d seconds from the receiver clock; the tolerance is %d.', $skewSeconds, $toleranceSeconds),
        );
    }

    public static function noValidSignature(): self
    {
        return new self(RejectionReason::NoValidSignature, 'No webhook signature verifies under a trusted key.');
    }

    public static function audienceMismatch(?string $received, string $expected): self
    {
        return new self(
            RejectionReason::AudienceMismatch,
            null === $received
                ? \sprintf('The webhook envelope names no audience; "%s" was expected.', $expected)
                : \sprintf('The webhook envelope is addressed to "%s", not to "%s".', $received, $expected),
        );
    }
}
