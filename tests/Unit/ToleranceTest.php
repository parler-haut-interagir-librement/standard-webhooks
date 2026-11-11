<?php

declare(strict_types=1);

namespace Phil\StandardWebhooks\Tests\Unit;

use Phil\StandardWebhooks\Exception\InvalidWebhookValue;
use Phil\StandardWebhooks\Tolerance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Tolerance::class)]
final class ToleranceTest extends TestCase
{
    public function testOneSecondIsTheSmallestTolerance(): void
    {
        self::assertSame(1, Tolerance::ofSeconds(1)->seconds);
    }

    public function testAZeroToleranceIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);
        $this->expectExceptionMessageIsOrContains('A timestamp tolerance is a positive number of seconds; got 0.');

        Tolerance::ofSeconds(0);
    }

    public function testANegativeToleranceIsRefused(): void
    {
        $this->expectException(InvalidWebhookValue::class);

        Tolerance::ofSeconds(-5);
    }
}
