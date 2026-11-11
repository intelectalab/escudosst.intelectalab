<?php

namespace App\Modules\USR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Ingreso con correo o DNI (8 dígitos) y contraseña (SCR-USR-01). */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** DNI de 8 dígitos (se ignoran espacios, puntos y guiones). */
    public function dni(): ?string
    {
        $raw = preg_replace('/[\s.\-]/', '', (string) $this->input('email'));

        return preg_match('/^\d{8}$/', (string) $raw) ? $raw : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => $this->dni() !== null ? ['required', 'string'] : ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Ingresa tu correo o DNI.',
            'email.email' => 'Ingresa un correo válido o un DNI de 8 dígitos.',
            'password.required' => 'Ingresa tu contraseña.',
        ];
    }
}
