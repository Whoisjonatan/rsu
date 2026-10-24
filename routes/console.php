<?php

use App\Models\Scheduling;
use App\Models\Vacation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Vacaciones aprobadas que ya terminaron pasan a "gozadas" (W10).
Artisan::command('vacations:close', function () {
    $this->info(Vacation::closeFinished().' período(s) marcados como gozados.');
})->purpose('Marca como gozadas las vacaciones aprobadas que ya terminaron');

Schedule::command('vacations:close')->dailyAt('00:05');

// Programaciones activas que ya pasaron su fecha de término pasan a "finalizada" (W17).
Artisan::command('schedulings:close', function () {
    $this->info(Scheduling::closeFinished().' programación(es) finalizadas.');
})->purpose('Finaliza las programaciones activas cuya fecha de término ya pasó');

Schedule::command('schedulings:close')->dailyAt('00:05');
