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

#[CoversClass(InvalidArgumentException::class)]
#[Small]
final class InvalidArgumentExceptionTest extends TestCase
{
    public function testTellsTheSizesOfACollectionWhoseMaximumSizeIsSmallerThanItsMinimumSize(): void
    {
        $this->assertSame(
            'The maximum size 2 of a collection is smaller than its minimum size 3',
            InvalidArgumentException::maximumSizeSmallerThanMinimumSize(3, 2)->getMessage(),
        );
    }

    public function testTellsTheBoundsOfARangeOfChoicesWhoseMinimumIsLargerThanItsMaximum(): void
    {
        $this->assertSame(
            'The minimum 5 of a range of choices is larger than its maximum 3',
            InvalidArgumentException::minimumOfRangeLargerThanMaximum(5, 3)->getMessage(),
        );
    }

    public function testTellsThatAStringIsNotValidUtf8(): void
    {
        $this->assertSame('The string is not valid UTF-8', InvalidArgumentException::stringNotValidUtf8()->getMessage());
    }
}
