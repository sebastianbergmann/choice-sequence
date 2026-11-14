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

#[CoversClass(TooManyDuplicateElementsException::class)]
#[Small]
final class TooManyDuplicateElementsExceptionTest extends TestCase
{
    public function testHasTheNumberOfAttempts(): void
    {
        $exception = new TooManyDuplicateElementsException(10);

        $this->assertSame(10, $exception->numberOfAttempts());
        $this->assertSame('No new element for the collection was drawn in 10 attempts', $exception->getMessage());
    }
}
