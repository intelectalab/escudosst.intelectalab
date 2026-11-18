<?php

namespace App\Modules\Consultora;

use Illuminate\Support\Facades\DB;

/**
 * M-CON marca blanca en los correos: si la empresa la atiende una consultora con marca blanca activa, los avisos salen con
 * su nombre, su color y su pie de página (y «con EscudoSST» solo si la consultora lo deja visible).
 * Antes de enviar se llama MailBrand::use($companyId); las vistas mail.* reciben $brand (AppServiceProvider).
 */
final class MailBrand
{
    private static ?array $current = null;

    public static function use(?string $companyId): void
    {
        self::$current = $companyId ? self::for($companyId) : null;
    }

    public static function current(): array
    {
        return self::$current ?? self::default();
    }

    public static function for(string $companyId): array
    {
        try {
            $w = DB::connection('platform')->selectOne("select w.brand_name, w.primary_color, w.pdf_footer_text, w.show_powered_by from white_label_settings w
                    join companies c on c.managed_by_account_id = w.account_id or c.account_id = w.account_id
                   where c.id = ? and w.status = 'active' and w.deleted_at is null order by (c.managed_by_account_id = w.account_id) desc limit 1", [$companyId]);
        } catch (\Throwable) {
            $w = null;
        }
        if (! $w || ! $w->brand_name) {
            return self::default();
        }
        $color = trim((string) $w->primary_color);

        return ['name' => $w->brand_name, 'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#0d3b6e',
            'footer' => $w->pdf_footer_text ?: 'Correo automático de '.$w->brand_name.'.', 'powered' => (bool) $w->show_powered_by];
    }

    private static function default(): array
    {
        return ['name' => 'EscudoSST', 'color' => '#0d3b6e', 'footer' => 'Correo automático de EscudoSST.', 'powered' => false];
    }
}
