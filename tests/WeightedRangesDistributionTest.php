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

use function array_unique;
use function array_values;
use function sort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

#[CoversClass(WeightedRangesDistribution::class)]
#[Small]
final class WeightedRangesDistributionTest extends TestCase
{
    public function testSamplesFromTheRangesAccordingToTheirWeights(): void
    {
        $distribution = new WeightedRangesDistribution(
            [
                ['weight' => 1.0, 'minimum' => 10, 'maximum' => 19],
                ['weight' => 0.0, 'minimum' => 20, 'maximum' => 29],
            ],
        );

        $randomizer = new Randomizer(new Xoshiro256StarStar(1));

        for ($i = 0; $i < 100; $i++) {
            $choice = $distribution->sample($randomizer, 100);

            $this->assertGreaterThanOrEqual(10, $choice);
            $this->assertLessThanOrEqual(19, $choice);
        }
    }

    public function testSamplesFromEachRangeInProportionToItsWeight(): void
    {
        $distribution = new WeightedRangesDistribution(
            [
                ['weight' => 3.0, 'minimum' => 0, 'maximum' => 0],
                ['weight' => 1.0, 'minimum' => 1, 'maximum' => 1],
            ],
        );

        $randomizer = new Randomizer(new Xoshiro256StarStar(1));
        $ones       = 0;

        for ($i = 0; $i < 4000; $i++) {
            $ones += $distribution->sample($randomizer, 1);
        }

        // one in four samples is drawn from the range of the second weight
        $this->assertEqualsWithDelta(1000, $ones, 100);
    }

    public function testSamplesFromARangeThatStartsAtTheMaximum(): void
    {
        $distribution = new WeightedRangesDistribution(
            [
                ['weight' => 1.0, 'minimum' => 5, 'maximum' => 9],
            ],
        );

        $randomizer = new Randomizer(new Xoshiro256StarStar(1));

        for ($i = 0; $i < 100; $i++) {
            $this->assertSame(5, $distribution->sample($randomizer, 5));
        }
    }

    public function testIgnoresRangesThatStartAboveTheMaximumAndCutsOffRangesThatExtendBeyondIt(): void
    {
        $distribution = new WeightedRangesDistribution(
            [
                ['weight' => 1.0, 'minimum' => 50, 'maximum' => 59],
                ['weight' => 1.0, 'minimum' => 3, 'maximum' => 40],
            ],
        );

        $randomizer = new Randomizer(new Xoshiro256StarStar(1));

        for ($i = 0; $i < 100; $i++) {
            $choice = $distribution->sample($randomizer, 5);

            $this->assertGreaterThanOrEqual(3, $choice);
            $this->assertLessThanOrEqual(5, $choice);
        }
    }

    public function testSamplesUniformlyWhenAllRangesStartAboveTheMaximum(): void
    {
        $distribution = new WeightedRangesDistribution(
            [
                ['weight' => 1.0, 'minimum' => 50, 'maximum' => 59],
            ],
        );

        $randomizer = new Randomizer(new Xoshiro256StarStar(1));

        $samples = [];

        for ($i = 0; $i < 100; $i++) {
            $samples[] = $distribution->sample($randomizer, 5);
        }

        $this->assertSame([0, 1, 2, 3, 4, 5], array_values(array_unique(self::sorted($samples))));
    }

    /**
     * @param list<int> $samples
     *
     * @return list<int>
     */
    private static function sorted(array $samples): array
    {
        sort($samples);

        return $samples;
    }
}
