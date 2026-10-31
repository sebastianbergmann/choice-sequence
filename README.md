[![Latest Stable Version](https://poser.pugx.org/sebastian/choice-sequence/v)](https://packagist.org/packages/sebastian/choice-sequence)
[![CI Status](https://github.com/sebastianbergmann/choice-sequence/workflows/CI/badge.svg)](https://github.com/sebastianbergmann/choice-sequence/actions)
[![codecov](https://codecov.io/gh/sebastianbergmann/choice-sequence/branch/main/graph/badge.svg)](https://codecov.io/gh/sebastianbergmann/choice-sequence)

# sebastian/choice-sequence

Library for drawing, replaying, shrinking, and storing choice sequences.

A choice sequence is the list of non-negative integers from which a value was generated: whenever the code that generates the value has to decide something, such as the length of a list or the next character of a string, it draws a choice, and 0 stands for the simplest option. Replaying the same choices generates the same value, so a value can be made simpler by making its choice sequence simpler: shorter, or lexicographically smaller.

This library provides the choice sequence, the drawing and replaying of choices, and the shrinker that searches for the simplest choice sequence that still fails in the same way, independent of how values are generated from the choices and of how a failure is detected.

## Installation

You can add this library as a local, per-project dependency to your project using [Composer](https://getcomposer.org/):

```
composer require sebastian/choice-sequence
```

If you only need this library during development, for instance to run your project's test suite, then you should add it as a development-time dependency:

```
composer require --dev sebastian/choice-sequence
```

## Usage

### Drawing and replaying choices

Code that generates a value draws its choices from a choice source. A choice source draws the choices at random, or replays the choices of a choice sequence. Spans mark the choices that form one part of the value, which helps the shrinker.

#### `draw.php`
```php
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SebastianBergmann\ChoiceSequence\ChoiceSource;
use SebastianBergmann\ChoiceSequence\UniformDistribution;

function word(ChoiceSource $source): string
{
    $word   = '';
    $length = $source->draw(8, new UniformDistribution);

    for ($i = 0; $i < $length; $i++) {
        $source->startSpan('letter');

        $word .= $source->choose(range('a', 'z'), new UniformDistribution);

        $source->endSpan();
    }

    return $word;
}

$source = ChoiceSource::random(new Randomizer(new Xoshiro256StarStar(1234)));

print word($source) . PHP_EOL;

$sequence = $source->sequence();

print implode(', ', $sequence->choices()) . PHP_EOL;
print word(ChoiceSource::replay($sequence)) . PHP_EOL;
```

```
yrbwbzig
8, 24, 17, 1, 22, 1, 25, 8, 6
yrbwbzig
```

### Labels and kinds of spans

The label of a span identifies how its choices are drawn: code that draws choices in the same way labels its spans in the same way. `Label::of()` computes a label from a name and its components, for instance the parameters of a generator, in the same way for every tool that uses this library.

A span may also have a kind, which tells tools that change choice sequences without knowing the code that draws them what the choices stand for: `SpanKind::Bytes` for a binary string that is drawn as a collection of bytes, and `SpanKind::Element` for an element of a collection, for instance one byte of such a string.

```php
use SebastianBergmann\ChoiceSequence\Label;
use SebastianBergmann\ChoiceSequence\SpanKind;

$source->startSpan(Label::of('bytes', '0', '4096'), SpanKind::Bytes);
```

### Shrinking a choice sequence

The shrinker is given a closure that replays a candidate. The closure returns the choice sequence that the replay consumed when the candidate still fails in the same way, and `null` otherwise.

#### `shrink.php`
```php
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Shrinker;

$shrinker = new Shrinker(
    static function (ChoiceSequence $candidate): ?ChoiceSequence
    {
        // this example fails when its choices add up to 10 or more
        if (array_sum($candidate->choices()) < 10) {
            return null;
        }

        return $candidate;
    },
    1000,
);

$result = $shrinker->shrink(new ChoiceSequence([7, 3, 9, 4]));

print implode(', ', $result->sequence()->choices()) . PHP_EOL;
print $result->numberOfSteps() . ' steps, ' . $result->numberOfAttempts() . ' attempts' . PHP_EOL;
```

```
10
3 steps, 22 attempts
```

### Telling failures apart

A failure signature tells whether two failures are the same: when they are of the same kind and, unless they exceeded the time limit, have the same text. Its kind and its text can be stored, together with the choice sequence of a counterexample, for instance, and the signature can be recreated from them:

#### `signature.php`
```php
use SebastianBergmann\ChoiceSequence\FailureSignature;

$signature = FailureSignature::forThrowable(RuntimeException::class, 'src/Parser.php:12');

print $signature->kind() . PHP_EOL;
print $signature->text() . PHP_EOL;

$recreated = FailureSignature::fromKindAndText($signature->kind(), $signature->text());

var_dump($recreated->isSameAs($signature));
```

```
throwable
RuntimeException at src/Parser.php:12
bool(true)
```

### Storing choice sequences

A choice sequence can be stored in a text file, for instance to replay a failing example again later. The format is meant to be compared with diff: the line `choice-sequence v1` is followed by one choice per line, each choice is a decimal integer without sign and leading zeros, and each line ends with a line feed. Only the choices are stored: spans and maxima are recorded again when the choices are replayed.

#### `store.php`
```php
use SebastianBergmann\ChoiceSequence\ChoiceSequence;
use SebastianBergmann\ChoiceSequence\Decoder;
use SebastianBergmann\ChoiceSequence\Encoder;

$encoded = (new Encoder)->encode(new ChoiceSequence([0, 17, 3]));

print $encoded;

$sequence = (new Decoder)->decode($encoded);

print implode(', ', $sequence->choices()) . PHP_EOL;
```

```
choice-sequence v1
0
17
3
0, 17, 3
```

The decoder also accepts lines that end with a carriage return and a line feed, which Git can turn line feeds into when a file is checked out on Windows. Anything else is rejected with an `InvalidChoiceSequenceException` that names the line that is not valid.
