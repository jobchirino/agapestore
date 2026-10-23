<?php

return [
    'api_url' => env('BCV_API_URL', 'https://rates.dolarvzla.com/bcv/current.json'),

    'timeout' => (int) env('BCV_TIMEOUT', 4),

    /*
     * El BCV publica entre 15:45 y 17:45 (America/Caracas) en días hábiles.
     * Fuera de esa ventana se tolera media hora de antigüedad; dentro, solo 5 minutos.
     */
    'timezone' => env('BCV_TIMEZONE', 'America/Caracas'),
    'cache_ttl_minutes' => (int) env('BCV_CACHE_TTL_MINUTES', 30),
    'critical_window_start' => '15:45',
    'critical_window_end' => '17:45',
    'critical_ttl_minutes' => 5,

    // Moneda con la que se muestran los equivalentes en Bs. en toda la app.
    'default_currency' => env('BCV_DEFAULT_CURRENCY', 'usd'),
];
