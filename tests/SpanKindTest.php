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

#[CoversClass(SpanKind::class)]
#[Small]
final class SpanKindTest extends TestCase
{
    public function testHasTheNameUnderWhichToolsStoreIt(): void
    {
        $names = [];

        foreach (SpanKind::cases() as $kind) {
            $names[] = $kind->value;
        }

        $this->assertSame(
            [
                'bytes',
                'characters',
                'element',
                'requiredelement',
                'referable',
                'reference',
                'consumingreference',
            ],
            $names,
        );
    }

    public function testIsRecreatedFromTheNameUnderWhichToolsStoreIt(): void
    {
        foreach (SpanKind::cases() as $kind) {
            $this->assertSame($kind, SpanKind::from($kind->value));
        }

        $this->assertNull(SpanKind::tryFrom('Element'));
    }
}
