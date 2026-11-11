<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\WebhookId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookId::class)]
final class WebhookIdTest extends TestCase
{
    public function testTheSpecificationExampleIsAccepted(): void
    {
        self::assertSame('msg_2KWPBgLlAfxdpx2AI54pPJ85f4W', WebhookId::fromString('msg_2KWPBgLlAfxdpx2AI54pPJ85f4W')->value);
    }

    public function testAnIdOfTheMaximumLengthIsAccepted(): void
    {
        self::assertSame(255, \strlen(WebhookId::fromString(str_repeat('a', 255))->value));
    }

    public function testAnIdOneByteTooLongIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A webhook id is at most 255 bytes; this one is 256.');

        WebhookId::fromString(str_repeat('a', 256));
    }

    public function testAnEmptyIdIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A webhook id cannot be empty.');

        WebhookId::fromString('');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedIds(): iterable
    {
        yield 'a full stop, which delimits the signed content' => ['msg.1'];
        yield 'a space' => ['msg 1'];
        yield 'a tab' => ["msg\t1"];
        yield 'a line break' => ["msg\n1"];
        yield 'a non-ASCII character' => ['msg_é'];
        yield 'a NUL byte' => ["msg\x001"];
        yield 'a trailing line break' => ["msg_1\n"];
    }

    #[DataProvider('refusedIds')]
    public function testAnIdWithACharacterOutsideTheSetIsRefused(string $id): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A webhook id is printable ASCII without spaces or full stops.');

        WebhookId::fromString($id);
    }

    public function testTwoIdsWithTheSameValueAreEqual(): void
    {
        self::assertTrue(WebhookId::fromString('msg_1')->equals(WebhookId::fromString('msg_1')));
        self::assertFalse(WebhookId::fromString('msg_1')->equals(WebhookId::fromString('msg_2')));
    }
}
