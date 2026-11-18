<?php

/*
 * docs/10 §Contraseñas: Argon2id. Las contraseñas guardadas antes con bcrypt se siguen aceptando (verify = false) y se
 * vuelven a cifrar con Argon2id en el siguiente ingreso correcto (AuthController::login).
 */
return [
    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => false,
        'limit' => null,
    ],

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => false,
    ],

    'rehash_on_login' => true,
];
