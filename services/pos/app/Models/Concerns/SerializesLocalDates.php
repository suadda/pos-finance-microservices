<?php

namespace App\Models\Concerns;

use DateTimeInterface;

/** Serialise timestamps in Asia/Jakarta with offset (e.g. 2026-06-15T09:30:00+07:00) instead of UTC. */
trait SerializesLocalDates
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:sP');
    }
}
