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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Encoder::class)]
#[UsesClass(ChoiceSequence::class)]
#[UsesClass(Span::class)]
#[Small]
final class EncoderTest extends TestCase
{
    public function testEncodesTheHeaderFollowedByOneChoicePerLineWithALineFeedAfterEachLine(): void
    {
        $this->assertSame(
            "choice-sequence v1\n0\n17\n3\n",
            new Encoder()->encode(new ChoiceSequence([0, 17, 3])),
        );
    }

    public function testEncodesAnEmptyChoiceSequenceAsTheHeaderAlone(): void
    {
        $this->assertSame("choice-sequence v1\n", new Encoder()->encode(new ChoiceSequence([])));
    }

    public function testEncodesTheLargestChoice(): void
    {
        $this->assertSame("choice-sequence v1\n" . PHP_INT_MAX . "\n", new Encoder()->encode(new ChoiceSequence([PHP_INT_MAX])));
    }

    public function testDoesNotEncodeSpansAndMaxima(): void
    {
        $this->assertSame(
            "choice-sequence v1\n3\n1\n",
            new Encoder()->encode(new ChoiceSequence([3, 1], [new Span(0, 2, 'element', null)], [10, 10])),
        );
    }
}
