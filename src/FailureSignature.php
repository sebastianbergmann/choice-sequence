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

use function sprintf;

/**
 * Tells whether two failures are the same: when the throwables that ended
 * them have the same class and were raised at the same location, when both
 * exceeded the time limit, or when both are described the same way.
 *
 * Shrinking keeps a simpler choice sequence only when replaying it fails in
 * the same way as the counterexample.
 *
 * @immutable
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for sebastian/choice-sequence
 */
final readonly class FailureSignature
{
    /**
     * @var non-empty-string
     */
    private string $signature;
    private bool $timeout;

    /**
     * @param class-string     $className the class of the throwable that ended the example
     * @param non-empty-string $location  where the throwable was raised, for instance "/path/to/FooTest.php:12"
     */
    public static function forThrowable(string $className, string $location): self
    {
        return new self(
            sprintf(
                '%s at %s',
                $className,
                $location,
            ),
            false,
        );
    }

    public static function forTimeout(): self
    {
        return new self('timeout', true);
    }

    /**
     * @param non-empty-string $description describes a failure that did not end with a throwable
     */
    public static function forDescription(string $description): self
    {
        return new self($description, false);
    }

    /**
     * @param non-empty-string $signature
     */
    private function __construct(string $signature, bool $timeout)
    {
        $this->signature = $signature;
        $this->timeout   = $timeout;
    }

    /**
     * Whether the example was aborted because it exceeded its time limit.
     */
    public function isTimeout(): bool
    {
        return $this->timeout;
    }

    public function isSameAs(self $other): bool
    {
        return $this->signature === $other->signature;
    }

    /**
     * Describes the failure, for instance "RuntimeException at
     * /path/to/FooTest.php:12" or "exceeding the time limit".
     *
     * @return non-empty-string
     */
    public function description(): string
    {
        if ($this->timeout) {
            return 'exceeding the time limit';
        }

        return $this->signature;
    }
}
