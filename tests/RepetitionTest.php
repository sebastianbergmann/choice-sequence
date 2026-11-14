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

use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function max;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

#[CoversClass(Repetition::class)]
#[UsesClass(BernoulliDistribution::class)]
#[UsesClass(ChoiceSequence::class)]
#[UsesClass(ChoiceSource::class)]
#[UsesClass(ExactDistribution::class)]
#[UsesClass(Span::class)]
#[UsesClass(TooManyDuplicateElementsException::class)]
#[UsesClass(UniformDistribution::class)]
#[Small]
final class RepetitionTest extends TestCase
{
    /**
     * @return array<string, array{positive-int}>
     */
    public static function maximumSizeProvider(): array
    {
        return [
            'a maximum size of 20'   => [20],
            'a maximum size of 100'  => [100],
            'a maximum size of 1000' => [1000],
        ];
    }

    public function testDrawsTheElementsThatTheMinimumSizeRequiresWithoutAChoiceAndEveryOtherElementAfterTheChoiceToAddIt(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([5, 6, 1, 7, 1, 8, 0]));

        $this->assertSame([5, 6, 7, 8], $this->elements(new Repetition('element', 2, null), $source));
        $this->assertSame([5, 6, 1, 7, 1, 8, 0], $source->sequence()->choices());
    }

    public function testMarksEachElementAndTheChoiceThatEndsTheCollectionAsASpanOfTheKindElement(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([5, 1, 7, 0]));

        $this->elements(new Repetition('element', 1, null), $source);

        $spans = [];

        foreach ($source->sequence()->spans() as $span) {
            $spans[] = [$span->start(), $span->end(), $span->label(), $span->kind()];
        }

        $this->assertSame(
            [
                [0, 1, 'element', SpanKind::Element],
                [1, 3, 'element', SpanKind::Element],
                [3, 4, 'element', SpanKind::Element],
            ],
            $spans,
        );
    }

    public function testDoesNotDrawAChoiceToAddAnotherElementWhenTheCollectionHasItsMaximumSize(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([1, 5, 1, 6, 1, 7]));

        $this->assertSame([5, 6], $this->elements(new Repetition('element', 0, 2), $source));
        $this->assertSame([1, 5, 1, 6], $source->sequence()->choices());
    }

    public function testDoesNotCountAnElementThatWasNotAddedToTheCollection(): void
    {
        $source   = ChoiceSource::replay(new ChoiceSequence([5, 5, 6]));
        $elements = [];

        new Repetition('element', 2, 2)->repeat(
            $source,
            static function () use ($source, &$elements): bool
            {
                $element = $source->draw(9, new UniformDistribution);

                if (isset($elements[$element])) {
                    return false;
                }

                $elements[$element] = true;

                return true;
            },
        );

        $this->assertSame([5 => true, 6 => true], $elements);
    }

    public function testStopsWhenNoElementWasAddedInSeveralAttemptsAndTheCollectionHasItsMinimumSize(): void
    {
        $source   = ChoiceSource::replay(new ChoiceSequence([1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1]));
        $attempts = 0;

        new Repetition('element', 0, null)->repeat(
            $source,
            static function () use (&$attempts): bool
            {
                $attempts++;

                return false;
            },
        );

        $this->assertSame(10, $attempts);
    }

    public function testCannotDrawTheElementsThatTheMinimumSizeRequiresWhenNoElementIsAddedInSeveralAttempts(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([]));

        $this->expectException(TooManyDuplicateElementsException::class);

        new Repetition('element', 1, null)->repeat($source, static fn (): bool => false);
    }

    /**
     * @param positive-int $maximumSize
     */
    #[DataProvider('maximumSizeProvider')]
    public function testSomeCollectionsWithAMaximumSizeHaveThatSizeAndSomeTheSizeNextToIt(int $maximumSize): void
    {
        $sizes = $this->sizes(new Repetition('element', 0, $maximumSize), 20000);

        $this->assertContains($maximumSize, $sizes);
        $this->assertContains($maximumSize - 1, $sizes);
    }

    /**
     * @param positive-int $maximumSize
     */
    #[DataProvider('maximumSizeProvider')]
    public function testTheMeanSizeOfCollectionsGrowsByAboutAThirdBecauseSomeOfThemAimForTheirMaximumSize(int $maximumSize): void
    {
        $withoutMaximumSize = $this->meanOf($this->sizes(new Repetition('element', 0, null), 20000));
        $withMaximumSize    = $this->meanOf($this->sizes(new Repetition('element', 0, $maximumSize), 20000));

        $this->assertGreaterThan(1.15 * $withoutMaximumSize, $withMaximumSize);
        $this->assertLessThan(1.5 * $withoutMaximumSize, $withMaximumSize);
    }

    public function testCollectionsWithoutAMaximumSizeDoNotAimForALargeSize(): void
    {
        $this->assertLessThan(100, max($this->sizes(new Repetition('element', 0, null), 20000)));
    }

    public function testACollectionWhoseMinimumSizeIsNextToItsMaximumSizeDoesNotAimForItsMaximumSize(): void
    {
        $sizes = $this->sizes(new Repetition('element', 99, 100, 0.5), 2000);

        // without aiming for the maximum size, the choice to add the last
        // element is drawn with the probability of another element
        $this->assertEqualsWithDelta(99.5, $this->meanOf($sizes), 0.05);
    }

    public function testACollectionThatNeverGetsAnotherElementDoesNotAimForItsMaximumSize(): void
    {
        $this->assertSame(5, max($this->sizes(new Repetition('element', 5, 100, 0.0), 2000)));
    }

    public function testACollectionThatAlwaysGetsAnotherElementHasItsMaximumSize(): void
    {
        $sizes = $this->sizes(new Repetition('element', 0, 30, 1.0), 200);

        $this->assertSame([30], array_values(array_unique($sizes)));
    }

    public function testAChoiceSequenceIsReplayedAsTheSameCollectionWhetherOrNotTheCollectionAimedForItsMaximumSize(): void
    {
        $repetition = new Repetition('element', 0, 50);
        $randomizer = new Randomizer(new Xoshiro256StarStar(1));
        $sizes      = [];

        for ($i = 0; $i < 2000; $i++) {
            $source   = ChoiceSource::random($randomizer);
            $elements = $this->elements($repetition, $source);
            $sizes[]  = count($elements);

            $this->assertSame($elements, $this->elements($repetition, ChoiceSource::replay($source->sequence())));
        }

        $this->assertContains(50, $sizes);
    }

    /**
     * @return list<non-negative-int>
     */
    private function elements(Repetition $repetition, ChoiceSource $source): array
    {
        $elements = [];

        $repetition->repeat(
            $source,
            static function () use ($source, &$elements): bool
            {
                $elements[] = $source->draw(9, new UniformDistribution);

                return true;
            },
        );

        return $elements;
    }

    /**
     * @param positive-int $numberOfCollections
     *
     * @return non-empty-list<non-negative-int>
     */
    private function sizes(Repetition $repetition, int $numberOfCollections): array
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(1));
        $sizes      = [count($this->elements($repetition, ChoiceSource::random($randomizer)))];

        for ($i = 1; $i < $numberOfCollections; $i++) {
            $sizes[] = count($this->elements($repetition, ChoiceSource::random($randomizer)));
        }

        return $sizes;
    }

    /**
     * @param non-empty-list<non-negative-int> $sizes
     */
    private function meanOf(array $sizes): float
    {
        return array_sum($sizes) / count($sizes);
    }
}
