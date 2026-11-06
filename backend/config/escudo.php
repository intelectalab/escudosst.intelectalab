<?php

// Parámetros propios de EscudoSST. Los valores marcados «a confirmar» deben
// pasar a la tabla de parámetros cuando seguridad los defina (RN-USR-06).

return [
    'auth' => [
        // Intentos fallidos seguidos antes del bloqueo temporal (a confirmar).
        'max_failed_logins' => (int) env('AUTH_MAX_FAILED_LOGINS', 5),
        // Minutos de bloqueo (a confirmar).
        'lock_minutes' => (int) env('AUTH_LOCK_MINUTES', 15),
        // Vigencia del token de sesión en minutos (a confirmar).
        'token_ttl_minutes' => (int) env('AUTH_TOKEN_TTL_MINUTES', 720),
        // RN-USR-05 (docs/modulos/M-USR.md): roles con MFA obligatorio. Se suman a roles.requires_mfa
        // de la base, que hoy no incluye a sst_manager (docs/08-api.md lo omite). Decisión: más estricto.
        'mfa_required_roles' => [
            'platform_owner', 'platform_support', 'account_admin', 'consultant',
            'occupational_doctor', 'health_staff', 'sst_manager',
        ],
    ],

    'subscriptions' => [
        // Duración de la prueba gratuita en días (docs/03 §9: «parámetro, por definir»).
        'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 14),
        // Vigencia del enlace de invitación del administrador de cuenta, en horas (a confirmar).
        'invitation_ttl_hours' => (int) env('INVITATION_TTL_HOURS', 72),
        // Días de gracia con «Pago pendiente» tras vencer el periodo; luego solo lectura (docs/03 §8: parámetro).
        'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 3),
        // Días antes del vencimiento en que se avisa (0 = el mismo día). Pedido del equipo: 5, 3 y 0.
        'reminder_days' => [5, 3, 0],
        // Hora del proceso diario (America/Lima).
        'daily_at' => env('SUBSCRIPTION_DAILY_AT', '07:00'),
    ],

    // Dirección del frontend para armar enlaces en los correos.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Disco donde se guardan el logo y el favicon del sitio (local en desarrollo; B2 en producción).
    'site_assets_disk' => env('SITE_ASSETS_DISK', 'local'),
];
