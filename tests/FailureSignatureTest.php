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

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FailureSignature::class)]
#[Small]
final class FailureSignatureTest extends TestCase
{
    /**
     * @return array<string, array{bool, FailureSignature, FailureSignature}>
     */
    public static function sameFailureProvider(): array
    {
        return [
            'throwables of the same class raised at the same location' => [
                true,
                FailureSignature::forThrowable(RuntimeException::class, '/path/to/FooTest.php:12'),
                FailureSignature::forThrowable(RuntimeException::class, '/path/to/FooTest.php:12'),
            ],
            'throwables of different classes raised at the same location' => [
                false,
                FailureSignature::forThrowable(RuntimeException::class, '/path/to/FooTest.php:12'),
                FailureSignature::forThrowable(LogicException::class, '/path/to/FooTest.php:12'),
            ],
            'throwables of the same class raised at different locations' => [
                false,
                FailureSignature::forThrowable(RuntimeException::class, '/path/to/FooTest.php:12'),
                FailureSignature::forThrowable(RuntimeException::class, '/path/to/FooTest.php:13'),
            ],
            'timeouts' => [
                true,
                FailureSignature::forTimeout(),
                FailureSignature::forTimeout(),
            ],
            'timeout and throwable' => [
                false,
                FailureSignature::forTimeout(),
                FailureSignature::forThrowable(RuntimeException::class, '/path/to/FooTest.php:12'),
            ],
            'failures with the same description' => [
                true,
                FailureSignature::forDescription('failed: message'),
                FailureSignature::forDescription('failed: message'),
            ],
            'failures with different descriptions' => [
                false,
                FailureSignature::forDescription('failed: message'),
                FailureSignature::forDescription('errored: message'),
            ],
            'failure with a description and throwable' => [
                false,
                FailureSignature::forDescription('failed: message'),
                FailureSignature::forThrowable(RuntimeException::class, '/path/to/FooTest.php:12'),
            ],
        ];
    }

    #[DataProvider('sameFailureProvider')]
    public function testTellsWhetherTwoFailuresAreTheSame(bool $expected, FailureSignature $a, FailureSignature $b): void
    {
        $this->assertSame($expected, $a->isSameAs($b));
        $this->assertSame($expected, $b->isSameAs($a));
    }

    public function testDescribesAFailureByTheClassOfItsThrowableAndTheLocationWhereItWasRaised(): void
    {
        $signature = FailureSignature::forThrowable(RuntimeException::class, '/path/to/FooTest.php:12');

        $this->assertSame('RuntimeException at /path/to/FooTest.php:12', $signature->description());
        $this->assertFalse($signature->isTimeout());
    }

    public function testDescribesAFailureThatExceededTheTimeLimit(): void
    {
        $signature = FailureSignature::forTimeout();

        $this->assertSame('exceeding the time limit', $signature->description());
        $this->assertTrue($signature->isTimeout());
    }

    public function testDescribesAFailureThatDidNotEndWithAThrowableByItsDescription(): void
    {
        $signature = FailureSignature::forDescription('failed: message');

        $this->assertSame('failed: message', $signature->description());
        $this->assertFalse($signature->isTimeout());
    }
}
