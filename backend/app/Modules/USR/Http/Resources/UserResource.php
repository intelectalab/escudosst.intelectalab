<?php

namespace App\Modules\USR\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'full_name' => $this->full_name,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'mfa_enabled' => $this->mfa_enabled,
            'is_platform_staff' => $this->is_platform_staff,
            'platform_role' => $this->platform_role,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
