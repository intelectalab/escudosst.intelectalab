<?php

namespace App\Modules\WEB\Services;

use App\Core\Http\ApiProblem;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Políticas y versiones del sitio (SCR-WEB-24, docs/06: draft → approved → published → superseded).
 *
 *  - Solo el borrador se edita. Para cambiar un texto publicado se crea una versión nueva (copia del vigente).
 *  - Al publicar se puede registrar el abogado que lo revisó; si no, se publica igual y solo el panel lo advierte.
 *  - No se publica un texto que aún tenga la marca «[Texto de prueba]».
 *  - Al publicar se guarda la huella SHA-256 del texto (prueba de qué versión aceptó cada persona) y la versión
 *    anterior pasa a «reemplazada». Si se marca «requiere re-aceptación», los usuarios deberán aceptarla otra vez.
 *  - Todo queda en la bitácora de la plataforma.
 */
class LegalDocumentsService
{
    public const TEST_MARK = '[Texto de prueba]';

    public function __construct(private readonly AccountsService $accounts) {}

    private function db(): ConnectionInterface
    {
        return DB::connection('platform');
    }

    public function list(): array
    {
        $versions = collect($this->db()->select(
            'select v.id, v.legal_document_id, v.version_number, v.version_label, v.status, v.content, v.summary_of_changes,
                    v.effective_from, v.requires_reacceptance, v.legal_reviewed_by, v.published_at, v.superseded_at, v.updated_at,
                    position(? in v.content) > 0 as has_test_text,
                    (select count(*) from legal_acceptances a where a.legal_document_version_id = v.id) as acceptances
               from legal_document_versions v where v.deleted_at is null order by v.version_number desc',
            [self::TEST_MARK]
        ))->groupBy('legal_document_id');

        return array_map(fn ($d) => (array) $d + ['versions' => $versions->get($d->id, collect())->map(fn ($v) => array_merge((array) $v, [
            'has_test_text' => (bool) $v->has_test_text,
            'acceptances' => (int) $v->acceptances,
        ]))->values()->all()], $this->db()->select(
            "select id, doc_type, title, slug, current_version_id, requires_acceptance_on_signup, applies_to
               from legal_documents where deleted_at is null and is_active
              order by array_position(array['terms','privacy','cookies','dpa','sla','complaints_info'], doc_type)"
        ));
    }

    public function saveDraft(string $versionId, string $content, ?string $summary, string $actor): void
    {
        $v = $this->version($versionId);
        if ($v->status !== 'draft') {
            throw $this->conflict('Solo se edita el borrador. Para cambiar un texto publicado, crea una versión nueva.');
        }
        $this->db()->update('update legal_document_versions set content = ?, summary_of_changes = ?, updated_by = ? where id = ?',
            [$content, $summary, $actor, $v->id]);
        $this->accounts->audit($actor, 'legal_draft_saved', 'legal_document_versions', $v->id, ['document' => $v->title, 'version' => $v->version_label]);
    }

    public function newVersion(string $documentId, string $actor): array
    {
        $doc = Str::isUuid($documentId) ? $this->db()->selectOne('select * from legal_documents where id = ? and deleted_at is null', [$documentId]) : null;
        if (! $doc) {
            throw ApiProblem::notFound('El documento no existe.');
        }
        if ($this->db()->selectOne("select 1 from legal_document_versions where legal_document_id = ? and status = 'draft' and deleted_at is null", [$doc->id])) {
            throw $this->conflict('Ya hay un borrador de este documento: edítalo.');
        }
        $last = $this->db()->selectOne('select * from legal_document_versions where legal_document_id = ? and deleted_at is null order by version_number desc limit 1', [$doc->id]);
        $number = ($last->version_number ?? 0) + 1;
        $id = $this->db()->selectOne(
            "insert into legal_document_versions (legal_document_id, version_number, version_label, content, status, created_by)
             values (?, ?, ?, ?, 'draft', ?) returning id",
            [$doc->id, $number, $number.'.0', $last->content ?? '', $actor]
        )->id;
        $this->accounts->audit($actor, 'legal_version_created', 'legal_document_versions', $id, ['document' => $doc->title, 'version' => $number.'.0']);

        return ['id' => $id];
    }

    /** @param array{legal_reviewed_by?: ?string, confirm_unreviewed?: bool, requires_reacceptance?: bool, effective_from?: ?string} $data */
    public function publish(string $versionId, array $data, string $actor): void
    {
        $v = $this->version($versionId);
        if ($v->status !== 'draft') {
            throw $this->conflict('Esta versión ya fue publicada.');
        }
        if (str_contains($v->content, self::TEST_MARK)) {
            throw $this->validation('content', 'El texto todavía tiene partes «[Texto de prueba]». Reemplázalas con el texto del abogado antes de publicar.');
        }
        if (trim($v->content) === '') {
            throw $this->validation('content', 'El documento está vacío.');
        }
        // El abogado que lo revisó es opcional: sin él se publica igual y el panel lo muestra como advertencia.
        $reviewer = trim((string) ($data['legal_reviewed_by'] ?? ''));

        $this->db()->transaction(function () use ($v, $data, $reviewer, $actor) {
            $this->db()->update(
                "update legal_document_versions set status = 'superseded', superseded_at = now(), updated_by = ?
                  where legal_document_id = ? and status = 'published' and deleted_at is null",
                [$actor, $v->legal_document_id]
            );
            $this->db()->update(
                "update legal_document_versions
                    set status = 'published', approved_by = ?, approved_at = now(), published_by = ?, published_at = now(),
                        effective_from = coalesce(?::timestamptz, now()), content_sha256 = sha256(convert_to(content, 'UTF8')),
                        requires_reacceptance = ?, legal_reviewed_by = ?, updated_by = ?
                  where id = ?",
                [$actor, $actor, $data['effective_from'] ?? null, (bool) ($data['requires_reacceptance'] ?? false), $reviewer ?: null, $actor, $v->id]
            );
            $this->db()->update('update legal_documents set current_version_id = ?, updated_by = ? where id = ?', [$v->id, $actor, $v->legal_document_id]);
            $this->accounts->audit($actor, 'legal_version_published', 'legal_document_versions', $v->id, [
                'document' => $v->title,
                'version' => $v->version_label,
                'reviewed_by' => $reviewer ?: 'Sin revisión legal (confirmado)',
                'requires_reacceptance' => (bool) ($data['requires_reacceptance'] ?? false),
            ]);
        });
    }

    /** Página pública: la versión publicada; si aún no hay, el borrador como vista previa rotulada «en preparación». */
    public function publicPage(string $slug): array
    {
        $doc = DB::connection('platform')->selectOne('select doc_type from legal_documents where slug = ? and is_active and deleted_at is null', [$slug]);
        if (! $doc) {
            throw ApiProblem::notFound('El documento no existe.');
        }
        $published = json_decode($this->db()->selectOne('select public_legal_document(?) as d', [$doc->doc_type])->d, true);
        $history = $this->db()->select(
            "select v.version_label, v.effective_from, v.status
               from legal_document_versions v join legal_documents d on d.id = v.legal_document_id
              where d.slug = ? and v.status in ('published', 'superseded') and v.deleted_at is null order by v.version_number desc",
            [$slug]
        );
        $others = $this->db()->select("select title, slug from legal_documents where is_active and deleted_at is null and slug <> ? and doc_type <> 'complaints_info' order by array_position(array['terms','privacy','cookies','dpa','sla'], doc_type)", [$slug]);

        unset($published['summary_of_changes']);   // la nota de la versión es interna (solo panel)
        if (($published['result'] ?? '') === 'ok') {
            return ['status' => 'published'] + $published + ['history' => $history, 'others' => $others];
        }
        $draft = $this->db()->selectOne(
            "select v.version_label, v.content from legal_document_versions v join legal_documents d on d.id = v.legal_document_id
              where d.slug = ? and v.status = 'draft' and v.deleted_at is null order by v.version_number desc limit 1",
            [$slug]
        );

        return [
            'status' => 'draft',
            'title' => $published['title'] ?? '',
            'slug' => $slug,
            'version_label' => $draft->version_label ?? null,
            'content' => $draft->content ?? '',
            'history' => $history,
            'others' => $others,
        ];
    }

    /**
     * Aviso por correo de una versión nueva que pide re-aceptación (M-WEB §10): `web.legal_notice_days` antes de que entre
     * en vigencia, a quienes aceptaron una versión anterior y todavía no aceptan esta. Una sola vez por versión (queda en
     * la bitácora). El modal al iniciar sesión sigue igual. Lo corre el programador una vez al día.
     */
    public function sendReacceptanceNotices(): int
    {
        $days = (int) ($this->db()->selectOne("select web_try_numeric(platform_legal_param('web.legal_notice_days')) as v")->v ?? 0);
        if ($days <= 0) {
            return 0;
        }
        $due = $this->db()->select(
            "select v.id, v.version_label, v.effective_from, v.summary_of_changes, d.id as document_id, d.title, d.slug, d.applies_to
               from legal_documents d join legal_document_versions v on v.id = d.current_version_id
              where v.status = 'published' and v.requires_reacceptance and v.deleted_at is null
                and d.requires_acceptance_on_signup and d.is_active and d.deleted_at is null and d.applies_to <> 'visitors'
                and v.effective_from > now() and v.effective_from <= now() + make_interval(days => ?)
                and not exists (select 1 from audit_logs a where a.action = 'legal_reacceptance_notice' and a.entity_id = v.id)",
            [$days]
        );
        $site = rtrim((string) config('escudo.frontend_url'), '/');
        $n = 0;
        foreach ($due as $v) {
            // Se marca antes de enviar: si un correo falla no se repite en bucle.
            $this->accounts->audit(null, 'legal_reacceptance_notice', 'legal_document_versions', $v->id, ['document' => $v->title, 'version' => $v->version_label]);
            $users = $this->db()->select(
                "select u.email, u.full_name from users u
                  where u.status = 'active' and u.deleted_at is null and (? = 'all' or not u.is_platform_staff)
                    and exists (select 1 from legal_acceptances a where a.user_id = u.id and a.legal_document_id = ?)
                    and not exists (select 1 from legal_acceptances a where a.user_id = u.id and a.legal_document_version_id = ?)",
                [$v->applies_to, $v->document_id, $v->id]
            );
            $when = \Illuminate\Support\Carbon::parse($v->effective_from)->timezone('America/Lima')->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
            foreach ($users as $u) {
                try {
                    \Illuminate\Support\Facades\Mail::to($u->email)->send(new \App\Modules\WEB\Mail\SiteRequestMail(
                        explode(' ', trim((string) $u->full_name))[0] ?: 'Hola', "Cambia el documento: {$v->title}",
                        "Publicamos una versión nueva de «{$v->title}». Al entrar al sistema te pediremos que la leas y la aceptes para seguir.",
                        array_values(array_filter([['Versión', $v->version_label], ['Vigente desde', $when], $v->summary_of_changes ? ['Qué cambia', $v->summary_of_changes] : null])),
                        null, 'Leer el documento', $site.'/legal/'.$v->slug
                    ));
                    $n++;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('No se pudo enviar el aviso de la nueva versión del documento', ['error' => $e->getMessage()]);
                }
            }
        }

        return $n;
    }

    private function version(string $id): object
    {
        $v = Str::isUuid($id) ? $this->db()->selectOne(
            'select v.*, d.title from legal_document_versions v join legal_documents d on d.id = v.legal_document_id where v.id = ? and v.deleted_at is null', [$id]
        ) : null;

        return $v ?? throw ApiProblem::notFound('La versión no existe.');
    }

    private function conflict(string $detail): ApiProblem
    {
        return new ApiProblem(409, 'conflict', 'No se puede realizar la acción', $detail);
    }

    private function validation(string $field, string $message): ApiProblem
    {
        return new ApiProblem(422, 'validation_failed', 'Revisa los datos ingresados', $message, ['errors' => [$field => [$message]]]);
    }
}
