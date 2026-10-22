<?php

return [
    /*
     * Fortify reescribe la configuración de `laravel/passkeys` al arrancar. Aquí se
     * desactiva la confirmación de contraseña para vincular llaves de acceso: el propio
     * autenticador ya exige huella/Face ID/PIN durante navigator.credentials.create().
     *
     * El resto de valores (redirect, guard, throttle, RP ID) los deriva Fortify de
     * config/fortify.php y APP_URL.
     */
    'passkeys' => [
        'confirmPassword' => false,
    ],
];
