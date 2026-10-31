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

use function hash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(Label::class)]
#[Small]
final class LabelTest extends TestCase
{
    public function testIsTheHashOfTheNameAndItsComponents(): void
    {
        $this->assertSame(hash('xxh3', 'bytes(0,4096)'), Label::of('bytes', '0', '4096'));
        $this->assertSame(hash('xxh3', 'booleans()'), Label::of('booleans'));
    }

    public function testIsTheSameForTheSameNameAndComponents(): void
    {
        $this->assertSame(Label::of('element', Label::of('bytes', '0', '10')), Label::of('element', Label::of('bytes', '0', '10')));
    }

    public function testDiffersForDifferentNamesOrComponents(): void
    {
        $this->assertNotSame(Label::of('integers', '0', '10'), Label::of('integers', '0', '11'));
        $this->assertNotSame(Label::of('integers', '0', '10'), Label::of('floats', '0', '10'));
    }
}
