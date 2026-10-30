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
