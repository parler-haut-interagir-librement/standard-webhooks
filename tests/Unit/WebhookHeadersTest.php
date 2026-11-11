<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use Phil\StandardWebhooks\Base64;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\WebhookHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookHeaders::class)]
final class WebhookHeadersTest extends TestCase
{
    public function testTheHeadersRoundTrip(): void
    {
        $signature = 'v1,'.Base64::encodePublic(str_repeat("\x01", 32));

        $headers = WebhookHeaders::fromStrings('msg_1', '1614265330', $signature);

        self::assertSame(
            ['webhook-id' => 'msg_1', 'webhook-timestamp' => '1614265330', 'webhook-signature' => $signature],
            $headers->toArray(),
        );
    }

    public function testAMissingIdIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('The "webhook-id" header is missing.');

        WebhookHeaders::fromStrings(null, '1', 'v1,x');
    }

    public function testAMissingTimestampIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('The "webhook-timestamp" header is missing.');

        WebhookHeaders::fromStrings('msg_1', null, 'v1,x');
    }

    public function testAMissingSignatureIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('The "webhook-signature" header is missing.');

        WebhookHeaders::fromStrings('msg_1', '1', null);
    }
}
