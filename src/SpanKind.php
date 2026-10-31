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
 * fuzzer, for instance, inserts the bytes of a token into a binary string at
 * the boundaries between its elements.
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
     * span of the kind Element that consists of the choice 1 ("another
     * byte"), followed by the value of the byte in the range [0, 255]. Unless
     * the string has its maximum length, it ends with a span of the kind
     * Element that consists of the choice 0.
     */
    case Bytes;

    /**
     * An element of a collection: the choices of the element, preceded by the
     * choice 1 when it was decided by a choice that there is another element,
     * or the choice 0 that ends the collection.
     */
    case Element;
}
