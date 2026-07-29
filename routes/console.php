<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ERI certificate expiry sweep (PLAN_PHASE_E.md §4) — daily, flips ACTIVE
// certs past their valid_to to EXPIRED. See App\Console\Commands\
// ExpireCertificatesCommand.
Schedule::command('certificates:expire')->daily();
