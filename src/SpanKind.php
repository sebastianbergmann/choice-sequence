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

/**
 * What the choices of a span stand for, as far as tools that change choice
 * sequences without knowing the code that draws them need to know it. A
 * fuzzer, for instance, inserts a token into a string at the boundaries
 * between its elements, as bytes into a binary string or as characters into a
 * string of Unicode characters.
 *
 * The label of a span tells which spans are drawn the same way, but it is an
 * opaque hash. The kind of a span is optional and tells what it holds.
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/choice-sequence
 */
enum SpanKind
{
    /**
     * A binary string that is drawn as a collection of bytes: each byte is a
     * span of the kind Element. The bytes that the minimum length of the
     * string requires come first and consist of the value of the byte, in
     * the range [0, 255]. Every other byte consists of the choice 1 ("another
     * byte"), whose maximum is 1, followed by the value of the byte. Unless
     * the string has its maximum length, it ends with a span of the kind
     * Element that consists of the choice 0, whose maximum is 1.
     *
     * A byte can be inserted where a choice decides whether there is another
     * byte: before a byte that consists of the choice 1 and its value, and
     * before the span that ends the string.
     */
    case Bytes;

    /**
     * A string of Unicode characters that is drawn as a collection of
     * characters: each character is a span of the kind Element. The
     * characters that the minimum length of the string requires come first
     * and consist of the index of the code point of the character in the
     * order of CodePoints, in the range [0, CodePoints::LARGEST_INDEX].
     * Every other character consists of the choice 1 ("another character"),
     * whose maximum is 1, followed by the index of its code point. Unless
     * the string has its maximum length, it ends with a span of the kind
     * Element that consists of the choice 0, whose maximum is 1.
     *
     * A character can be inserted where a choice decides whether there is
     * another character, as a byte can be inserted into a binary string.
     */
    case Characters;

    /**
     * An element of a collection: the choices of the element, preceded by the
     * choice 1 when it was decided by a choice that there is another element,
     * or the choice 0 that ends the collection. The elements that the minimum
     * size of the collection requires are not decided by a choice and come
     * first.
     */
    case Element;
}
