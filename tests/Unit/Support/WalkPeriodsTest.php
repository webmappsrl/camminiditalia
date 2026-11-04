<?php

namespace Tests\Unit\Support;

use App\Support\UgcRecordingDate;
use App\Support\WalkPeriods;
use PHPUnit\Framework\TestCase;

/**
 * Percorrenze e giorno di una registrazione (oc:8169, ciclo 4).
 */
class WalkPeriodsTest extends TestCase
{
    public function test_latest_keeps_only_the_most_recent_walk(): void
    {
        $this->assertSame(['2026-05-10', '2026-05-11'], WalkPeriods::latest(['2026-05-11', '2024-05-10', '2026-05-10']));
    }

    public function test_days_thirty_days_apart_are_the_same_walk(): void
    {
        $this->assertSame(['2026-04-01', '2026-05-01'], WalkPeriods::latest(['2026-05-01', '2026-04-01']));
        $this->assertSame(['2026-05-02'], WalkPeriods::latest(['2026-04-01', '2026-05-02']));
    }

    public function test_empty_and_duplicates(): void
    {
        $this->assertSame([], WalkPeriods::latest([]));
        $this->assertSame(['2026-05-10'], WalkPeriods::latest(['2026-05-10', '2026-05-10']));
    }

    public function test_recording_day_is_the_first_point_in_rome(): void
    {
        // 22:30 UTC del 1° maggio è già il 2 maggio a Roma
        $this->assertSame('2026-05-02', UgcRecordingDate::day((string) strtotime('2026-05-01T22:30:00Z') * 1000, null, null));
        $this->assertSame('2026-05-02', UgcRecordingDate::day(null, '2026-05-01T22:30:00Z', null));
        $this->assertSame('2026-05-01', UgcRecordingDate::day(null, 'non è una data', '2026-05-01 10:00:00'));
        $this->assertNull(UgcRecordingDate::day(null, null, null));
    }
}
