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

#[CoversClass(NoSpanToEndException::class)]
#[Small]
final class NoSpanToEndExceptionTest extends TestCase
{
    public function testTellsThatThereIsNoSpanToEnd(): void
    {
        $this->assertSame('There is no span that was started and has not been ended', new NoSpanToEndException()->getMessage());
    }
}
