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
use Uhifadhi\Roster\Entity\EditedDay;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Model\SheetCellKind;
use Uhifadhi\Roster\Model\SheetWindow;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\SheetService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE SHEET, READ — people down, days across, per station.
 *
 * WHAT THIS TEST DEFENDS is the one thing the tab exists for: a gap shows
 * at once. Everything else on the sheet is a convenience; an unfilled day
 * that draws as an ordinary quiet one is the tab failing at its job.
 */
final class SheetServiceTest extends IntegrationTestCase
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

    private function sheet(): SheetService
    {
        $service = static::getContainer()->get('test_public.'.SheetService::class);
        self::assertInstanceOf(SheetService::class, $service);

        return $service;
    }

    private function watches(): StationWatchService
    {
        $service = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $service);

        return $service;
    }

    private function patterns(): PatternService
    {
        $service = static::getContainer()->get('test_public.'.PatternService::class);
        self::assertInstanceOf(PatternService::class, $service);

        return $service;
    }

    private function window(int $weeks = 2): SheetWindow
    {
        return SheetWindow::of($this->monday, $weeks, new \DateTimeImmutable('today'));
    }

    /**
     * A STATION WITH NOBODY STATIONED AT IT STILL GETS A BAND. It is on
     * the area's books, and a sheet that quietly left it out could never
     * be used to notice it.
     */
    public function testEveryStationOnTheBooksGetsABand(): void
    {
        $this->aStation($this->area, 'lemagrut roadside post', 'ST-12');
        $this->em->flush();

        $sheet = $this->sheet()->read($this->area, $this->window());

        self::assertCount(2, $sheet->bands);
        self::assertSame(['ST-01', 'ST-12'], array_map(static fn ($band) => $band->stationCode, $sheet->bands));
        self::assertSame(0, $sheet->bands[1]->rangers(), 'Nobody is stationed there, and the band says so.');
    }

    /** RANGERS DOWN, ONE ROW EACH, AND A CELL PER DAY OF THE WINDOW. */
    public function testTheSheetIsPeopleDownAndTheWindowAcross(): void
    {
        $sheet = $this->sheet()->read($this->area, $this->window(2));

        $band = $sheet->bands[0];
        self::assertSame(2, $band->rangers());
        self::assertSame(['Ada Example', 'Bea Example'], array_map(static fn ($row) => $row->personName, $band->rows));
        self::assertCount(14, $band->rows[0]->cells);

        $sheet = $this->sheet()->read($this->area, $this->window(4));
        self::assertCount(28, $sheet->bands[0]->rows[0]->cells);
    }

    /**
     * A DAY WITH A DUTY ON IT IS THE SHIFT'S OWN COLOUR — the stored slot,
     * so the same watch is the same colour on every tab.
     */
    public function testADutyDrawsTheShiftsOwnColour(): void
    {
        $this->em->persist(new Duty($this->area, $this->gate, $this->ada, 'day', $this->monday));
        $this->em->flush();

        $cell = $this->sheet()->read($this->area, $this->window())->bands[0]->rows[0]->cells[0];

        self::assertSame(SheetCellKind::Watch, $cell->kind);
        self::assertSame('day', $cell->shiftKey);
        self::assertIsInt($cell->colour);
        self::assertNotNull($cell->dutyUuid);
    }

    /**
     * AND A DAY THE STATION'S CYCLE ASKS FOR AND NOBODY IS ON DRAWS AS
     * UNFILLED. This is the assertion the tab exists for.
     */
    public function testADayTheCycleAsksForAndNobodyIsOnIsUnfilled(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day']);
        // A ring of nothing but day watches: every day of the window is
        // asked for, and no duty has been written for any of them.
        $this->patterns()->applyTo($watch, $this->patterns()->create($this->area, Cycle::of(['day'])));
        $watch->filledFrom($this->monday, $this->monday->modify('+27 days'));
        $this->em->flush();

        $row = $this->sheet()->read($this->area, $this->window())->bands[0]->rows[0];

        self::assertSame(14, $row->unfilled());
        self::assertSame(SheetCellKind::Unfilled, $row->cells[0]->kind);
    }

    /**
     * A STATION NOBODY HAS FILLED YET EXPECTS NOTHING OF ANYBODY. Null is
     * the honest answer, and a fortnight of alarm ink at a station nobody
     * has made a decision about would be the sheet shouting at a reader
     * who has done nothing wrong.
     */
    public function testAStationWithNoCycleDrawsNoGaps(): void
    {
        $row = $this->sheet()->read($this->area, $this->window())->bands[0]->rows[0];

        self::assertSame(0, $row->unfilled());
        self::assertSame(SheetCellKind::Off, $row->cells[0]->kind);
    }

    /**
     * EACH RANGER ENTERS THE RING ONE DAY LATER THAN THE LAST, which is
     * how one cycle staffs a station without anybody writing a rota — and
     * why the seat is part of the row.
     */
    public function testEachRangerEntersTheRingOneDayAfterTheLast(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day']);
        $this->patterns()->applyTo($watch, $this->patterns()->create($this->area, Cycle::of(['day', Cycle::OFF])));
        $watch->filledFrom($this->monday, $this->monday->modify('+27 days'));
        $this->em->flush();

        $band = $this->sheet()->read($this->area, $this->window())->bands[0];

        self::assertSame(0, $band->rows[0]->seat);
        self::assertSame(1, $band->rows[1]->seat);
        self::assertSame(SheetCellKind::Unfilled, $band->rows[0]->cells[0]->kind, 'The first seat is on the ring\'s first day.');
        self::assertSame(SheetCellKind::Off, $band->rows[1]->cells[0]->kind, 'The second enters a day later.');
    }

    /**
     * A HAND MARK IS ONE RANGER'S DAY, and it carries which of the two
     * things the hand did: stood them down, or left the seat open.
     */
    public function testAHandMarkIsOneRangersDayAndSaysWhichKind(): void
    {
        $this->em->persist(new EditedDay($this->gate, $this->monday, null, new \DateTimeImmutable(), $this->ada, true));
        $this->em->persist(new EditedDay($this->gate, $this->monday->modify('+1 day'), null, new \DateTimeImmutable(), $this->ada, false));
        $this->em->flush();

        $rows = $this->sheet()->read($this->area, $this->window())->bands[0]->rows;

        self::assertTrue($rows[0]->cells[0]->editedByHand);
        self::assertSame(SheetCellKind::Off, $rows[0]->cells[0]->kind, 'Stood down by hand draws nothing, and carries the mark.');
        self::assertSame(SheetCellKind::Unfilled, $rows[0]->cells[1]->kind, 'Marked unfilled by hand is a gap like any other.');
        self::assertFalse($rows[1]->cells[0]->editedByHand, 'The mark is one ranger\'s, not the whole station\'s.');
    }

    /** THE SHEET'S OWN FIGURES COME OFF ITS OWN CELLS. */
    public function testTheFiguresCountWhatTheSheetDraws(): void
    {
        $this->em->persist(new Duty($this->area, $this->gate, $this->ada, 'day', $this->monday));
        $this->em->persist(new EditedDay($this->gate, $this->monday->modify('+1 day'), null, new \DateTimeImmutable(), $this->bea, false));
        $this->em->flush();

        $sheet = $this->sheet()->read($this->area, $this->window());

        self::assertSame(2, $sheet->rangers());
        self::assertSame(1, $sheet->daysPlanned());
        self::assertSame(1, $sheet->unfilled());
        self::assertSame(1, $sheet->editedByHand());
    }

    /** ONE STATION ONLY, when the head's filter names one. */
    public function testTheFilterNarrowsTheSheetToOneStation(): void
    {
        $this->aStation($this->area, 'lerai ranger post', 'ST-02');
        $this->em->flush();

        $sheet = $this->sheet()->read($this->area, $this->window(), $this->gate);

        self::assertCount(1, $sheet->bands);
        self::assertSame('ST-01', $sheet->bands[0]->stationCode);
        self::assertSame(2, $sheet->stations, 'The count is still the area\'s, so the chip can say what it is filtering out of.');
    }
}
