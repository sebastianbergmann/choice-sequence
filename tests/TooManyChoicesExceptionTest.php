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

#[CoversClass(TooManyChoicesException::class)]
#[Small]
final class TooManyChoicesExceptionTest extends TestCase
{
    public function testHasTheMaximumNumberOfChoices(): void
    {
        $exception = new TooManyChoicesException(8192);

        $this->assertSame(8192, $exception->maximumNumberOfChoices());
        $this->assertSame('Cannot draw more than 8192 choices', $exception->getMessage());
    }
}
