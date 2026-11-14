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
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pool::class)]
#[UsesClass(ChoiceSequence::class)]
#[UsesClass(ChoiceSource::class)]
#[UsesClass(ExactDistribution::class)]
#[UsesClass(NoValueToReferToException::class)]
#[UsesClass(Span::class)]
#[UsesClass(UniformDistribution::class)]
#[Small]
final class PoolTest extends TestCase
{
    public function testHasItsLabel(): void
    {
        $this->assertSame('pool', new Pool('pool')->label());
    }

    public function testHasNoValuesUntilAValueIsAdded(): void
    {
        /** @var Pool<string> $pool */
        $pool = new Pool('pool');

        $this->assertTrue($pool->isEmpty());
        $this->assertSame(0, $pool->count());

        $pool->add(ChoiceSource::replay(new ChoiceSequence([])), 'a');

        $this->assertFalse($pool->isEmpty());
        $this->assertSame(1, $pool->count());
    }

    public function testMarksAValueThatIsAddedWithAChoiceThatIsAlwaysZeroInASpanOfTheKindReferable(): void
    {
        /** @var Pool<string> $pool */
        $pool   = new Pool('pool');
        $source = ChoiceSource::replay(new ChoiceSequence([5]));

        $pool->add($source, 'a');

        $sequence = $source->sequence();

        $this->assertSame([0], $sequence->choices());
        $this->assertSame(0, $sequence->maximumAt(0));
        $this->assertCount(1, $sequence->spans());
        $this->assertSame('pool', $sequence->spans()[0]->label());
        $this->assertSame(SpanKind::Referable, $sequence->spans()[0]->kind());
    }

    public function testRefersToAValueByItsPositionCountedFromTheValueThatWasAddedLast(): void
    {
        $pool   = $this->poolWith('a', 'b', 'c');
        $source = ChoiceSource::replay(new ChoiceSequence([0, 2, 1]));

        $this->assertSame('c', $pool->refer($source));
        $this->assertSame('a', $pool->refer($source));
        $this->assertSame('b', $pool->refer($source));
        $this->assertSame(3, $pool->count());
    }

    public function testAChoiceThatIsGreaterThanTheLastPositionRefersToTheValueThatWasAddedFirst(): void
    {
        $this->assertSame('a', $this->poolWith('a', 'b', 'c')->refer(ChoiceSource::replay(new ChoiceSequence([9]))));
    }

    public function testMarksAReferenceAsASpanOfTheKindReference(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([1]));

        $this->poolWith('a', 'b', 'c')->refer($source);

        $sequence = $source->sequence();

        $this->assertSame([1], $sequence->choices());
        $this->assertSame(2, $sequence->maximumAt(0));
        $this->assertCount(1, $sequence->spans());
        $this->assertSame('pool', $sequence->spans()[0]->label());
        $this->assertSame(SpanKind::Reference, $sequence->spans()[0]->kind());
    }

    public function testRemovesAValueThatIsConsumed(): void
    {
        $pool   = $this->poolWith('a', 'b', 'c');
        $source = ChoiceSource::replay(new ChoiceSequence([1, 1]));

        $this->assertSame('b', $pool->consume($source));
        $this->assertSame(2, $pool->count());

        // without the second value, the first one is the second one counted
        // from the last one
        $this->assertSame('a', $pool->refer($source));
    }

    public function testMarksAReferenceThatConsumesItsValueAsASpanOfTheKindConsumingReference(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([0]));

        $this->poolWith('a', 'b')->consume($source);

        $sequence = $source->sequence();

        $this->assertSame(1, $sequence->maximumAt(0));
        $this->assertCount(1, $sequence->spans());
        $this->assertSame('pool', $sequence->spans()[0]->label());
        $this->assertSame(SpanKind::ConsumingReference, $sequence->spans()[0]->kind());
    }

    public function testCannotReferToAValueWhenItHasNone(): void
    {
        /** @var Pool<string> $pool */
        $pool = new Pool('pool');

        $this->expectException(NoValueToReferToException::class);

        $pool->refer(ChoiceSource::replay(new ChoiceSequence([])));
    }

    public function testCannotConsumeAValueWhenItHasNone(): void
    {
        /** @var Pool<string> $pool */
        $pool = new Pool('pool');

        $this->expectException(NoValueToReferToException::class);

        $pool->consume(ChoiceSource::replay(new ChoiceSequence([])));
    }

    /**
     * @return Pool<string>
     */
    private function poolWith(string ...$values): Pool
    {
        /** @var Pool<string> $pool */
        $pool   = new Pool('pool');
        $source = ChoiceSource::replay(new ChoiceSequence([]));

        foreach ($values as $value) {
            $pool->add($source, $value);
        }

        return $pool;
    }
}
