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
use function array_fill;
use function array_filter;
use function array_key_exists;
use function array_search;
use function array_slice;
use function array_splice;
use function array_unique;
use function array_values;
use function assert;
use function count;
use function implode;
use function in_array;
use function min;
use function preg_match;
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
     * @return array<string, array{non-negative-int, positive-int, non-negative-int, non-negative-int, non-negative-int}>
     */
    public static function loweredInStepsProvider(): array
    {
        return [
            'to the remainder of the division'                                => [51, 15, 6, 0, 6],
            'to the divisor when the remainder does not keep it failing'      => [45, 15, 0, 1, 15],
            'by as many multiples of the divisor as keep it failing'          => [7000, 7, 0, 1001, 1001],
            'by multiples of the divisor that are not larger than the choice' => [49, 7, 0, 21, 21],
            'in steps of a power of two'                                      => [768, 256, 0, 1, 256],
            'in steps of a power of ten'                                      => [700, 100, 0, 1, 100],
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

        // the attempts to minimize the second choice right after it was
        // raised belong to the same pass
        $candidatesAfterwards = array_values(
            array_filter(
                array_slice($candidates, $index + 1),
                static fn (array $candidate): bool => $candidate !== [] && $candidate[0] !== 4,
            ),
        );

        $this->assertSame([[3, PHP_INT_MAX]], array_slice($candidatesAfterwards, 0, 1));
    }

    public function testMinimizesALaterChoiceRightAfterItWasRaisedWhileAChoiceWasLowered(): void
    {
        $candidates = [];

        // the choices are the two digits of a number that is at least 45
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$candidates): ?ChoiceSequence
            {
                $candidates[] = $candidate->choices();

                if (count($candidate->choices()) !== 2) {
                    return null;
                }

                $first  = min($candidate->choices()[0], 9);
                $second = min($candidate->choices()[1], 9);

                if ($first * 10 + $second < 45) {
                    return null;
                }

                return new ChoiceSequence([$first, $second]);
            },
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([5, 0]));

        $index = array_search([4, PHP_INT_MAX], $candidates, true);

        $this->assertIsInt($index);

        // smaller values for the second digit were tried by earlier passes
        // and are not replayed again
        $this->assertSame([[4, PHP_INT_MAX], [4, 2]], array_slice($candidates, $index, 2));
        $this->assertSame([4, 5], $result->sequence()->choices());
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

    public function testDeletesAnElementThatTheMinimumSizeOfACollectionRequiresTogetherWithTheChoiceThatAddedTheFirstOtherElement(): void
    {
        // deleting the span of the first element alone leaves the choice 1
        // that added the third element where the second element is read
        $result = $this->shrinkerAccepting(
            [
                '6,7,0' => [
                    new Span(0, 1, 'element', null, SpanKind::RequiredElement),
                    new Span(1, 2, 'element', null, SpanKind::RequiredElement),
                    new Span(2, 3, 'element', null, SpanKind::Element),
                ],
            ],
        )->shrink(
            new ChoiceSequence(
                [5, 6, 1, 7, 0],
                [
                    new Span(0, 1, 'element', null, SpanKind::RequiredElement),
                    new Span(1, 2, 'element', null, SpanKind::RequiredElement),
                    new Span(2, 4, 'element', null, SpanKind::Element),
                    new Span(4, 5, 'element', null, SpanKind::Element),
                ],
            ),
        );

        $this->assertSame([6, 7, 0], $result->sequence()->choices());
        $this->assertSame(1, $result->numberOfSteps());
    }

    public function testDeletesTheSpanOfAnElementThatTheMinimumSizeOfACollectionRequiresWhenNoChoiceAddedAnotherElement(): void
    {
        // the collections share their label, but the choice that added an
        // element to the second one does not add an element to the first one
        $result = $this->shrinkerAccepting(
            [
                '6,1,7' => [
                    new Span(0, 1, 'first', null),
                    new Span(0, 1, 'element', 0, SpanKind::RequiredElement),
                    new Span(1, 3, 'second', null),
                    new Span(1, 3, 'element', 1, SpanKind::Element),
                ],
            ],
        )->shrink(
            new ChoiceSequence(
                [5, 6, 1, 7],
                [
                    new Span(0, 2, 'first', null),
                    new Span(0, 1, 'element', 0, SpanKind::RequiredElement),
                    new Span(1, 2, 'element', 0, SpanKind::RequiredElement),
                    new Span(2, 4, 'second', null),
                    new Span(2, 4, 'element', 3, SpanKind::Element),
                ],
            ),
        );

        $this->assertSame([6, 1, 7], $result->sequence()->choices());
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

    public function testSetsBlocksOfChoicesToZeroBeforeItDeletesBlocksOfChoicesAndSkipsABlockThatWasSetToZero(): void
    {
        // no choice can be deleted, as for a collection that has its smallest size
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                if (count($candidate->choices()) !== 16) {
                    return null;
                }

                return $candidate;
            },
            2,
        );

        $result = $shrinker->shrink(new ChoiceSequence(array_fill(0, 16, 1)));

        $this->assertSame(array_fill(0, 16, 0), $result->sequence()->choices());
        $this->assertSame(2, $result->numberOfAttempts());
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

    /**
     * @param non-negative-int $choice
     * @param positive-int     $divisor
     * @param non-negative-int $remainder
     * @param non-negative-int $threshold
     * @param non-negative-int $smallest
     */
    #[DataProvider('loweredInStepsProvider')]
    public function testLowersAChoiceInStepsWhenTheCounterexampleFailsForEveryValueWithTheSameRemainderOfADivision(int $choice, int $divisor, int $remainder, int $threshold, int $smallest): void
    {
        // a binary search ends at the choice, as none of the values that it
        // tries has the remainder
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use ($divisor, $remainder, $threshold): ?ChoiceSequence
            {
                $choices = $candidate->choices();

                if (count($choices) !== 1 || $choices[0] < $threshold || $choices[0] % $divisor !== $remainder) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([$choice]));

        $this->assertSame([$smallest], $result->sequence()->choices());
        $this->assertFalse($result->budgetExhausted());
    }

    public function testLowersAChoiceInStepsAsSoonAsABinarySearchLowersItBelowAValueThatDoesNotKeepTheCounterexampleFailing(): void
    {
        $candidates = [];

        // the choice has to be a multiple of 5
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$candidates): ?ChoiceSequence
            {
                $candidates[] = $candidate->choices();

                $choices = $candidate->choices();

                if (count($choices) !== 1 || $choices[0] === 0 || $choices[0] % 5 !== 0) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([1000]));

        // the first binary search ends at 625, as 624 does not keep the
        // counterexample failing, and the second one lowers the choice to 605
        $this->assertContains([624], $candidates);

        $index = array_search([605], $candidates, true);

        $this->assertIsInt($index);
        $this->assertSame([[605], [604], [603], [602], [601], [600], [5]], array_slice($candidates, $index, 7));
        $this->assertSame([5], $result->sequence()->choices());
    }

    public function testContinuesTheBinarySearchWhenAChoiceThatItLoweredBelowAValueThatDoesNotKeepTheCounterexampleFailingCannotBeLoweredInSteps(): void
    {
        $candidates = [];

        // the first binary search ends at 750, the second one lowers the
        // choice to 563 and then to 469
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$candidates): ?ChoiceSequence
            {
                $candidates[] = $candidate->choices();

                if (!in_array($candidate->choices(), [[750], [563], [469]], true)) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([1000]));

        $loweredTo563 = array_search([563], $candidates, true);
        $loweredTo469 = array_search([469], $candidates, true);

        $this->assertIsInt($loweredTo563);
        $this->assertIsInt($loweredTo469);

        // 422 is the value that the binary search tries after 469
        $this->assertSame([[563], [562]], array_slice($candidates, $loweredTo563, 2));
        $this->assertSame([[469], [422]], array_slice($candidates, $loweredTo469, 2));
        $this->assertSame([469], $result->sequence()->choices());
    }

    public function testDoesNotLowerAChoiceByAnotherStepWhenItCouldNotBeLoweredByAFurtherMultipleOfTheStep(): void
    {
        $candidates = [];

        // lowering the choice by 4 keeps the counterexample failing, lowering
        // it by 8 does not
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$candidates): ?ChoiceSequence
            {
                $candidates[] = $candidate->choices();

                if ($candidate->choices() !== [41]) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([45]));

        $index = array_search([41], $candidates, true);

        $this->assertIsInt($index);

        // 5 is the remainder of the division of 41 by 4 plus 4, and 22 is the
        // first value that the binary search of the passes that are repeated
        // tries, rather than 40, which is one step below 41
        $this->assertSame([[41], [5], [37], [22]], array_slice($candidates, $index, 4));
        $this->assertSame([41], $result->sequence()->choices());
    }

    public function testRepeatsTheOtherPassesAfterAChoiceWasLoweredInSteps(): void
    {
        // the first choice has to be a multiple of 15, and the second choice
        // can only be deleted when the first one is 15
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                $choices = $candidate->choices();

                if ($choices === [] || $choices[0] === 0 || $choices[0] % 15 !== 0) {
                    return null;
                }

                if ($choices[0] > 15 && count($choices) < 2) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $this->assertSame([15], $shrinker->shrink(new ChoiceSequence([45, 0]))->sequence()->choices());
    }

    public function testLowersChoicesInStepsOnlyWhenNoOtherPassMakesTheCounterexampleSimpler(): void
    {
        $candidates = [];

        // the second choice can be minimized, the first one can only be
        // lowered in steps
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$candidates): ?ChoiceSequence
            {
                $candidates[] = $candidate->choices();

                $choices = $candidate->choices();

                if (count($choices) !== 2 || $choices[0] === 0 || $choices[0] % 15 !== 0 || $choices[1] < 50) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([45, 100]));

        $minimized      = array_search([45, 50], $candidates, true);
        $loweredInSteps = array_search([30, 50], $candidates, true);

        $this->assertSame([15, 50], $result->sequence()->choices());
        $this->assertIsInt($minimized);
        $this->assertIsInt($loweredInSteps);
        $this->assertGreaterThan($minimized, $loweredInSteps);
        $this->assertNotContains([30, 100], $candidates);
    }

    public function testDoesNotLowerAChoiceInStepsThatHasTheValueOfAnEarlierChoiceThatCouldNotBeLoweredInSteps(): void
    {
        $candidates = [];

        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$candidates): ?ChoiceSequence
            {
                $candidates[] = $candidate->choices();

                $choices = $candidate->choices();

                if (count($choices) !== 2 || $choices[0] < 100 || $choices[1] < 100) {
                    return null;
                }

                return $candidate;
            },
            1000,
        );

        $result = $shrinker->shrink(new ChoiceSequence([100, 100]));

        // the values that are one and two below the choices are tried when
        // the choices are minimized
        $this->assertSame([100, 100], $result->sequence()->choices());
        $this->assertContains([97, 100], $candidates);
        $this->assertNotContains([100, 97], $candidates);
    }

    public function testStopsLoweringAChoiceInStepsThatTheConsumedChoiceSequenceNoLongerContains(): void
    {
        // lowering the second choice by 15 keeps the counterexample failing,
        // and the replay does not consume the second choice then
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                if ($candidate->choices() === [1, 30]) {
                    return new ChoiceSequence([1]);
                }

                return null;
            },
            1000,
        );

        $this->assertSame([1], $shrinker->shrink(new ChoiceSequence([1, 45]))->sequence()->choices());
    }

    public function testStopsLoweringAChoiceByMultiplesOfAStepThatTheConsumedChoiceSequenceNoLongerContains(): void
    {
        // lowering the second choice by 7 keeps the counterexample failing,
        // and the replay does not consume the second choice when it is
        // lowered by 7 once more
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate): ?ChoiceSequence
            {
                if ($candidate->choices() === [1, 42]) {
                    return $candidate;
                }

                if ($candidate->choices() === [1, 35]) {
                    return new ChoiceSequence([1]);
                }

                return null;
            },
            1000,
        );

        $this->assertSame([1], $shrinker->shrink(new ChoiceSequence([1, 49]))->sequence()->choices());
    }

    public function testDoesNotLowerAChoiceByMultiplesOfAStepWhenTheConsumedChoiceSequenceHasAnotherValueForIt(): void
    {
        $candidates = [];

        // lowering the choice by 15 keeps the counterexample failing, and the
        // replay consumes a smaller value for it then
        $shrinker = new Shrinker(
            static function (ChoiceSequence $candidate) use (&$candidates): ?ChoiceSequence
            {
                $candidates[] = $candidate->choices();

                if ($candidate->choices() === [130]) {
                    return new ChoiceSequence([100]);
                }

                return null;
            },
            1000,
        );

        // 25 is the remainder of the division of 100 by 15 plus 15
        $this->assertSame([100], $shrinker->shrink(new ChoiceSequence([145]))->sequence()->choices());
        $this->assertNotContains([25], $candidates);
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

    public function testDeletesASpanWhileChangingTheReferencesAfterItSoThatTheyReferToTheSameValues(): void
    {
        $candidates = [];

        // a value that is 3 has to be added, and a value that is 7 has to be
        // referred to
        $shrinker = $this->shrinkerOfStepsThatReferToValues('/add 3.*use 7/', $candidates);

        // the values 3, 7, and 5 are added, and the last step refers to the
        // second one: without the value that was added last, it is the first
        // one counted from the last one
        $result = $shrinker->shrink(self::stepsThatReferToValues([1, 0, 3, 0, 1, 0, 7, 0, 1, 0, 5, 0, 1, 1, 1])[0]);

        $this->assertSame([1, 0, 3, 0, 1, 0, 7, 0, 1, 1, 0], $result->sequence()->choices());

        // the span is not deleted without changing the reference
        $this->assertNotContains([1, 0, 3, 0, 1, 0, 7, 0, 1, 1, 1], $candidates);
    }

    public function testDeletesASpanThatConsumesAValueWhileChangingTheReferencesAfterItSoThatTheyReferToTheSameValues(): void
    {
        $candidates = [];

        $shrinker = $this->shrinkerOfStepsThatReferToValues('/use 7/', $candidates);

        // the values 7 and 5 are added, the second one is consumed, and the
        // last step refers to the first one: when the second one is not
        // consumed, the first one is the second one counted from the last one
        $result = $shrinker->shrink(self::stepsThatReferToValues([1, 0, 7, 0, 1, 0, 5, 0, 1, 2, 0, 1, 1, 0])[0]);

        $this->assertContains([1, 0, 7, 0, 1, 0, 5, 0, 1, 1, 1], $candidates);
        $this->assertSame([1, 0, 7, 0, 1, 1, 0], $result->sequence()->choices());
    }

    public function testDeletesASpanWhileLettingTheFirstReferenceToTheValueThatItContributedReferToAnotherValue(): void
    {
        $candidates = [];

        // a value that is 7 has to be added before a value that is 8, then a
        // value that is 7 has to be consumed, and then a value that is 8 has
        // to be referred to
        $shrinker = $this->shrinkerOfStepsThatReferToValues('/add 7.*add 8.*consume 7.*use 8/', $candidates);

        // the values 7, 8, and 7 are added, the third one is consumed, and
        // the last step refers to the second one: without the third one, the
        // first one can be consumed instead
        $result = $shrinker->shrink(self::stepsThatReferToValues([1, 0, 7, 0, 1, 0, 8, 0, 1, 0, 7, 0, 1, 2, 0, 1, 1, 0])[0]);

        $this->assertSame([1, 0, 7, 0, 1, 0, 8, 0, 1, 2, 1, 1, 1, 0], $result->sequence()->choices());
    }

    public function testAReferenceCannotReferToTheValueOfASpanThatItIsPartOf(): void
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

        // the reference is the last choice of the span of the third value,
        // so it refers to the first value: without the second value, that is
        // the first one counted from the last one
        $shrinker->shrink(
            new ChoiceSequence(
                [5, 6, 7, 2],
                [
                    new Span(0, 1, 'value', null, SpanKind::Referable),
                    new Span(1, 2, 'value', null, SpanKind::Referable),
                    new Span(2, 4, 'value', null, SpanKind::Referable),
                    new Span(3, 4, 'value', 2, SpanKind::Reference),
                ],
            ),
        );

        $this->assertContains([5, 7, 0], $candidates);
        $this->assertNotContains([5, 7, 1], $candidates);
    }

    public function testOnlyChangesAReferenceThatConsistsOfOneChoiceAndThatHasAValueToReferTo(): void
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

        // the first reference is drawn before a value that it can refer to,
        // and the last one has two choices
        $shrinker->shrink(
            new ChoiceSequence(
                [4, 5, 6, 1, 9],
                [
                    new Span(0, 1, 'value', null, SpanKind::Reference),
                    new Span(1, 2, 'value', null, SpanKind::Referable),
                    new Span(2, 3, 'value', null, SpanKind::Referable),
                    new Span(3, 5, 'value', null, SpanKind::Reference),
                ],
            ),
        );

        // a reference at the fourth choice would be changed from 1 to 0 when
        // the span of the third choice is deleted
        $this->assertContains([4, 5, 1, 9], $candidates);
        $this->assertNotContains([4, 5, 0, 9], $candidates);
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
     * @param non-empty-string             $pattern    the regular expression that the steps that falsify the property match, see stepsThatReferToValues()
     * @param list<list<non-negative-int>> $candidates the choices of the candidates that were replayed
     */
    private function shrinkerOfStepsThatReferToValues(string $pattern, array &$candidates): Shrinker
    {
        return new Shrinker(
            static function (ChoiceSequence $candidate) use ($pattern, &$candidates): ?ChoiceSequence
            {
                $candidates[] = $candidate->choices();

                [$consumed, $steps] = self::stepsThatReferToValues($candidate->choices());

                if (preg_match($pattern, $steps) !== 1) {
                    return null;
                }

                return $consumed;
            },
            1000,
        );
    }

    /**
     * Replays the choices as steps that add values to a pool and that take
     * values from it, like the steps of a state machine. A step starts with
     * the choice 1 and the choice of what it does. A step that adds a value
     * (0) draws the value, of up to 9, and a choice that is always 0 for the
     * span of the kind Referable. A step that refers to a value (1) or that
     * consumes one (2) draws its position, counted from the value that was
     * added last, in a span of the kind Reference or ConsumingReference. It
     * is skipped, after its choices were drawn, when the pool has no value.
     *
     * @param list<non-negative-int> $candidate
     *
     * @return array{ChoiceSequence, string} the choices that were consumed, with their spans, and the steps that were taken, "add 3,use 3", for instance
     */
    private static function stepsThatReferToValues(array $candidate): array
    {
        $choices = [];
        $spans   = [];
        $maxima  = [];
        $pool    = [];
        $steps   = [];

        while (self::choiceAt($candidate, count($choices), 1) === 1) {
            $start  = count($choices);
            $action = self::choiceAt($candidate, $start + 1, 2);
            $step   = count($spans);

            $choices[] = 1;
            $choices[] = $action;
            $maxima[]  = 1;
            $maxima[]  = 2;

            if ($action === 0) {
                $value = self::choiceAt($candidate, $start + 2, 9);

                $choices[] = $value;
                $choices[] = 0;
                $maxima[]  = 9;
                $maxima[]  = 0;
                $spans[]   = new Span($start, $start + 4, 'step', null);
                $spans[]   = new Span($start + 3, $start + 4, 'value', $step, SpanKind::Referable);
                $pool[]    = $value;
                $steps[]   = 'add ' . $value;

                continue;
            }

            $spans[] = new Span($start, $start + 3, 'step', null);

            if ($pool === []) {
                $choices[] = 0;
                $maxima[]  = 0;

                continue;
            }

            $position = self::choiceAt($candidate, $start + 2, count($pool) - 1);
            $index    = count($pool) - 1 - $position;

            assert(array_key_exists($index, $pool));

            $choices[] = $position;
            $maxima[]  = count($pool) - 1;

            if ($action === 1) {
                $spans[] = new Span($start + 2, $start + 3, 'value', $step, SpanKind::Reference);
                $steps[] = 'use ' . $pool[$index];

                continue;
            }

            $spans[] = new Span($start + 2, $start + 3, 'value', $step, SpanKind::ConsumingReference);
            $steps[] = 'consume ' . $pool[$index];

            array_splice($pool, $index, 1);
        }

        return [new ChoiceSequence($choices, $spans, $maxima), implode(',', $steps)];
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
