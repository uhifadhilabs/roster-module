<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Roster Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Roster\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * A PATTERN IS ONE SHARED OBJECT, NAMED BY ITS CYCLE, AND EDITING IT MOVES
 * NOBODY.
 *
 * THE THIRD OF THOSE IS WHY THE FIRST IS SAFE. One object at area level is
 * only defensible because an edit reaches future fills and nothing else;
 * the alternative the ruling rejected — one copy per station — is three
 * objects going quietly out of step, and the alternative to THAT is an
 * edit that re-plans people who are already rostered.
 */
final class PatternServiceTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    private Station $outpost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'north gate post', 'ST-01');
        $this->outpost = $this->aStation($this->area, 'far outpost', 'ST-02');
        $this->theShiftVocabulary($this->area);
        $this->em->flush();
    }

    private function patterns(): PatternService
    {
        $patterns = $this->service(PatternService::class);
        self::assertInstanceOf(PatternService::class, $patterns);

        return $patterns;
    }

    private function watches(): StationWatchService
    {
        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);

        return $watches;
    }

    /** A FRESH AREA HAS NONE, and that is a state rather than a gap. */
    public function testAFreshAreaFillsFromNothing(): void
    {
        self::assertSame([], $this->patterns()->forArea($this->area));
    }

    /** SAY A CYCLE AND IT GETS A NAME — in this area's own words. */
    public function testTheNameIsDerivedFromTheCycle(): void
    {
        $pattern = $this->patterns()->create($this->area, Cycle::of(['day', 'day', 'night', 'night', Cycle::OFF]));

        self::assertSame('2 days of Day, 2 days of Night, 1 off', $this->patterns()->nameOf($pattern));
        self::assertSame(5, $pattern->length());
    }

    /**
     * AND RENAMING THE SHIFT RENAMES THE PATTERN, because the name is
     * derived on every read and stored nowhere. A name kept beside the
     * cycle would be a second copy going stale the first time somebody
     * corrected a spelling.
     */
    public function testRenamingAShiftRenamesEveryPatternBuiltFromIt(): void
    {
        $pattern = $this->patterns()->create($this->area, Cycle::of(['day', 'day', Cycle::OFF]));
        self::assertSame('2 days of Day, 1 off', $this->patterns()->nameOf($pattern));

        $vocabulary = $this->service(ShiftVocabularyService::class);
        self::assertInstanceOf(ShiftVocabularyService::class, $vocabulary);

        foreach ($vocabulary->forArea($this->area) as $shift) {
            if ('day' === $shift->getKey()) {
                $vocabulary->rename($shift, 'early');
            }
        }

        self::assertSame('2 days of early, 1 off', $this->patterns()->nameOf($pattern));
    }

    /** ONE OBJECT, RUN AT TWO STATIONS — and it names them, never only counts. */
    public function testOnePatternRunsAtManyStationsAndNamesThem(): void
    {
        $pattern = $this->patterns()->create($this->area, Cycle::of(['day', 'day', Cycle::OFF]));

        $this->patterns()->applyTo($this->watches()->addToRoster($this->gate), $pattern);
        $this->patterns()->applyTo($this->watches()->addToRoster($this->outpost), $pattern);

        $names = [];
        foreach ($this->patterns()->stationsRunning($pattern) as $station) {
            // A station's name is nullable in the area's own model — a
            // nameless one would be a fixture bug, and the test says so
            // here rather than comparing against a silent null.
            self::assertNotNull($station->getName());
            $names[] = $station->getName();
        }
        sort($names);

        self::assertSame(['far outpost', 'north gate post'], $names);
    }

    /**
     * EDITING CHANGES FUTURE FILLS ONLY. The day already planned at a
     * station running this pattern stays exactly where it was — not
     * because the edit filtered it out, but because editing a cycle is not
     * a verb that writes duties at all.
     */
    public function testEditingThePatternMovesNobodyWhoIsAlreadyPlanned(): void
    {
        $pattern = $this->patterns()->create($this->area, Cycle::of(['day', 'day', Cycle::OFF]));
        $this->patterns()->applyTo($this->watches()->addToRoster($this->gate), $pattern);

        $planned = new Duty($this->area, $this->gate, $this->aPerson('ada@example.test'), 'day', new \DateTimeImmutable('2026-10-01'));
        $this->em->persist($planned);
        $this->em->flush();

        $this->patterns()->save($pattern, Cycle::of(['night', 'night', 'night', Cycle::OFF]));

        self::assertSame('3 days of Night, 1 off', $this->patterns()->nameOf($pattern));

        $duties = $this->em->getRepository(Duty::class)->findAll();
        self::assertCount(1, $duties, 'Editing a cycle writes no duty and removes none.');
        self::assertSame('day', $duties[0]->getShiftKey(), 'And the day already planned still says what it said.');
    }

    /**
     * DELETING ONE STOPS THE FILL AND KEEPS THE STATION. Everything the
     * station knew about itself survives, including every day already
     * planned — which is why the relation is SET NULL and not CASCADE.
     */
    public function testDeletingAPatternLeavesTheStationsItFilled(): void
    {
        $pattern = $this->patterns()->create($this->area, Cycle::of(['day', Cycle::OFF]));
        $watch = $this->watches()->addToRoster($this->gate);
        $this->patterns()->applyTo($watch, $pattern);

        $this->patterns()->delete($pattern);

        self::assertNull($watch->getPattern(), 'The station stops being filled.');
        self::assertSame([], $this->patterns()->forArea($this->area));
        self::assertNotNull($this->watches()->forStation($this->gate), 'And it is still on the books.');
    }
}
