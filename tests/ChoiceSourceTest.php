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

use function array_map;
use function array_unique;
use function array_values;
use function count;
use function serialize;
use function sort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

#[CoversClass(ChoiceSource::class)]
#[UsesClass(ChoiceSequence::class)]
#[UsesClass(ChoiceTree::class)]
#[UsesClass(ChoiceTreeNode::class)]
#[UsesClass(ExactDistribution::class)]
#[UsesClass(Span::class)]
#[UsesClass(TooManyChoicesException::class)]
#[UsesClass(UniformDistribution::class)]
#[Small]
final class ChoiceSourceTest extends TestCase
{
    public function testDrawsChoicesAtRandomWithinTheirRange(): void
    {
        $source = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar(1)));

        for ($i = 0; $i < 100; $i++) {
            $choice = $source->draw(5, new UniformDistribution);

            $this->assertGreaterThanOrEqual(0, $choice);
            $this->assertLessThanOrEqual(5, $choice);
        }
    }

    public function testRecordsTheChoicesItDraws(): void
    {
        $source = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar(1)));

        $choices = [];

        for ($i = 0; $i < 10; $i++) {
            $choices[] = $source->draw(1000, new UniformDistribution);
        }

        $this->assertSame($choices, $source->sequence()->choices());
    }

    public function testDrawsTheSameChoicesForTheSameSeed(): void
    {
        $first  = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar(42)));
        $second = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar(42)));

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(
                $first->draw(1000000, new UniformDistribution),
                $second->draw(1000000, new UniformDistribution),
            );
        }
    }

    public function testReplaysTheChoicesOfASequence(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([3, 1, 4]));

        $this->assertSame(3, $source->draw(10, new UniformDistribution));
        $this->assertSame(1, $source->draw(10, new UniformDistribution));
        $this->assertSame(4, $source->draw(10, new UniformDistribution));
        $this->assertSame([3, 1, 4], $source->sequence()->choices());
    }

    public function testReplaysZeroForChoicesBeyondTheEndOfTheSequence(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([3]));

        $this->assertSame(3, $source->draw(10, new UniformDistribution));
        $this->assertSame(0, $source->draw(10, new UniformDistribution));
        $this->assertSame(0, $source->draw(10, new UniformDistribution));
        $this->assertSame([3, 0, 0], $source->sequence()->choices());
    }

    public function testRecordsTheLargestChoiceThatEachChoiceItDrawsCouldHaveBeen(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([7, 1]));

        $source->draw(10, new UniformDistribution);
        $source->draw(1, new UniformDistribution);
        $source->draw(5, new UniformDistribution);

        $sequence = $source->sequence();

        $this->assertSame([7, 1, 0], $sequence->choices());
        $this->assertSame(10, $sequence->maximumAt(0));
        $this->assertSame(1, $sequence->maximumAt(1));
        $this->assertSame(5, $sequence->maximumAt(2));
    }

    public function testClampsAReplayedChoiceThatExceedsItsRange(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([7]));

        $this->assertSame(2, $source->draw(2, new UniformDistribution));
        $this->assertSame([2], $source->sequence()->choices());
    }

    public function testDoesNotDrawMoreThanTheMaximumNumberOfChoices(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([]), 2);

        $source->draw(1, new UniformDistribution);
        $source->draw(1, new UniformDistribution);

        $this->expectException(TooManyChoicesException::class);
        $this->expectExceptionMessageIs('Cannot draw more than 2 choices');

        $source->draw(1, new UniformDistribution);
    }

    public function testRecordsSpansOfTheChoicesThatWereDrawnBetweenTheirStartAndEndInTheOrderInWhichTheyWereStarted(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([]));

        $source->startSpan('outer');
        $source->draw(1, new UniformDistribution);
        $source->startSpan('inner');
        $source->draw(1, new UniformDistribution);
        $source->draw(1, new UniformDistribution);
        $source->endSpan();
        $source->endSpan();

        $this->assertSame(
            [
                ['start' => 0, 'end' => 3, 'length' => 3, 'label' => 'outer'],
                ['start' => 1, 'end' => 3, 'length' => 2, 'label' => 'inner'],
            ],
            array_map(
                static fn (Span $span): array => ['start' => $span->start(), 'end' => $span->end(), 'length' => $span->length(), 'label' => $span->label()],
                $source->sequence()->spans(),
            ),
        );
    }

    public function testRecordsTheSpanThatASpanIsPartOf(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([]));

        $source->startSpan('outer');
        $source->startSpan('first');
        $source->draw(1, new UniformDistribution);
        $source->endSpan();
        $source->startSpan('second');
        $source->draw(1, new UniformDistribution);
        $source->endSpan();
        $source->endSpan();
        $source->startSpan('next');
        $source->draw(1, new UniformDistribution);
        $source->endSpan();

        $spans = $source->sequence()->spans();

        $this->assertSame(['outer', 'first', 'second', 'next'], array_map(static fn (Span $span): string => $span->label(), $spans));
        $this->assertSame([null, 0, 0, null], array_map(static fn (Span $span): ?int => $span->parent(), $spans));
    }

    public function testDoesNotRecordSpansWithoutChoices(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([]));

        $source->startSpan('outer');
        $source->startSpan('empty');
        $source->endSpan();
        $source->startSpan('inner');
        $source->draw(1, new UniformDistribution);
        $source->endSpan();
        $source->endSpan();

        $spans = $source->sequence()->spans();

        $this->assertSame(['outer', 'inner'], array_map(static fn (Span $span): string => $span->label(), $spans));
        $this->assertSame([null, 0], array_map(static fn (Span $span): ?int => $span->parent(), $spans));
    }

    public function testDoesNotRecordSpansThatWereNotEnded(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([]));

        $source->startSpan('outer');
        $source->startSpan('ended');
        $source->draw(1, new UniformDistribution);
        $source->endSpan();
        $source->startSpan('not ended');
        $source->draw(1, new UniformDistribution);

        $this->assertSame(
            [['label' => 'ended', 'parent' => null]],
            array_map(
                static fn (Span $span): array => ['label' => $span->label(), 'parent' => $span->parent()],
                $source->sequence()->spans(),
            ),
        );
    }

    public function testChoosesTheElementAtTheDrawnIndex(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([2, 0, 9]));

        $this->assertSame('c', $source->choose(['a', 'b', 'c'], new UniformDistribution));
        $this->assertSame('a', $source->choose(['a', 'b', 'c'], new UniformDistribution));
        $this->assertSame('c', $source->choose(['a', 'b', 'c'], new UniformDistribution));
    }

    public function testSuggestsACandidateWithTheGivenProbabilityWhileChoicesAreDrawnAtRandom(): void
    {
        $source = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar(1)));

        $this->assertSame('candidate', $source->suggest(['candidate'], 1.0));
        $this->assertNull($source->suggest(['candidate'], 0.0));
        $this->assertSame([], $source->sequence()->choices());
    }

    public function testSuggestsEachOfTheCandidates(): void
    {
        $source      = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar(1)));
        $suggestions = [];

        for ($i = 0; $i < 100; $i++) {
            $suggestions[] = $source->suggest(['a', 'b', 'c'], 1.0);
        }

        sort($suggestions);

        $this->assertSame(['a', 'b', 'c'], array_values(array_unique($suggestions)));
    }

    public function testDoesNotSuggestACandidateWhileASequenceIsReplayed(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([]));

        $this->assertNull($source->suggest(['candidate'], 1.0));
    }

    public function testDoesNotDrawTheChoicesOfAnExampleThatWasGeneratedBefore(): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(1));
        $tree       = new ChoiceTree;
        $sequences  = [];

        for ($i = 0; $i < 10; $i++) {
            $source = ChoiceSource::random($randomizer, ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES, $tree);

            $source->draw(1, new UniformDistribution);
            $source->draw(4, new UniformDistribution);
            $source->exampleGenerated();

            $sequences[] = $source->sequence()->choices();
        }

        $this->assertSame(10, count(array_unique(array_map(serialize(...), $sequences))));
        $this->assertTrue($tree->isExhausted());
    }

    public function testRecordsTheChoicesOfAReplayedSequenceInTheChoiceTree(): void
    {
        $tree   = new ChoiceTree;
        $source = ChoiceSource::replay(new ChoiceSequence([0]), ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES, $tree);

        $source->draw(1, new UniformDistribution);
        $source->exampleGenerated();

        $source = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar(1)), ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES, $tree);

        $this->assertSame(1, $source->draw(1, new ExactDistribution(0)));
    }

    public function testNoLongerUsesTheChoiceTreeWhenAChoiceIsDrawnFromADifferentRangeThanBefore(): void
    {
        $tree   = new ChoiceTree;
        $source = ChoiceSource::replay(new ChoiceSequence([0]), ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES, $tree);

        $source->draw(1, new UniformDistribution);
        $source->exampleGenerated();

        $source = ChoiceSource::replay(new ChoiceSequence([0]), ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES, $tree);

        $source->draw(2, new UniformDistribution);

        $this->assertNull($tree->root());
    }

    public function testKnowsWhetherAChoiceWasDrawnFromADifferentRangeThanBefore(): void
    {
        $tree   = new ChoiceTree;
        $source = ChoiceSource::replay(new ChoiceSequence([0]), ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES, $tree);

        $source->draw(1, new UniformDistribution);
        $source->exampleGenerated();

        $this->assertFalse($source->drewDifferentlyThanBefore());

        $source = ChoiceSource::replay(new ChoiceSequence([0]), ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES, $tree);

        $source->draw(2, new UniformDistribution);

        $this->assertTrue($source->drewDifferentlyThanBefore());
    }

    public function testDrawsChoicesOfAnExampleThatWasGeneratedBeforeWhenEveryExampleWasGenerated(): void
    {
        $tree   = new ChoiceTree;
        $source = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar(1)), ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES, $tree);

        $source->draw(0, new UniformDistribution);
        $source->exampleGenerated();

        $this->assertTrue($tree->isExhausted());

        $source = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar(1)), ChoiceSource::DEFAULT_MAXIMUM_NUMBER_OF_CHOICES, $tree);

        $this->assertSame(0, $source->draw(0, new UniformDistribution));
    }
}
