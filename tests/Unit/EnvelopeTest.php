<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use Phil\StandardWebhooks\Envelope;
use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\Exception\RejectionReason;
use Phil\StandardWebhooks\Exception\WebhookRejected;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Envelope::class)]
#[CoversClass(WebhookRejected::class)]
final class EnvelopeTest extends TestCase
{
    public function testTheRecommendedFieldsAreRead(): void
    {
        $envelope = Envelope::fromJson('{"type":"invoice.paid","timestamp":"2026-10-31T12:00:00Z","data":{"id":"abc"},"audience":"receiver-1"}');

        self::assertSame('invoice.paid', $envelope->type);
        self::assertSame('2026-10-31T12:00:00Z', $envelope->timestamp);
        self::assertSame(['id' => 'abc'], $envelope->data);
        self::assertSame('receiver-1', $envelope->audience);
    }

    /**
     * JSON decoded to arrays cannot tell `{}` from `[]`: an empty `data` is the empty object.
     */
    public function testEmptyDataIsAccepted(): void
    {
        self::assertSame([], Envelope::fromJson('{"type":"a","data":[]}')->data);
    }

    public function testOnlyTheTypeIsRequired(): void
    {
        $envelope = Envelope::fromJson('{"type":"ping"}');

        self::assertNull($envelope->timestamp);
        self::assertSame([], $envelope->data);
        self::assertNull($envelope->audience);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'a list' => ['[1,2]'];
        yield 'a scalar' => ['"text"'];
        yield 'no type' => ['{"data":{}}'];
        yield 'an empty type' => ['{"type":""}'];
        yield 'a numeric type' => ['{"type":5}'];
        yield 'a numeric timestamp' => ['{"type":"a","timestamp":5}'];
        yield 'scalar data' => ['{"type":"a","data":"x"}'];
        yield 'list data' => ['{"type":"a","data":[1,2]}'];
        yield 'a numeric audience' => ['{"type":"a","audience":5}'];
    }

    public function testAListIsRefusedAsNotAnObject(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('The webhook body is a JSON object.');

        Envelope::fromJson('[1,2]');
    }

    /**
     * Sixty-four levels in all, the object included; one more is refused.
     */
    public function testTheNestingIsBounded(): void
    {
        $nested = static fn (int $depth): string => '{"type":"a","data":'.str_repeat('{"x":', $depth).'1'.str_repeat('}', $depth).'}';

        self::assertSame('a', Envelope::fromJson($nested(62))->type);

        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('not JSON');
        Envelope::fromJson($nested(63));
    }

    #[DataProvider('malformedBodies')]
    public function testAMalformedEnvelopeIsRefused(string $body): void
    {
        $this->expectException(InvalidWebhookValue::class);

        Envelope::fromJson($body);
    }

    public function testAnEnvelopeAddressedToThisReceiverPasses(): void
    {
        $this->expectNotToPerformAssertions();

        Envelope::fromJson('{"type":"a","audience":"receiver-1"}')->assertAddressedTo('receiver-1');
    }

    public function testAnEnvelopeAddressedToAnotherReceiverIsRejected(): void
    {
        try {
            Envelope::fromJson('{"type":"a","audience":"receiver-2"}')->assertAddressedTo('receiver-1');
            self::fail('The envelope was accepted.');
        } catch (WebhookRejected $webhookRejected) {
            self::assertSame(RejectionReason::AudienceMismatch, $webhookRejected->reason);
            self::assertSame('The webhook envelope is addressed to "receiver-2", not to "receiver-1".', $webhookRejected->getMessage());
        }
    }

    public function testAnEnvelopeThatNamesNoAudienceIsRejected(): void
    {
        $this->expectException(WebhookRejected::class);
        $this->expectExceptionMessageIsOrContains('The webhook envelope names no audience; "receiver-1" was expected.');

        Envelope::fromJson('{"type":"a"}')->assertAddressedTo('receiver-1');
    }
}
