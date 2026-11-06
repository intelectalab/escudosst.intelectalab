<?php

namespace App\Modules\WEB\Http\Controllers;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Contenido de apoyo del sitio (SCR-WEB-11 y 23): preguntas frecuentes, testimonios y otros productos.
 *  Panel:   GET/POST /internal/site/{faqs|testimonials|products}, PATCH/DELETE .../{id}
 *  Público: GET /public/faqs, GET /public/testimonials (solo lo publicado/aprobado, vía funciones de la base)
 *
 * Reglas: un testimonio solo se aprueba con consentimiento del autor o marcado como «ejemplo» (RN-WEB-12); quitar el
 * consentimiento lo oculta al momento. Los enlaces de otros productos deben ser https (RN-WEB-15). Borrar = baja lógica.
 */
class SiteContentController extends Controller
{
    private const TABLE = ['faqs' => 'site_faqs', 'testimonials' => 'site_testimonials', 'products' => 'site_products'];

    private const ORDER = [
        'faqs' => 'topic, sort_order, created_at',
        'testimonials' => "(status = 'pending') desc, sort_order, created_at",
        'products' => 'sort_order, name',
    ];

    private const MESSAGES = [
        'required' => 'Completa este campo.',
        'max' => 'Es muy largo.',
        'in' => 'Elige una opción de la lista.',
        'url' => 'El enlace no es válido.',
        'starts_with' => 'El enlace debe empezar con https://.',
        'between' => 'Debe estar entre :min y :max.',
    ];

    // ---------------------------------------------------------------- Público

    public function publicFaqs(Request $request): JsonResponse
    {
        $topic = $request->query('tema');
        $r = json_decode(DB::connection('platform')->selectOne('select public_site_faqs(?) as r', [is_string($topic) ? $topic : null])->r, true);

        return response()->json(['data' => $r['faqs'] ?? []])->header('Cache-Control', 'public, max-age=60');
    }

    public function publicTestimonials(): JsonResponse
    {
        $r = json_decode(DB::connection('platform')->selectOne('select public_site_testimonials() as r')->r, true);

        return response()->json(['data' => $r['testimonials'] ?? []])->header('Cache-Control', 'public, max-age=60');
    }

    // ---------------------------------------------------------------- Panel

    public function index(string $kind): JsonResponse
    {
        $rows = DB::connection('platform')->select('select * from '.self::TABLE[$kind].' where deleted_at is null order by '.self::ORDER[$kind]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request, string $kind, AccountsService $accounts): JsonResponse
    {
        $data = $this->validated($request, $kind);
        $actor = $request->user()->id;
        $row = $this->prepare($kind, $data, null, $actor) + ['created_by' => $actor];
        $id = DB::connection('platform')->table(self::TABLE[$kind])->insertGetId($row);
        $accounts->audit($actor, "site_{$kind}_created", self::TABLE[$kind], $id, ['fields' => array_keys($row)]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $kind, string $id, AccountsService $accounts): Response
    {
        $current = $this->find($kind, $id);
        $data = $this->validated($request, $kind);
        $actor = $request->user()->id;
        $row = $this->prepare($kind, $data, $current, $actor) + ['updated_by' => $actor];
        DB::connection('platform')->table(self::TABLE[$kind])->where('id', $id)->update($row);
        $accounts->audit($actor, "site_{$kind}_updated", self::TABLE[$kind], $id, ['status' => $row['status'] ?? null]);

        return response()->noContent();
    }

    public function destroy(Request $request, string $kind, string $id, AccountsService $accounts): Response
    {
        $this->find($kind, $id);
        DB::connection('platform')->table(self::TABLE[$kind])->where('id', $id)->update(['deleted_at' => now(), 'updated_by' => $request->user()->id]);
        $accounts->audit($request->user()->id, "site_{$kind}_deleted", self::TABLE[$kind], $id, []);

        return response()->noContent();
    }

    private function find(string $kind, string $id): object
    {
        return DB::connection('platform')->table(self::TABLE[$kind])->where('id', $id)->whereNull('deleted_at')->first()
            ?? throw ApiProblem::notFound('No existe.');
    }

    private function validated(Request $request, string $kind): array
    {
        return match ($kind) {
            'faqs' => $request->validate([
                'topic' => ['required', 'in:general,plans,security,data_protection,product,billing,support'],
                'question' => ['required', 'string', 'min:5', 'max:200'],
                'answer' => ['required', 'string', 'min:5', 'max:2000'],
                'status' => ['required', 'in:draft,published,archived'],
                'sort_order' => ['integer', 'between:0,999'],
            ], self::MESSAGES),
            'testimonials' => $request->validate([
                'author_name' => ['required', 'string', 'min:2', 'max:80'],
                'author_role' => ['nullable', 'string', 'max:80'],
                'company_name' => ['nullable', 'string', 'max:120'],
                'quote' => ['required', 'string', 'min:10', 'max:600'],
                'rating' => ['nullable', 'integer', 'between:1,5'],
                'publication_consent' => ['boolean'],
                'consent_evidence' => ['nullable', 'required_if:publication_consent,true', 'string', 'max:300'],
                'is_example' => ['boolean'],
                'status' => ['required', 'in:pending,approved,hidden'],
                'sort_order' => ['integer', 'between:0,999'],
            ], self::MESSAGES + ['consent_evidence.required_if' => 'Indica cómo se obtuvo el consentimiento (correo, formulario, contrato).']),
            'products' => $request->validate([
                'name' => ['required', 'string', 'min:2', 'max:60'],
                'description' => ['nullable', 'string', 'max:200'],
                'url' => ['nullable', 'url', 'starts_with:https://', 'max:250'],
                'is_visible' => ['boolean'],
                'sort_order' => ['integer', 'between:0,999'],
            ], self::MESSAGES),
        };
    }

    /** Arma la fila a guardar con las reglas de cada tipo. */
    private function prepare(string $kind, array $d, ?object $current, string $actor): array
    {
        $clean = fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        $row = array_map($clean, $d);

        if ($kind === 'testimonials') {
            $row['publication_consent'] = (bool) ($d['publication_consent'] ?? false);
            $row['is_example'] = (bool) ($d['is_example'] ?? false);
            if ($row['status'] === 'approved') {
                if (! $row['publication_consent'] && ! $row['is_example']) {
                    $msg = 'Para publicarlo necesitas el consentimiento del autor (o marcarlo como «ejemplo»).';
                    throw new ApiProblem(422, 'validation_failed', 'Revisa los datos', $msg, ['errors' => ['status' => [$msg]]]);
                }
                $row['approved_at'] = $current?->status === 'approved' ? $current->approved_at : now();
                $row['approved_by'] = $current?->status === 'approved' ? $current->approved_by : $actor;
            } else {
                $row['approved_at'] = null;
                $row['approved_by'] = null;
            }
        }
        if ($kind === 'products') {
            $row['is_visible'] = (bool) ($d['is_visible'] ?? true);
            $row['description_editable'] = false;   // ya lo revisó el dueño
        }

        return $row;
    }
}
