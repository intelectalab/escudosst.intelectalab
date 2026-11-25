<?php

// Mensajes de validación en español sencillo. Las reglas que no están aquí usan el texto en inglés de Laravel.
return [
    'accepted' => 'Debes aceptar «:attribute».',
    'after' => '«:attribute» debe ser una fecha posterior a :date.',
    'after_or_equal' => '«:attribute» debe ser una fecha igual o posterior a :date.',
    'array' => '«:attribute» no tiene el formato esperado.',
    'before' => '«:attribute» debe ser una fecha anterior a :date.',
    'before_or_equal' => '«:attribute» debe ser una fecha igual o anterior a :date.',
    'boolean' => '«:attribute» debe ser sí o no.',
    'date' => '«:attribute» no es una fecha válida.',
    'email' => '«:attribute» no es un correo válido.',
    'exists' => 'No encontramos «:attribute».',
    'in' => 'Elige una opción válida en «:attribute».',
    'integer' => '«:attribute» debe ser un número entero.',
    'max' => [
        'numeric' => '«:attribute» no puede ser mayor que :max.',
        'string' => '«:attribute» admite hasta :max caracteres.',
        'array' => '«:attribute» admite hasta :max elementos.',
        'file' => '«:attribute» no puede pesar más de :max KB.',
    ],
    'min' => [
        'numeric' => '«:attribute» debe ser al menos :min.',
        'string' => '«:attribute» debe tener al menos :min caracteres.',
        'array' => '«:attribute» debe tener al menos :min elementos.',
        'file' => '«:attribute» debe pesar al menos :min KB.',
    ],
    'numeric' => '«:attribute» debe ser un número.',
    'required' => 'Completa «:attribute».',
    'string' => '«:attribute» debe ser un texto.',
    'url' => '«:attribute» no es un enlace válido.',
    'uuid' => '«:attribute» no es válido.',

    'attributes' => [
        'password' => 'contraseña',
        'worker_id' => 'trabajador',
        'program_id' => 'programa',
        'document' => 'documento',
        'paid_on' => 'fecha del pago',
    ],

    // Valores mostrados en los mensajes (p. ej. «igual o anterior a hoy»).
    'values' => [
        'paid_on' => ['today' => 'hoy'],
    ],
];
