<?php

use App\Models\AgentApiToken;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled automation (PRD §11)
|--------------------------------------------------------------------------
|
| Weekly audit sweep: every active laptop is asked to re-verify itself, so the
| register stays fresh without anyone clicking anything. Requires the scheduler
| to run (`php artisan schedule:work` locally, or cron in production).
|
*/

Schedule::command('audit:sweep')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping()
    ->when(fn () => AgentApiToken::where('is_active', true)->exists());

