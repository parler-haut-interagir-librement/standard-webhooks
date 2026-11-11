<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Exception;

/**
 * Why a well-formed delivery was refused. The application maps each reason to its response and to its
 * own metrics; the library does not decide the status code.
 */
enum RejectionReason: string
{
    /** `webhook-timestamp` is further from the receiver's clock than the tolerance allows. */
    case TimestampOutsideTolerance = 'timestamp_outside_tolerance';

    /** No signature in `webhook-signature` verifies under any trusted key. */
    case NoValidSignature = 'no_valid_signature';

    /** The envelope does not name this receiver as its audience. */
    case AudienceMismatch = 'audience_mismatch';
}
