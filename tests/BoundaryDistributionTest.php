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
use function array_filter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

#[CoversClass(BoundaryDistribution::class)]
#[Small]
final class BoundaryDistributionTest extends TestCase
{
    public function testSamplesZeroTheMaximumAndSmallChoicesWithTheIntendedProbabilities(): void
    {
        $distribution = new BoundaryDistribution;
        $randomizer   = new Randomizer(new Xoshiro256StarStar(1));
        $counts       = ['zero' => 0, 'one' => 0, 'small' => 0, 'maximum' => 0];
        $samples      = 20000;

        for ($i = 0; $i < $samples; $i++) {
            $choice = $distribution->sample($randomizer, 1000);

            if ($choice === 0) {
                $counts['zero']++;
            }

            if ($choice === 1) {
                $counts['one']++;
            }

            if ($choice >= 1 && $choice <= 16) {
                $counts['small']++;
            }

            if ($choice === 1000) {
                $counts['maximum']++;
            }
        }

        // a choice of 1000 has 10 bits: one in ten samples is 0, one in ten
        // is the maximum, three in ten are uniform among the choices up to
        // 16, and half of them have a uniformly drawn number of bits between
        // 0 and 10 and are uniform among the choices with that number of bits
        $this->assertEqualsWithDelta(0.1 + 0.3 / 17 + 0.5 / 11, $counts['zero'] / $samples, 0.01);
        $this->assertEqualsWithDelta(0.3 / 17 + 0.5 / 11, $counts['one'] / $samples, 0.01);
        $this->assertEqualsWithDelta(0.3 * 16 / 17 + 0.5 * (4 / 11 + 1 / 11 / 16), $counts['small'] / $samples, 0.01);
        $this->assertEqualsWithDelta(0.1 + 0.5 / 11 / 489, $counts['maximum'] / $samples, 0.01);
    }

    public function testSamplesChoicesWithinTheRange(): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(1));

        for ($i = 0; $i < 1000; $i++) {
            $choice = (new BoundaryDistribution)->sample($randomizer, 1000);

            $this->assertGreaterThanOrEqual(0, $choice);
            $this->assertLessThanOrEqual(1000, $choice);
        }
    }

    public function testSamplesTheBoundariesSmallChoicesAndChoicesOfEveryMagnitude(): void
    {
        $randomizer = new Randomizer(new Xoshiro256StarStar(1));
        $samples    = [];

        for ($i = 0; $i < 2000; $i++) {
            $samples[] = (new BoundaryDistribution)->sample($randomizer, PHP_INT_MAX);
        }

        $this->assertContains(0, $samples);
        $this->assertContains(1, $samples);
        $this->assertContains(PHP_INT_MAX, $samples);
        $this->assertNotEmpty(array_filter($samples, static fn (int $choice): bool => $choice > 1000 && $choice < 1000000));
        $this->assertNotEmpty(array_filter($samples, static fn (int $choice): bool => $choice > 1000000000000 && $choice < PHP_INT_MAX));
    }

    public function testSamplesZeroWhenTheRangeOnlyContainsZero(): void
    {
        $this->assertSame(0, (new BoundaryDistribution)->sample(new Randomizer(new Xoshiro256StarStar(1)), 0));
    }
}
