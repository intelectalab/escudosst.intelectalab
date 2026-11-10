<?php

namespace App\Modules\CON;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Core\Tenancy\CompanyContext;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Marca blanca de la consultora (M-CON, RN-CON-04): la consultora pone su nombre, logo y colores, y sus empresas
 * cliente ven EscudoSST con esa marca (menú, inicio, certificados y fotochecks). Puede mantener «Con tecnología EscudoSST».
 *  GET /con/white-label        la marca de mi consultora (administrador de la cuenta)
 *  PUT /con/white-label        guardar {brand_name, primary_color, secondary_color, logo?, pdf_footer_text, show_powered_by, active}
 *  GET /brand                  la marca que ve la empresa activa: la de su consultora si está activa (o null)
 */
class WhiteLabelController extends Controller
{
    public function show(): JsonResponse
    {
        $this->can('con.white_label_settings.read');
        $account = $this->account();
        $w = $this->db()->selectOne('select * from white_label_settings where account_id = ? and deleted_at is null', [$account]);

        return response()->json(['data' => $w ? $this->present($w) : null, 'clients' => (int) $this->db()->selectOne('select count(*) as n from companies where managed_by_account_id = ? and deleted_at is null', [$account])->n]);
    }

    public function save(Request $request, AccountsService $accounts): JsonResponse
    {
        $this->can('con.white_label_settings.update');
        $d = $request->validate([
            'brand_name' => ['required', 'string', 'min:2', 'max:60'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'string', 'max:450000'],
            'remove_logo' => ['boolean'],
            'pdf_footer_text' => ['nullable', 'string', 'max:200'],
            'show_powered_by' => ['boolean'],
            'active' => ['boolean'],
        ], ['brand_name.required' => 'Escribe el nombre de tu consultora.', '*.regex' => 'El color debe ser como #1F7FD1.']);
        $account = $this->account();
        $current = $this->db()->selectOne('select * from white_label_settings where account_id = ? and deleted_at is null', [$account]);
        $key = $current?->logo_file_key;
        if (! empty($d['logo'])) {
            [$bytes, $mime] = \App\Modules\FIR\SignatureImages::decode($d['logo']);   // PNG o JPG verificados por contenido
            $key = 'white-label/'.$account.'.'.($mime === 'image/png' ? 'png' : 'jpg');
            Storage::disk('local')->put($key, $bytes);
        } elseif (! empty($d['remove_logo'])) {
            $key = null;
        }
        $values = [$d['brand_name'], $key, $d['primary_color'] ?? null, $d['secondary_color'] ?? null, $d['pdf_footer_text'] ?? null,
            ($d['show_powered_by'] ?? true) ? 'true' : 'false', ($d['active'] ?? false) ? 'active' : 'draft', (string) auth()->id()];
        if ($current) {
            $this->db()->update('update white_label_settings set brand_name = ?, logo_file_key = ?, primary_color = ?, secondary_color = ?, pdf_footer_text = ?, show_powered_by = ?, status = ?, updated_by = ?, updated_at = now() where id = ?',
                [...$values, $current->id]);
        } else {
            $this->db()->insert('insert into white_label_settings (account_id, brand_name, logo_file_key, primary_color, secondary_color, pdf_footer_text, show_powered_by, status, updated_by, created_by)
                        values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$account, ...$values, (string) auth()->id()]);
        }
        $accounts->audit((string) auth()->id(), 'white_label_updated', 'white_label_settings', null, ['status' => $values[6]]);

        return $this->show();
    }

    public function brand(): JsonResponse
    {
        $company = app(CompanyContext::class)->companyId;
        $w = $this->db()->selectOne("select w.* from white_label_settings w join companies c on c.managed_by_account_id = w.account_id or c.account_id = w.account_id
                             where c.id = ? and w.status = 'active' and w.deleted_at is null order by (c.managed_by_account_id = w.account_id) desc limit 1", [$company]);

        return response()->json(['data' => $w ? $this->present($w) : null]);
    }

    private function present(object $w): array
    {
        $logo = null;
        if ($w->logo_file_key && Storage::disk('local')->exists($w->logo_file_key)) {
            $logo = 'data:'.(str_ends_with($w->logo_file_key, '.png') ? 'image/png' : 'image/jpeg').';base64,'.base64_encode(Storage::disk('local')->get($w->logo_file_key));
        }

        return ['brand_name' => $w->brand_name, 'logo' => $logo, 'primary_color' => $w->primary_color ? trim($w->primary_color) : null, 'secondary_color' => $w->secondary_color ? trim($w->secondary_color) : null,
            'pdf_footer_text' => $w->pdf_footer_text, 'show_powered_by' => (bool) $w->show_powered_by, 'active' => $w->status === 'active'];
    }

    /** La marca se lee y guarda con la conexión de la plataforma, siempre limitada a la cuenta de la empresa activa. */
    private function db()
    {
        return DB::connection('platform');
    }

    private function account(): string
    {
        $a = $this->db()->selectOne('select account_id from companies where id = ?', [app(CompanyContext::class)->companyId])?->account_id;

        return $a ?? throw ApiProblem::notFound('No se encontró la cuenta de la empresa.');
    }

    private function can(string $p): void
    {
        if (! in_array($p, Permissions::effective(), true)) {
            throw ApiProblem::forbidden('La marca blanca la configura el administrador de la cuenta de la consultora (plan Consultora).');
        }
    }
}
