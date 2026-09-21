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

namespace Uhifadhi\Roster\Model;

/**
 * ONE STATION'S BAND OF THE SHEET — its head, and the rangers stationed
 * at it.
 *
 * A BAND FOLDS, and a FOLDED BAND STILL STATES ITS UNFILLED DAYS. Folding
 * is for length — a sheet of 34 rangers is not read by scrolling for ages
 * — and it may never hide a gap, which is why {@see $unfilled} is on the
 * head and not only in the rows underneath it.
 *
 * A STATION WITH NOBODY STATIONED AT IT STILL GETS A BAND. It is on the
 * area's books, and a sheet that quietly left it out would be a sheet
 * that cannot be used to notice it.
 */
final readonly class SheetBand
{
    /**
     * @param list<SheetRow> $rows
     */
    public function __construct(
        public string $stationUuid,
        public string $stationName,
        public ?string $stationCode,
        public array $rows,
    ) {
    }

    public function rangers(): int
    {
        return \count($this->rows);
    }

    public function unfilled(): int
    {
        $unfilled = 0;
        foreach ($this->rows as $row) {
            $unfilled += $row->unfilled();
        }

        return $unfilled;
    }

    /** The code the fold is keyed by; a station with none is keyed by its uuid. */
    public function foldKey(): string
    {
        return $this->stationCode ?? $this->stationUuid;
    }
}
