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
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Model\SheetCell;
use Uhifadhi\Roster\Model\SheetCellKind;
use Uhifadhi\Roster\Model\SheetWindow;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\SheetDayService;
use Uhifadhi\Roster\Service\SheetService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * WHAT EACH ITEM ON THE BY-HAND MENU LEAVES BEHIND.
 *
 * THE ASSERTION IS THE SHEET, NOT THE SERVICE'S RETURN. The design's own
 * answer to "what happens after each action" is four frames ending in the
 * sheet that results, so every test here runs the verb and then READS THE
 * SHEET BACK — the same read the tab does. A verb that stored the right
 * rows and drew the wrong cell would be the defect the frames exist to
 * prevent.
 *
 * AND THE ONE THAT KEEPS BEING READ WRONGLY IS THE DAY OFF. Standing
 * somebody down on a day the station's cycle wanted covered is a gap: the
 * seat is open and nobody is on it. On a day the cycle asked nothing of
 * them it is simply a quiet day. So the mark records what the HAND did
 * and the cycle decides how it READS, which is why one verb has two
 * tests. (Design note, day-menu flow-b: an approved day off reading as
 * "off" rather than "unfilled" would be a rule, and rules live on
 * Watches — so until one is written, cover expected means unfilled.)
 */
final class SheetDayServiceTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    private User $ada;
    private User $bea;
    private \DateTimeImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'seneto gate post', 'ST-01');
        $this->theShiftVocabulary($this->area);
        $this->ada = $this->aPerson('ada@example.test', 'Ada', 'Example');
        $this->bea = $this->aPerson('bea@example.test', 'Bea', 'Example');
        $this->em->flush();

        foreach ([$this->ada, $this->bea] as $person) {
            $this->em->persist(
                new Posting()->setStation($this->gate)->setPerson($person)->setSince(new \DateTimeImmutable('-1 year'))->setSource(PostingSource::WrittenHere),
            );
        }
        $this->em->flush();

        $this->monday = new \DateTimeImmutable('today')->modify('monday this week');
    }

    private function days(): SheetDayService
    {
        $service = static::getContainer()->get('test_public.'.SheetDayService::class);
        self::assertInstanceOf(SheetDayService::class, $service);

        return $service;
    }

    private function sheet(): SheetService
    {
        $service = static::getContainer()->get('test_public.'.SheetService::class);
        self::assertInstanceOf(SheetService::class, $service);

        return $service;
    }

    /** A RING THAT ASKS FOR A DAY WATCH EVERY DAY, so every cell is one the cycle expects cover on. */
    private function theCycleExpectsCoverEveryDay(): void
    {
        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $patterns = static::getContainer()->get('test_public.'.PatternService::class);
        self::assertInstanceOf(PatternService::class, $patterns);

        $watch = $watches->addToRoster($this->gate);
        $watch->expect(['day']);
        $patterns->applyTo($watch, $patterns->create($this->area, Cycle::of(['day'])));
        $watch->filledFrom($this->monday, $this->monday->modify('+27 days'));
        $this->em->flush();
    }

    private function aDuty(User $person, string $shift = 'night'): Duty
    {
        $duty = new Duty($this->area, $this->gate, $person, $shift, $this->monday);
        $this->em->persist($duty);
        $this->em->flush();

        return $duty;
    }

    /** MONDAY'S CELL ON ONE RANGER'S ROW, read back the way the tab reads it. */
    private function mondayOf(User $person): SheetCell
    {
        $sheet = $this->sheet()->read($this->area, SheetWindow::of($this->monday, 2, new \DateTimeImmutable('today')));
        foreach ($sheet->bands[0]->rows as $row) {
            if ($row->personUuid === (string) $person->getUuidString()) {
                return $row->cells[0];
            }
        }

        self::fail('That ranger has no row on the sheet.');
    }

    private function unfilledAtTheStation(): int
    {
        return $this->sheet()->read($this->area, SheetWindow::of($this->monday, 2, new \DateTimeImmutable('today')))->bands[0]->unfilled();
    }

    /** CHANGE THE SHIFT — the cell wears the new shift and carries the hand mark. */
    public function testChangingTheShiftLeavesTheWatchWearingItAndMarked(): void
    {
        $this->theCycleExpectsCoverEveryDay();
        $duty = $this->aDuty($this->ada);

        $this->days()->changeShift($duty, 'day', null);

        $cell = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Watch, $cell->kind);
        self::assertSame('day', $cell->shiftKey);
        self::assertTrue($cell->editedByHand, 'A day a person decided is a day a fill must not decide again.');
    }

    /**
     * MOVE TO SOMEONE ELSE — the day sits on the other ranger's row, and
     * the origin is UNFILLED because the cycle expected cover there.
     */
    public function testMovingTheDayLeavesTheOriginUnfilledWhereCoverWasExpected(): void
    {
        /*
         * THE DESIGN'S OWN FRAME: the ranger taking the day was already
         * covered that day, so the station head goes up by one — the
         * origin opens and nothing closes. Where the destination was
         * itself a gap the move only carries the gap across, and the
         * head is unchanged; that is arithmetic, not a different rule.
         */
        $this->theCycleExpectsCoverEveryDay();
        $duty = $this->aDuty($this->ada);
        $this->aDuty($this->bea, 'day');
        $before = $this->unfilledAtTheStation();

        $this->days()->moveTo($duty, $this->bea, null);

        $taken = $this->mondayOf($this->bea);
        self::assertSame(SheetCellKind::Watch, $taken->kind);
        self::assertTrue($taken->editedByHand);

        $origin = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Unfilled, $origin->kind, 'Nobody is on a day the ring asked for.');
        self::assertTrue($origin->editedByHand);

        self::assertSame($before + 1, $this->unfilledAtTheStation(), 'The station head counts one more gap.');
    }

    /** AND WHERE THE CYCLE ASKED NOTHING, the origin is simply empty. */
    public function testMovingTheDayLeavesAnEmptyOriginWhereNothingWasExpected(): void
    {
        $duty = $this->aDuty($this->ada);

        $this->days()->moveTo($duty, $this->bea, null);

        $origin = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Off, $origin->kind, 'No ring, no expectation, no alarm ink.');
        self::assertTrue($origin->editedByHand);
        self::assertSame(0, $this->unfilledAtTheStation());
    }

    /**
     * GIVE THE DAY OFF — the watch goes, and on a day the cycle wanted
     * covered the cell reads UNFILLED, not empty. The seat is open.
     */
    public function testGivingTheDayOffReadsUnfilledWhereCoverWasExpected(): void
    {
        $this->theCycleExpectsCoverEveryDay();
        $duty = $this->aDuty($this->ada);
        $before = $this->unfilledAtTheStation();

        $this->days()->giveTheDayOff($duty, null);

        $cell = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Unfilled, $cell->kind);
        self::assertTrue($cell->editedByHand);
        self::assertSame($before + 1, $this->unfilledAtTheStation());
    }

    /** AND ON A DAY NOTHING WAS ASKED OF, the day off draws nothing at all. */
    public function testGivingTheDayOffDrawsNothingWhereNothingWasExpected(): void
    {
        $duty = $this->aDuty($this->ada);

        $this->days()->giveTheDayOff($duty, null);

        $cell = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Off, $cell->kind);
        self::assertTrue($cell->editedByHand);
        self::assertSame(0, $this->unfilledAtTheStation());
    }

    /**
     * MARK UNFILLED — the destructive one. The seat stays open in the
     * alarm ink whatever the ring says, because the verb IS the decision.
     */
    public function testMarkingUnfilledOutlinesTheDayEvenWithNoCycle(): void
    {
        $duty = $this->aDuty($this->ada);

        $this->days()->markUnfilled($duty, null);

        $cell = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Unfilled, $cell->kind);
        self::assertTrue($cell->editedByHand);
        self::assertSame(1, $this->unfilledAtTheStation(), 'Raised as needing a decision.');
    }

    /** CLEAR THE HAND MARK — the mark goes, the shift stays, the next fill owns the day again. */
    public function testClearingTheMarkHandsTheDayBackToThePattern(): void
    {
        $duty = $this->aDuty($this->ada);
        $this->days()->changeShift($duty, 'day', null);

        $this->days()->clearTheMark($this->gate, $this->monday, $this->ada);

        $cell = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Watch, $cell->kind);
        self::assertSame('day', $cell->shiftKey, 'The shift the hand chose stays.');
        self::assertFalse($cell->editedByHand);
    }

    /** AND THE MARK IS ONE RANGER'S — clearing Ada's leaves Bea's alone. */
    public function testClearingOneRangersMarkLeavesTheOthersStanding(): void
    {
        $this->days()->changeShift($this->aDuty($this->ada), 'day', null);
        $this->days()->changeShift($this->aDuty($this->bea), 'day', null);

        $this->days()->clearTheMark($this->gate, $this->monday, $this->ada);

        self::assertFalse($this->mondayOf($this->ada)->editedByHand);
        self::assertTrue($this->mondayOf($this->bea)->editedByHand);
    }
}
