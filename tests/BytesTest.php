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

use function ksort;
use function strlen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

#[CoversClass(Bytes::class)]
#[UsesClass(BernoulliDistribution::class)]
#[UsesClass(ChoiceSequence::class)]
#[UsesClass(ChoiceSource::class)]
#[UsesClass(ExactDistribution::class)]
#[UsesClass(Label::class)]
#[UsesClass(Repetition::class)]
#[UsesClass(Span::class)]
#[UsesClass(WeightedRangesDistribution::class)]
#[Small]
final class BytesTest extends TestCase
{
    public function testTheLabelOfTheSpanOfABinaryStringTellsItsMinimumAndItsMaximumLength(): void
    {
        $this->assertSame(Label::of('bytes', '0', '4096'), new Bytes(0, 4096)->label());
        $this->assertSame(Label::of('bytes', '2', 'NULL'), new Bytes(2, null)->label());
    }

    public function testDrawsTheBytesThatTheMinimumLengthRequiresAsTheirValueAndEveryOtherByteAfterTheChoiceToAddIt(): void
    {
        $source = ChoiceSource::replay(new ChoiceSequence([0x61, 1, 0x00, 1, 0xFF, 0]));

        $this->assertSame("a\x00\xFF", new Bytes(1, null)->draw($source));
        $this->assertSame([0x61, 1, 0x00, 1, 0xFF, 0], $source->sequence()->choices());
    }

    public function testMarksEachByteAndTheChoiceThatEndsTheStringAsASpanOfTheKindElement(): void
    {
        $bytes  = new Bytes(0, 10);
        $source = ChoiceSource::replay(new ChoiceSequence([1, 0x61, 0]));

        $bytes->draw($source);

        $spans = [];

        foreach ($source->sequence()->spans() as $span) {
            $spans[] = [$span->start(), $span->end(), $span->label(), $span->kind()];
        }

        $element = Label::of('element', $bytes->label());

        $this->assertSame(
            [
                [0, 2, $element, SpanKind::Element],
                [2, 3, $element, SpanKind::Element],
            ],
            $spans,
        );
    }

    public function testDrawsBinaryStringsWithinTheLength(): void
    {
        $bytes      = new Bytes(2, 5);
        $randomizer = new Randomizer(new Xoshiro256StarStar(1));
        $lengths    = [];

        for ($i = 0; $i < 500; $i++) {
            $lengths[strlen($bytes->draw(ChoiceSource::random($randomizer)))] = true;
        }

        ksort($lengths);

        $this->assertSame([2 => true, 3 => true, 4 => true, 5 => true], $lengths);
    }

    public function testTheSimplestBinaryStringIsEmpty(): void
    {
        $this->assertSame('', new Bytes(0, null)->draw(ChoiceSource::replay(new ChoiceSequence([]))));
    }
}
