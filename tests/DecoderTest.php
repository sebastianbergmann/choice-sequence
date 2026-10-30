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
use function range;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Decoder::class)]
#[CoversClass(InvalidChoiceSequenceException::class)]
#[UsesClass(ChoiceSequence::class)]
#[UsesClass(Encoder::class)]
#[Small]
final class DecoderTest extends TestCase
{
    /**
     * @return array<string, array{list<non-negative-int>, string}>
     */
    public static function validProvider(): array
    {
        return [
            'line feeds'                        => [[0, 17, 3], "choice-sequence v1\n0\n17\n3\n"],
            'carriage returns and line feeds'   => [[0, 17, 3], "choice-sequence v1\r\n0\r\n17\r\n3\r\n"],
            'header alone'                      => [[], "choice-sequence v1\n"],
            'header alone with carriage return' => [[], "choice-sequence v1\r\n"],
            'largest choice'                    => [[PHP_INT_MAX], "choice-sequence v1\n9223372036854775807\n"],
        ];
    }

    /**
     * @return array<string, array{string, positive-int, string}>
     */
    public static function invalidProvider(): array
    {
        $header = 'Line 1 of the encoded choice sequence is not "choice-sequence v1"';

        return [
            'empty string'                                  => ['', 1, $header],
            'other version'                                 => ["choice-sequence v2\n", 1, $header],
            'header with trailing whitespace'               => ["choice-sequence v1 \n", 1, $header],
            'header with byte order mark'                   => ["\u{FEFF}choice-sequence v1\n", 1, $header],
            'choice instead of header'                      => ["0\n", 1, $header],
            'header without line break'                     => ['choice-sequence v1', 1, 'Line 1 of the encoded choice sequence does not end with a line break'],
            'header with carriage return only'              => ["choice-sequence v1\r", 1, $header],
            'last choice without line break'                => ["choice-sequence v1\n1\n2", 3, 'Line 3 of the encoded choice sequence does not end with a line break'],
            'empty line'                                    => ["choice-sequence v1\n1\n\n", 3, self::notAChoice(3, '')],
            'leading zero'                                  => ["choice-sequence v1\n01\n", 2, self::notAChoice(2, '01')],
            'plus sign'                                     => ["choice-sequence v1\n+1\n", 2, self::notAChoice(2, '+1')],
            'minus sign'                                    => ["choice-sequence v1\n-1\n", 2, self::notAChoice(2, '-1')],
            'leading whitespace'                            => ["choice-sequence v1\n 1\n", 2, self::notAChoice(2, ' 1')],
            'trailing whitespace'                           => ["choice-sequence v1\n1 \n", 2, self::notAChoice(2, '1 ')],
            'letters'                                       => ["choice-sequence v1\nabc\n", 2, self::notAChoice(2, 'abc')],
            'decimal point'                                 => ["choice-sequence v1\n1.0\n", 2, self::notAChoice(2, '1.0')],
            'exponent'                                      => ["choice-sequence v1\n1e3\n", 2, self::notAChoice(2, '1e3')],
            'hexadecimal'                                   => ["choice-sequence v1\n0x1\n", 2, self::notAChoice(2, '0x1')],
            'larger than PHP_INT_MAX'                       => ["choice-sequence v1\n9223372036854775808\n", 2, self::notAChoice(2, '9223372036854775808')],
            'more digits than PHP_INT_MAX'                  => ["choice-sequence v1\n10000000000000000000\n", 2, self::notAChoice(2, '10000000000000000000')],
            'carriage return within a line'                 => ["choice-sequence v1\n1\r2\n", 2, self::notAChoice(2, "1\r2")],
            'line feed after carriage return and line feed' => ["choice-sequence v1\r\n1\n", 2, 'Line 2 of the encoded choice sequence does not end with the same line break as line 1'],
            'carriage return and line feed after line feed' => ["choice-sequence v1\n1\n2\r\n", 3, 'Line 3 of the encoded choice sequence does not end with the same line break as line 1'],
        ];
    }

    /**
     * @return array<string, array{list<non-negative-int>}>
     */
    public static function choicesProvider(): array
    {
        return [
            'no choices'                             => [[]],
            'smallest choice'                        => [[0]],
            'largest choice'                         => [[PHP_INT_MAX]],
            'several choices'                        => [[0, 17, 3]],
            'largest and smallest choices'           => [[PHP_INT_MAX, 0, PHP_INT_MAX]],
            'choices with growing numbers of digits' => [[1, 10, 100, 1000, 10000, 100000, 1000000, 10000000, 100000000, 1000000000]],
            'many choices'                           => [range(0, 999)],
        ];
    }

    /**
     * @param list<non-negative-int> $expected
     */
    #[DataProvider('validProvider')]
    public function testDecodesTheChoicesOfAnEncodedChoiceSequence(array $expected, string $encoded): void
    {
        $this->assertSame($expected, new Decoder()->decode($encoded)->choices());
    }

    public function testDecodesAChoiceSequenceWithoutSpansAndMaxima(): void
    {
        $sequence = new Decoder()->decode("choice-sequence v1\n3\n1\n");

        $this->assertSame([], $sequence->spans());
        $this->assertNull($sequence->maximumAt(0));
    }

    /**
     * @param positive-int $line
     */
    #[DataProvider('invalidProvider')]
    public function testRejectsAnInvalidEncodedChoiceSequenceAndNamesTheInvalidLine(string $encoded, int $line, string $message): void
    {
        try {
            new Decoder()->decode($encoded);
        } catch (InvalidChoiceSequenceException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($line, $e->invalidLine());

            return;
        }

        $this->fail('No exception was thrown');
    }

    public function testRejectsAnInvalidEncodedChoiceSequenceWithAnExceptionThatImplementsTheExceptionInterfaceOfThisLibrary(): void
    {
        $this->expectException(Exception::class);

        new Decoder()->decode('');
    }

    /**
     * @param list<non-negative-int> $choices
     */
    #[DataProvider('choicesProvider')]
    public function testDecodesTheChoicesThatWereEncoded(array $choices): void
    {
        $sequence = new ChoiceSequence($choices);

        $this->assertSame($sequence->choices(), new Decoder()->decode(new Encoder()->encode($sequence))->choices());
    }

    /**
     * @param positive-int $line
     */
    private static function notAChoice(int $line, string $content): string
    {
        return 'Line ' . $line . ' of the encoded choice sequence is not a decimal integer between 0 and 9223372036854775807 without sign and leading zeros: "' . $content . '"';
    }
}
