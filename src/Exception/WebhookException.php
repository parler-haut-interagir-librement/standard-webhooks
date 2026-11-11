<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Exception;

use Throwable;

/**
 * Everything the library throws, catchable with a single `catch` at the HTTP boundary.
 *
 * Both kinds map to a permanent `4xx`: a sender must not retry a delivery that is malformed or that does
 * not verify, because the same bytes will fail the same way.
 */
interface WebhookException extends Throwable
{
}
