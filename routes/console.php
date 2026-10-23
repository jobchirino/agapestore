<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ventana crítica del BCV (15:45–17:45 en Caracas): buscar el corte cada 5 minutos.
Schedule::command('bcv:refresh')
    ->everyFiveMinutes()
    ->weekdays()
    ->between('15:45', '17:45')
    ->timezone('America/Caracas')
    ->withoutOverlapping();

// Resto del día: media hora, por si el BCV publica con retraso.
Schedule::command('bcv:refresh')
    ->everyThirtyMinutes()
    ->timezone('America/Caracas')
    ->withoutOverlapping();
