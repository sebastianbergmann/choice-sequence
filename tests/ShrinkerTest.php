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

use const PHP_INT_MAX;
use function array_filter;
use function array_key_exists;
use function array_search;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function min;
use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Shrinker::class)]
#[CoversClass(ShrinkingResult::class)]
#[UsesClass(ChoiceSequence::class)]
#[UsesClass(Span::class)]
#[Small]
final class ShrinkerTest extends TestCase
{
    /**
     * @return array<string, array{non-negative-int, non-negative-int}>
     */
    public static function minimizationProvider(): array
    {
        return [
            'to a small value'                      => [9, 2],
            'to a value that a binary search finds' => [100, 50],
        ];
    }

    /**
     * @return array<string, array{list<non-negative-int>}>
     */
    public static function loweredChoiceWithoutSpansProvider(): array
    {
        return [
            'last span'  => [[2, 7, 8]],
            'first span' => [[2, 8, 9]],
        ];
    }

    /**
     * @return array<string, array{list<non-negative-int>}>
     */
    public static function raisedLaterChoiceProvider(): array
    {
        return [
            'nearest later choice'      => [[1, 7, 2, 7, 1, 7]],
            'next nearest later choice' => [[1, 7, 1, 7, 2, 7]],
        ];
    }

    public function testCountsTheAttemptsAndTheStepsThatMadeTheCounterexampleSimpler(): void
    {
        $attempts = 0;

        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$attempts): ?ChoiceSequence
            {
                $attempts++;

                if ($candidate->choices() === [0]) {
                    return $candidate;
                }

                return null;
            },
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([5]));

        $this->assertSame([0], $result->sequence()->choices());
        $this->assertSame(1, $result->numberOfSteps());
        $this->assertSame($attempts, $result->numberOfAttempts());
    }

    public function testDoesNotShrinkWhenNoAttemptMayBeMade(): void
    {
        $shrinker = new Shrinker(
            function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                $this->fail('No candidate is to be replayed');
            },
            0,
        );

        $result = $shrinker->shrink(new ChoiceSequence([3, 9, 27, 81]));

        $this->assertSame([3, 9, 27, 81], $result->sequence()->choices());
        $this->assertSame(0, $result->numberOfAttempts());
        $this->assertSame(0, $result->numberOfSteps());
        $this->assertFalse($result->budgetExhausted());
        $this->assertFalse($result->interrupted());
    }

    public function testStopsWithoutCountingFurtherAttemptsWhenItIsInterrupted(): void
    {
        $attempts = new ArrayObject;

        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use ($attempts): ?ChoiceSequence
            {
                $attempts->append($candidate);

                return null;
            },
            1000,
            static function () use ($attempts): bool
            {
                return count($attempts) === 2;
            },
        );

        $result = $shrinker->shrink(new ChoiceSequence([3, 9, 27, 81], [new Span(0, 2, 'element', null), new Span(2, 4, 'element', null)]));

        $this->assertCount(2, $attempts);
        $this->assertSame(2, $result->numberOfAttempts());
        $this->assertTrue($result->interrupted());
        $this->assertFalse($result->budgetExhausted());
        $this->assertSame([3, 9, 27, 81], $result->sequence()->choices());
    }

    public function testDoesNotReplayTheSameCandidateTwice(): void
    {
        $candidates = [];

        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$candidates): ?ChoiceSequence
            {
                $candidates[] = implode(',', $candidate->choices());

                return null;
            },
            1000,
        );

        $shrinker->shrink(new ChoiceSequence([3, 9, 27, 81], [new Span(0, 2, 'element', null), new Span(2, 4, 'element', null)]));

        $this->assertSame($candidates, array_values(array_unique($candidates)));
    }

    public function testReplacesASpanWithAShorterSpanThatItContainsAndThatHasTheSameLabel(): void
    {
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                if ($candidate->choices() !== [3]) {
                    return null;
                }

                return new ChoiceSequence([3], [new Span(0, 1, 'tree', null)]);
            },
            1000,
        );

        $result = $shrinker->shrink(
            new ChoiceSequence(
                [1, 2, 3, 4, 5],
                [new Span(0, 5, 'tree', null), new Span(1, 4, 'list', 0), new Span(2, 3, 'tree', 1)],
            ),
        );

        $this->assertSame([3], $result->sequence()->choices());
        $this->assertSame(1, $result->numberOfSteps());
    }

    public function testReplacesSpansOfTheSameLengthWithShorterSpansTheyContainAndThatHaveTheSameLabel(): void
    {
        $falsifying = [
            '2,4,5,6' => [new Span(0, 1, 'tree', null), new Span(1, 4, 'tree', null), new Span(2, 3, 'tree', 1)],
            '1,2,3,5' => [new Span(0, 3, 'tree', null), new Span(1, 2, 'tree', 0), new Span(3, 4, 'tree', null)],
            '2,5'     => [new Span(0, 1, 'tree', null), new Span(1, 2, 'tree', null)],
        ];

        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use ($falsifying): ?ChoiceSequence
            {
                $key = implode(',', $candidate->choices());

                if (!isset($falsifying[$key])) {
                    return null;
                }

                return new ChoiceSequence($candidate->choices(), $falsifying[$key]);
            },
            1000,
        );

        $result = $shrinker->shrink(
            new ChoiceSequence(
                [1, 2, 3, 4, 5, 6],
                [new Span(0, 3, 'tree', null), new Span(1, 2, 'tree', 0), new Span(3, 6, 'tree', null), new Span(4, 5, 'tree', 2)],
            ),
        );

        $this->assertSame([2, 5], $result->sequence()->choices());
        $this->assertSame(2, $result->numberOfSteps());
    }

    public function testDoesNotReplaceASpanWithASpanThatItContainsAndThatHasAnotherLabel(): void
    {
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                if ($candidate->choices() !== [3]) {
                    return null;
                }

                return new ChoiceSequence([3], [new Span(0, 1, 'leaf', null)]);
            },
            1000,
        );

        $result = $shrinker->shrink(
            new ChoiceSequence(
                [1, 2, 3, 4, 5],
                [new Span(0, 5, 'tree', null), new Span(2, 3, 'leaf', 0)],
            ),
        );

        $this->assertSame([1, 2, 3, 4, 5], $result->sequence()->choices());
    }

    public function testLowersAChoiceThatDeterminesTheLengthOfAStringWhileRaisingTheChoiceThatDeterminesTheLengthOfALaterString(): void
    {
        // lowering the length of the first string while deleting one of its
        // characters makes the strings too short together, unless the length
        // of the second string is raised by as much
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                [$sequence, $totalLength] = self::twoStrings($candidate);

                if ($totalLength < 3) {
                    return null;
                }

                return $sequence;
            },
            1000,
        );

        $result = $shrinker->shrink(self::twoStrings(new ChoiceSequence([2, 0, 0, 1, 0]))[0]);

        // the first string cannot become shorter than one character this
        // way, because a choice is only lowered while at least two spans of
        // the same kind follow it
        $this->assertSame([1, 0, 2, 0, 0], $result->sequence()->choices());
    }

    public function testStartsEachRoundOfPassesWithThePassesThatMadeTheCounterexampleSimplerMostOftenInTheRoundBefore(): void
    {
        $candidates = [];

        // only lowering the first choice while raising the second one to its
        // largest value makes the counterexample simpler in the first round
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$candidates): ?ChoiceSequence
            {
                $candidates[] = $candidate->choices();

                if ($candidate->choices() !== [4, PHP_INT_MAX]) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $shrinker->shrink(new ChoiceSequence([5, 5]));

        $index = array_search([4, PHP_INT_MAX], $candidates, true);

        $this->assertIsInt($index);
        $this->assertSame([[4, PHP_INT_MAX], [3, PHP_INT_MAX]], array_slice($candidates, $index, 2));
    }

    public function testSortsSpansThatArePartOfTheSameSpanAndHaveTheSameLabel(): void
    {
        $elements = [new Span(0, 2, 'element', null), new Span(2, 4, 'element', null)];

        $result = $this->shrinkerAccepting(['5,6,1,2' => $elements, '1,2,5,6' => $elements])->shrink(new ChoiceSequence([5, 6, 1, 2], $elements));

        $this->assertSame([1, 2, 5, 6], $result->sequence()->choices());
    }

    public function testDoesNotSortSpansThatHaveDifferentLabels(): void
    {
        $elements = [new Span(0, 2, 'element', null), new Span(2, 4, 'other element', null)];

        $result = $this->shrinkerAccepting(['5,6,1,2' => $elements, '1,2,5,6' => $elements])->shrink(new ChoiceSequence([5, 6, 1, 2], $elements));

        $this->assertSame([5, 6, 1, 2], $result->sequence()->choices());
    }

    public function testDoesNotSortSpansThatArePartOfDifferentSpans(): void
    {
        $elements = [new Span(0, 2, 'pair', null), new Span(0, 2, 'element', 0), new Span(2, 4, 'other pair', null), new Span(2, 4, 'element', 2)];

        $result = $this->shrinkerAccepting(['5,6,1,2' => $elements, '1,2,5,6' => $elements])->shrink(new ChoiceSequence([5, 6, 1, 2], $elements));

        $this->assertSame([5, 6, 1, 2], $result->sequence()->choices());
    }

    public function testSwapsAdjacentSpansThatAreOutOfOrderWhenSortingAllOfThemDoesNotKeepThePropertyFalsified(): void
    {
        $elements = [new Span(0, 2, 'element', null), new Span(2, 4, 'element', null), new Span(4, 6, 'element', null)];

        $result = $this->shrinkerAccepting(['5,6,3,4,1,2' => $elements, '3,4,5,6,1,2' => $elements])->shrink(new ChoiceSequence([5, 6, 3, 4, 1, 2], $elements));

        $this->assertSame([3, 4, 5, 6, 1, 2], $result->sequence()->choices());
    }

    public function testKeepsTheChoicesBetweenSpansThatAreSortedWhereTheyAre(): void
    {
        $elements = [new Span(0, 2, 'element', null), new Span(3, 5, 'element', null)];

        $result = $this->shrinkerAccepting(['5,6,9,1,2,9' => $elements, '1,2,9,5,6,9' => $elements])->shrink(new ChoiceSequence([5, 6, 9, 1, 2, 9], $elements));

        $this->assertSame([1, 2, 9, 5, 6, 9], $result->sequence()->choices());
    }

    public function testKeepsTheChoiceSequenceThatTheReplayOfACandidateConsumed(): void
    {
        $shrinker = new Shrinker(
            static fn (ChoiceSequence $candidate): ChoiceSequence => new ChoiceSequence([0]),
            1000,
        );

        $this->assertSame([0], $shrinker->shrink(new ChoiceSequence([5, 5, 5]))->sequence()->choices());
    }

    public function testDoesNotKeepAConsumedChoiceSequenceThatIsNotSimplerThanTheCurrentOne(): void
    {
        $shrinker = new Shrinker(
            static fn (ChoiceSequence $candidate): ChoiceSequence => new ChoiceSequence([5, 5, 5]),
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([5, 5, 5]));

        $this->assertSame([5, 5, 5], $result->sequence()->choices());
        $this->assertSame(0, $result->numberOfSteps());
    }

    public function testStopsMinimizingAChoiceThatTheConsumedChoiceSequenceNoLongerContains(): void
    {
        // the binary search on the only choice first tries 51, the replay of
        // which consumes no choice at all
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                if ($candidate->choices() === [51]) {
                    return new ChoiceSequence([]);
                }

                return null;
            },
            1000,
        );

        $this->assertSame([], $shrinker->shrink(new ChoiceSequence([100]))->sequence()->choices());
    }

    public function testStopsLoweringEqualChoicesThatTheConsumedChoiceSequenceDoesNotHave(): void
    {
        // lowering both choices together to a small value does not falsify
        // the property, lowering them to 51 does, but the replay of that
        // candidate consumes a different choice sequence
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                if ($candidate->choices() === [51, 51]) {
                    return new ChoiceSequence([50, 51]);
                }

                return null;
            },
            1000,
        );

        $this->assertSame(
            [50, 51],
            $shrinker->shrink(new ChoiceSequence([100, 100], [new Span(0, 1, 'element', null), new Span(1, 2, 'element', null)]))->sequence()->choices(),
        );
    }

    public function testDoesNotLowerChoicesThatAreNoLongerEqualTogether(): void
    {
        // lowering the first choices of both spans together falsifies the
        // property, but the replay of that candidate consumes a choice
        // sequence in which the second choices of the spans differ
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                if ($candidate->choices() === [0, 7, 0, 7]) {
                    return new ChoiceSequence([0, 7, 0, 8]);
                }

                return null;
            },
            1000,
        );

        $this->assertSame(
            [0, 7, 0, 8],
            $shrinker->shrink(new ChoiceSequence([5, 7, 5, 7], [new Span(0, 2, 'element', null), new Span(2, 4, 'element', null)]))->sequence()->choices(),
        );
    }

    public function testStopsRedistributingAPairOfChoicesThatTheConsumedChoiceSequenceDoesNotHave(): void
    {
        // moving everything from the first choice to the second one does not
        // falsify the property, moving half of it does, but the replay of
        // that candidate consumes a different choice sequence
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                $choices = $candidate->choices();

                if (count($choices) !== 2 || $choices[0] + $choices[1] !== 20 || $choices[1] > 15) {
                    return null;
                }

                if ($choices === [10, 10]) {
                    return new ChoiceSequence([10, 9]);
                }

                return $candidate;
            },
            1000,
        );

        $this->assertSame([10, 9], $shrinker->shrink(new ChoiceSequence([20, 0]))->sequence()->choices());
    }

    public function testFindsTheValuesWithTheSameLabelAgainWhenMovingAValueChangedWhichChoicesAreValues(): void
    {
        // once the first value is 0, the choice between the values is a value
        // as well, which is adjacent to both of them
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                $choices = [
                    self::choiceAt($candidate->choices(), 0, 10),
                    self::choiceAt($candidate->choices(), 1, 10),
                    self::choiceAt($candidate->choices(), 2, 10),
                ];

                if ($choices[0] + $choices[2] < 7) {
                    return null;
                }

                return self::twoValuesWithAChoiceBetweenThem($choices);
            },
            1000,
        );

        $result = $shrinker->shrink(self::twoValuesWithAChoiceBetweenThem([3, 0, 4]));

        $this->assertSame([0, 0, 7], $result->sequence()->choices());
    }

    public function testDeletesASpan(): void
    {
        // the span is longer than the blocks of consecutive choices that are
        // deleted, so only deleting the span deletes all of its choices at once
        $result = $this->shrinkerAccepting(['1,5' => [new Span(0, 1, 'element', null), new Span(1, 2, 'element', null)]])->shrink(
            new ChoiceSequence([1, 2, 3, 4, 5], [new Span(0, 1, 'element', null), new Span(1, 4, 'element', null), new Span(4, 5, 'element', null)]),
        );

        $this->assertSame([1, 5], $result->sequence()->choices());
        $this->assertSame(1, $result->numberOfSteps());
    }

    public function testDeletesASpanWhileRaisingTheChoiceThatDeterminesHowManySpansOfAKindThatItContainsALaterGroupOfSpansHas(): void
    {
        // the first of two strings, whose lengths are drawn before their
        // characters, is deleted while the length of the second string is
        // raised by the number of choices of the first string
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                if ($candidate->choices() !== [4, 7]) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $result = $shrinker->shrink(
            new ChoiceSequence(
                [2, 7, 7, 1, 7],
                [new Span(0, 3, 'string', null), new Span(1, 2, 'character', 0), new Span(2, 3, 'character', 0), new Span(3, 5, 'string', null), new Span(4, 5, 'character', 3)],
                [10, 25, 25, 10, 25],
            ),
        );

        $this->assertSame([4, 7], $result->sequence()->choices());
    }

    public function testReplacesLongerSpansFirstAndEachWithTheShortestSpansThatItContainsFirst(): void
    {
        $candidates = [];

        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$candidates): ?ChoiceSequence
            {
                $candidates[] = $candidate->choices();

                return null;
            },
            1000,
        );

        $shrinker->shrink(
            new ChoiceSequence(
                [1, 2, 3, 4, 5, 6, 7],
                [new Span(0, 7, 'tree', null), new Span(1, 4, 'tree', 0), new Span(2, 3, 'tree', 1), new Span(4, 6, 'tree', 0), new Span(5, 6, 'tree', 3)],
            ),
        );

        $replacements = [[3], [6], [5, 6], [2, 3, 4], [1, 3, 5, 6, 7], [1, 2, 3, 4, 6, 7]];

        $this->assertSame(
            $replacements,
            array_values(array_filter($candidates, static fn (array $candidate): bool => in_array($candidate, $replacements, true))),
        );
    }

    public function testMovesTheValueOfASpanThatConsistsOfOneChoiceToALaterValueWithTheSameLabelAsFarAsItCanTakeIt(): void
    {
        // the values are too far apart for redistributing them
        $spans  = [new Span(0, 1, 'value', null), new Span(1, 5, 'other', null), new Span(5, 6, 'value', null)];
        $maxima = [10, 10, 10, 10, 10, 10];

        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use ($spans, $maxima): ?ChoiceSequence
            {
                if ($candidate->choices() !== [2, 1, 1, 1, 1, 10]) {
                    return null;
                }

                return new ChoiceSequence($candidate->choices(), $spans, $maxima);
            },
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([7, 1, 1, 1, 1, 5], $spans, $maxima));

        $this->assertSame([2, 1, 1, 1, 1, 10], $result->sequence()->choices());
    }

    public function testMovesAValueToTheLaterValuesWithTheSameLabelLastOneFirstUntilItIsZeroOrCannotBeMoved(): void
    {
        // the last value cannot take more, the value before it takes all of
        // the first value, and moving the second value does not keep the
        // counterexample failing
        $spans = [
            new Span(0, 1, 'value', null),
            new Span(1, 5, 'other', null),
            new Span(5, 6, 'value', null),
            new Span(6, 10, 'other', null),
            new Span(10, 11, 'value', null),
            new Span(11, 15, 'other', null),
            new Span(15, 16, 'value', null),
        ];

        $maxima = [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10];

        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use ($spans, $maxima): ?ChoiceSequence
            {
                if ($candidate->choices() !== [0, 1, 1, 1, 1, 4, 1, 1, 1, 1, 5, 1, 1, 1, 1, 10]) {
                    return null;
                }

                return new ChoiceSequence($candidate->choices(), $spans, $maxima);
            },
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([3, 1, 1, 1, 1, 4, 1, 1, 1, 1, 2, 1, 1, 1, 1, 10], $spans, $maxima));

        $this->assertSame([0, 1, 1, 1, 1, 4, 1, 1, 1, 1, 5, 1, 1, 1, 1, 10], $result->sequence()->choices());
    }

    public function testDeletesBlocksOfConsecutiveChoices(): void
    {
        $result = $this->shrinkerAccepting(['1,4,5' => []])->shrink(new ChoiceSequence([1, 2, 3, 4, 5]));

        $this->assertSame([1, 4, 5], $result->sequence()->choices());
    }

    public function testSetsBlocksOfConsecutiveChoicesToZero(): void
    {
        $result = $this->shrinkerAccepting(['0,0,7' => []])->shrink(new ChoiceSequence([5, 6, 7]));

        $this->assertSame([0, 0, 7], $result->sequence()->choices());
    }

    /**
     * @param non-negative-int $choice
     * @param non-negative-int $smallest
     */
    #[DataProvider('minimizationProvider')]
    public function testMinimizesAChoiceToTheSmallestValueThatKeepsTheCounterexampleFailing(int $choice, int $smallest): void
    {
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use ($smallest): ?ChoiceSequence
            {
                $choices = $candidate->choices();

                if (count($choices) !== 1 || $choices[0] < $smallest) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $this->assertSame([$smallest], $shrinker->shrink(new ChoiceSequence([$choice]))->sequence()->choices());
    }

    public function testMovesEverythingFromAChoiceToANearbyLaterChoiceWhenThatKeepsTheCounterexampleFailing(): void
    {
        $result = $this->shrinkerAccepting(['0,11' => []])->shrink(new ChoiceSequence([11, 0]));

        $this->assertSame([0, 11], $result->sequence()->choices());
    }

    public function testMovesTheLargestAmountFromAChoiceToANearbyLaterChoiceThatKeepsTheCounterexampleFailing(): void
    {
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                $choices = $candidate->choices();

                if (count($choices) !== 2 || $choices[0] + $choices[1] < 20 || $choices[1] > 15) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $this->assertSame([5, 15], $shrinker->shrink(new ChoiceSequence([20, 0]))->sequence()->choices());
    }

    /**
     * @param list<non-negative-int> $expected
     */
    #[DataProvider('loweredChoiceWithoutSpansProvider')]
    public function testLowersAChoiceWhileDeletingAsManySpansOfAGroupOfSpansAfterIt(array $expected): void
    {
        // the first choice is the number of elements that are drawn after it
        $result = $this->shrinkerAccepting([implode(',', $expected) => [new Span(1, 2, 'element', null), new Span(2, 3, 'element', null)]])->shrink(
            new ChoiceSequence([3, 7, 8, 9], [new Span(1, 2, 'element', null), new Span(2, 3, 'element', null), new Span(3, 4, 'element', null)]),
        );

        $this->assertSame($expected, $result->sequence()->choices());
    }

    public function testDeletesSpansOfTheNearestGroupOfSpansAfterAChoiceFirst(): void
    {
        // the spans of the group that starts before the choice are listed
        // first, but the group of spans that starts after the choice is nearer
        $nearer = [new Span(0, 1, 'b', null), new Span(2, 3, 'a', null), new Span(3, 4, 'b', null), new Span(4, 5, 'b', null)];
        $other  = [new Span(0, 1, 'b', null), new Span(2, 3, 'a', null), new Span(3, 4, 'a', null), new Span(4, 5, 'b', null)];

        $result = $this->shrinkerAccepting(['9,1,7,5,6' => $nearer, '9,1,7,8,5' => $other])->shrink(
            new ChoiceSequence(
                [9, 2, 7, 8, 5, 6],
                [new Span(0, 1, 'b', null), new Span(2, 3, 'a', null), new Span(3, 4, 'a', null), new Span(4, 5, 'b', null), new Span(5, 6, 'b', null)],
            ),
        );

        $this->assertSame([9, 1, 7, 5, 6], $result->sequence()->choices());
    }

    /**
     * @param list<non-negative-int> $expected
     */
    #[DataProvider('raisedLaterChoiceProvider')]
    public function testLowersAChoiceWhileDeletingSpansAndRaisingALaterChoiceThatDeterminesHowManySpansOfTheSameKindALaterGroupHas(array $expected): void
    {
        // three strings, whose lengths are drawn before their characters: the
        // length of the first string is lowered while one of its characters
        // is deleted, and the length of one of the later strings is raised
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use ($expected): ?ChoiceSequence
            {
                if ($candidate->choices() !== $expected) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $result = $shrinker->shrink(
            new ChoiceSequence(
                [2, 7, 7, 1, 7, 1, 7],
                [
                    new Span(0, 3, 'string', null),
                    new Span(1, 2, 'character', 0),
                    new Span(2, 3, 'character', 0),
                    new Span(3, 5, 'string', null),
                    new Span(4, 5, 'character', 3),
                    new Span(5, 7, 'string', null),
                    new Span(6, 7, 'character', 5),
                ],
                [10, 25, 25, 10, 25, 10, 25],
            ),
        );

        $this->assertSame($expected, $result->sequence()->choices());
    }

    public function testLowersEqualChoicesTogetherToTheSmallestValueThatKeepsTheCounterexampleFailing(): void
    {
        $spans = [new Span(0, 1, 'element', null), new Span(1, 2, 'element', null)];

        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use ($spans): ?ChoiceSequence
            {
                $choices = $candidate->choices();

                if (count($choices) !== 2 || $choices[0] !== $choices[1] || $choices[0] < 50) {
                    return null;
                }

                return new ChoiceSequence($choices, $spans);
            },
            1000,
        );

        $this->assertSame([50, 50], $shrinker->shrink(new ChoiceSequence([100, 100], $spans))->sequence()->choices());
    }

    public function testStopsWhenTheMaximumNumberOfAttemptsHasBeenMade(): void
    {
        $shrinker = new Shrinker(
            static fn (ChoiceSequence $candidate): ?ChoiceSequence => null,
            3,
        );

        $result = $shrinker->shrink(
            new ChoiceSequence(
                [3, 9, 27, 81],
                [new Span(0, 1, 'element', null), new Span(1, 2, 'element', null), new Span(2, 3, 'element', null), new Span(3, 4, 'element', null)],
            ),
        );

        $this->assertSame([3, 9, 27, 81], $result->sequence()->choices());
        $this->assertSame(3, $result->numberOfAttempts());
        $this->assertSame(0, $result->numberOfSteps());
        $this->assertTrue($result->budgetExhausted());
        $this->assertFalse($result->interrupted());
    }

    /**
     * @param array<string, list<Span>> $falsifying the spans of the choice sequences that falsify the property, by their choices separated by commas
     */
    private function shrinkerAccepting(array $falsifying): Shrinker
    {
        return new Shrinker(
            static function (ChoiceSequence $candidate) use ($falsifying): ?ChoiceSequence
            {
                $key = implode(',', $candidate->choices());

                if (!isset($falsifying[$key])) {
                    return null;
                }

                return new ChoiceSequence($candidate->choices(), $falsifying[$key]);
            },
            1000,
        );
    }

    /**
     * Replays the choices as two strings whose lengths, of up to 10
     * characters, are drawn before their characters, like the labels of a
     * domain name.
     *
     * @return array{ChoiceSequence, non-negative-int} the choices that were consumed, with their spans and the largest choices that they could have been, and the total length of the strings
     */
    private static function twoStrings(ChoiceSequence $candidate): array
    {
        $choices     = [];
        $spans       = [];
        $maxima      = [];
        $totalLength = 0;

        for ($string = 0; $string < 2; $string++) {
            $start  = count($choices);
            $length = self::choiceAt($candidate->choices(), $start, 10);
            $parent = count($spans);

            $choices[] = $length;
            $maxima[]  = 10;
            $spans[]   = new Span($start, $start + 1 + $length, 'string', null);

            for ($character = 0; $character < $length; $character++) {
                $index = count($choices);

                $choices[] = self::choiceAt($candidate->choices(), $index, 25);
                $maxima[]  = 25;
                $spans[]   = new Span($index, $index + 1, 'character', $parent);
            }

            $totalLength += $length;
        }

        return [new ChoiceSequence($choices, $spans, $maxima), $totalLength];
    }

    /**
     * Two values of up to 10 with a choice between them, which is a value
     * as well when the first value is 0.
     *
     * @param array{non-negative-int, non-negative-int, non-negative-int} $choices
     */
    private static function twoValuesWithAChoiceBetweenThem(array $choices): ChoiceSequence
    {
        $between = 'between';

        if ($choices[0] === 0) {
            $between = 'value';
        }

        return new ChoiceSequence(
            $choices,
            [new Span(0, 1, 'value', null), new Span(1, 2, $between, null), new Span(2, 3, 'value', null)],
            [10, 10, 10],
        );
    }

    /**
     * The choice at the index, 0 beyond the end of the choices, and clamped
     * to the maximum, like a choice source replays a choice sequence.
     *
     * @param list<non-negative-int> $choices
     * @param non-negative-int       $maximum
     *
     * @return non-negative-int
     */
    private static function choiceAt(array $choices, int $index, int $maximum): int
    {
        if (!array_key_exists($index, $choices)) {
            return 0;
        }

        return min($choices[$index], $maximum);
    }
}
