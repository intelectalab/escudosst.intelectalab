<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuario de la plataforma (tabla users, módulo USR).
 * Único por correo en toda la plataforma (RN-USR-01); el acceso a cada
 * empresa se da por memberships.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasUuids;

    /** La tabla no tiene remember_token: la sesión es por token de Sanctum. */
    protected $rememberTokenName = '';

    protected $fillable = [
        'email',
        'full_name',
        'phone_e164',
        'locale',
        'timezone',
    ];

    protected $hidden = [
        'password_hash',
        'mfa_secret',
        'mfa_recovery_codes',
        'reauth_secret_hash',
        'document_number',
        'document_number_hash',
    ];

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'mfa_enabled' => 'boolean',
            'is_platform_staff' => 'boolean',
        ];
    }
}
