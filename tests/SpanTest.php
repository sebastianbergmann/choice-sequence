<?php declare(strict_types=1);
/*
 * This file is part of sebastian/choice-sequence.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\ChoiceSequence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(Span::class)]
#[Small]
final class SpanTest extends TestCase
{
    public function testHasTheIndexesOfItsFirstChoiceAndOfTheChoiceAfterItsLastChoice(): void
    {
        $span = new Span(2, 5, 'element', null);

        $this->assertSame(2, $span->start());
        $this->assertSame(5, $span->end());
    }

    public function testHasTheNumberOfItsChoices(): void
    {
        $this->assertSame(3, new Span(2, 5, 'element', null)->length());
        $this->assertSame(1, new Span(0, 1, 'element', null)->length());
    }

    public function testHasALabel(): void
    {
        $this->assertSame('element', new Span(2, 5, 'element', null)->label());
    }

    public function testMayBePartOfAnotherSpan(): void
    {
        $this->assertSame(0, new Span(2, 5, 'element', 0)->parent());
    }

    public function testMayNotBePartOfAnotherSpan(): void
    {
        $this->assertNull(new Span(2, 5, 'element', null)->parent());
    }

    public function testMayHaveAKind(): void
    {
        $this->assertSame(SpanKind::Element, new Span(2, 5, 'element', null, SpanKind::Element)->kind());
    }

    public function testMayNotHaveAKind(): void
    {
        $this->assertNull(new Span(2, 5, 'element', null)->kind());
    }
}
