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

#[CoversClass(NoValueToReferToException::class)]
#[Small]
final class NoValueToReferToExceptionTest extends TestCase
{
    public function testHasAMessage(): void
    {
        $this->assertSame('The pool has no value that can be referred to', new NoValueToReferToException()->getMessage());
    }
}
