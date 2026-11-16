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
use function mb_check_encoding;
use function mb_strlen;
use function strlen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

#[CoversClass(Characters::class)]
#[UsesClass(BernoulliDistribution::class)]
#[UsesClass(ChoiceSequence::class)]
#[UsesClass(ChoiceSource::class)]
#[UsesClass(CodePoints::class)]
#[UsesClass(ExactDistribution::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(Label::class)]
#[UsesClass(Repetition::class)]
#[UsesClass(Span::class)]
#[UsesClass(WeightedRangesDistribution::class)]
#[Small]
final class CharactersTest extends TestCase
{
    public function testTheLabelOfTheSpanOfAStringTellsItsMinimumAndItsMaximumLength(): void
    {
        $this->assertSame(Label::of('characters', '0', '4096'), new Characters(0, 4096)->label());
        $this->assertSame(Label::of('characters', '2', 'NULL'), new Characters(2, null)->label());
    }

    public function testCannotHaveAMaximumLengthThatIsSmallerThanItsMinimumLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Characters(3, 2);
    }

    public function testDrawsTheCharactersThatTheMinimumLengthRequiresAsTheIndexOfTheirCodePointAndEveryOtherCharacterAfterTheChoiceToAddIt(): void
    {
        $choices = [CodePoints::index(0x61), 1, CodePoints::index(0xE4), 1, CodePoints::index(0x1F600), 0];
        $source  = ChoiceSource::replay(new ChoiceSequence($choices));

        $this->assertSame("a\u{E4}\u{1F600}", new Characters(1, null)->draw($source));
        $this->assertSame($choices, $source->sequence()->choices());
    }

    public function testMarksEachCharacterAndTheChoiceThatEndsTheStringAsASpanOfTheKindElement(): void
    {
        $characters = new Characters(0, 10);
        $source     = ChoiceSource::replay(new ChoiceSequence([1, 0, 0]));

        $characters->draw($source);

        $spans = [];

        foreach ($source->sequence()->spans() as $span) {
            $spans[] = [$span->start(), $span->end(), $span->label(), $span->kind()];
        }

        $element = Label::of('element', $characters->label());

        $this->assertSame(
            [
                [0, 2, $element, SpanKind::Element],
                [2, 3, $element, SpanKind::Element],
            ],
            $spans,
        );
    }

    public function testDrawsStringsOfUnicodeCharactersWithinTheLength(): void
    {
        $characters = new Characters(2, 5);
        $randomizer = new Randomizer(new Xoshiro256StarStar(1));
        $lengths    = [];
        $multibyte  = false;

        for ($i = 0; $i < 500; $i++) {
            $string = $characters->draw(ChoiceSource::random($randomizer));

            $this->assertTrue(mb_check_encoding($string, 'UTF-8'));

            $lengths[mb_strlen($string, 'UTF-8')] = true;

            if (strlen($string) > mb_strlen($string, 'UTF-8')) {
                $multibyte = true;
            }
        }

        ksort($lengths);

        $this->assertSame([2 => true, 3 => true, 4 => true, 5 => true], $lengths);
        $this->assertTrue($multibyte);
    }

    public function testTheSimplestStringIsEmpty(): void
    {
        $this->assertSame('', new Characters(0, null)->draw(ChoiceSource::replay(new ChoiceSequence([]))));
    }

    public function testTellsTheChoicesFromWhichAStringIsDrawn(): void
    {
        $characters = new Characters(1, null);
        $choices    = $characters->choicesFor("a\u{E4}\u{1F600}");

        $this->assertSame([CodePoints::index(0x61), 1, CodePoints::index(0xE4), 1, CodePoints::index(0x1F600), 0], $choices);
        $this->assertSame("a\u{E4}\u{1F600}", $characters->draw(ChoiceSource::replay(new ChoiceSequence($choices))));
        $this->assertSame([0], new Characters(0, 10)->choicesFor(''));
    }

    public function testTheChoicesOfAStringThatHasMoreCharactersThanTheMaximumLengthAreTheOnesOfTheStringCutOff(): void
    {
        $characters = new Characters(0, 2);

        $this->assertSame($characters->choicesFor("\u{E4}\u{F6}"), $characters->choicesFor("\u{E4}\u{F6}\u{FC}"));
    }

    public function testTheChoicesOfAStringThatHasFewerCharactersThanTheMinimumLengthAreReplayedAsTheStringFollowedByTheSimplestCharacter(): void
    {
        $characters = new Characters(3, null);

        $this->assertSame("\u{E4}aa", $characters->draw(ChoiceSource::replay(new ChoiceSequence($characters->choicesFor("\u{E4}")))));
    }

    public function testCannotTellTheChoicesOfAStringThatIsNotValidUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Characters(0, null)->choicesFor("\xFF");
    }
}
