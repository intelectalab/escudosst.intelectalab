<?php

namespace App\Modules\IAA;

use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\SUS\Services\AccountsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Panel › Asistente IA: el equipo entrena al asistente de las empresas.
 *  GET    /internal/assistant                 temas (por uso) y preguntas sin respuesta
 *  POST   /internal/assistant/topics          crear tema {feature, title, keywords[], items[{group,title,detail}]}
 *  PUT    /internal/assistant/topics/{id}     editar tema (también activar o desactivar)
 *  DELETE /internal/assistant/topics/{id}     borrar tema
 *  POST   /internal/assistant/misses/{id}     marcar una pregunta como enseñada o ignorada {status}
 *  POST   /internal/assistant/try             probar qué respondería {feature, text}
 */
class KnowledgeController extends Controller
{
    private function db()
    {
        return DB::connection('platform');
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'topics' => array_map(fn ($t) => ['id' => $t->id, 'feature' => $t->feature, 'title' => $t->title, 'keywords' => array_values(array_filter(array_map(fn ($k) => trim($k, ' "'), explode(',', trim((string) $t->keywords, '{}'))))),
                'items' => json_decode($t->items, true) ?: [], 'status' => $t->status, 'uses' => (int) $t->uses, 'updated_at' => $t->updated_at],
                $this->db()->select('select * from assistant_knowledge order by feature, uses desc, title')),
            'misses' => $this->db()->select("select id, feature, question, times, last_at from assistant_misses where status = 'open' order by times desc, last_at desc limit 100"),
        ]]);
    }

    public function store(Request $request, AccountsService $accounts): JsonResponse
    {
        $d = $this->validated($request);
        $id = $this->db()->selectOne('insert into assistant_knowledge (feature, title, keywords, items, created_by, updated_by) values (?, ?, ?, ?::jsonb, ?, ?) returning id',
            [$d['feature'], $d['title'], $this->pgArray($d['keywords']), json_encode($d['items'], JSON_UNESCAPED_UNICODE), $request->user()->id, $request->user()->id])->id;
        if (! empty($d['miss_id'])) {
            $this->db()->update("update assistant_misses set status = 'taught' where id = ?", [$d['miss_id']]);
        }
        $accounts->audit($request->user()->id, 'assistant_topic_created', 'assistant_knowledge', $id, ['title' => $d['title']]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request, string $id, AccountsService $accounts): JsonResponse
    {
        $this->find($id);
        $d = $this->validated($request);
        $this->db()->update('update assistant_knowledge set feature = ?, title = ?, keywords = ?, items = ?::jsonb, status = ?, updated_by = ?, updated_at = now() where id = ?',
            [$d['feature'], $d['title'], $this->pgArray($d['keywords']), json_encode($d['items'], JSON_UNESCAPED_UNICODE), $d['status'] ?? 'active', $request->user()->id, $id]);
        $accounts->audit($request->user()->id, 'assistant_topic_updated', 'assistant_knowledge', $id, ['title' => $d['title']]);

        return response()->json(['data' => ['id' => $id]]);
    }

    public function destroy(Request $request, string $id, AccountsService $accounts): Response
    {
        $this->find($id);
        $this->db()->delete('delete from assistant_knowledge where id = ?', [$id]);
        $accounts->audit($request->user()->id, 'assistant_topic_deleted', 'assistant_knowledge', $id, []);

        return response()->noContent();
    }

    public function miss(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'in:taught,ignored,open']]);
        if (! Str::isUuid($id) || ! $this->db()->update('update assistant_misses set status = ? where id = ?', [$d['status'], $id])) {
            throw ApiProblem::notFound('La pregunta no existe.');
        }

        return response()->json(['data' => ['status' => $d['status']]]);
    }

    public function try(Request $request): JsonResponse
    {
        $d = $request->validate(['feature' => ['required', 'in:controls,causes,talk'], 'text' => ['required', 'string', 'min:2', 'max:500']]);
        // Prueba sin anotar la pregunta como «sin respuesta».
        $t = ' '.mb_strtolower($d['text']).' ';
        $best = null;
        $bestScore = 0;
        foreach ($this->db()->select("select title, keywords, items from assistant_knowledge where feature = ? and status = 'active'", [$d['feature']]) as $k) {
            $score = 0;
            foreach (array_filter(explode(',', trim((string) $k->keywords, '{}'))) as $kw) {
                $kw = mb_strtolower(trim($kw, ' "'));
                if ($kw !== '' && str_contains($t, $kw)) {
                    $score += mb_strlen($kw) >= 6 ? 2 : 1;
                }
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$k, $score];
            }
        }

        return response()->json(['data' => $best ? ['topic' => $best->title, 'items' => json_decode($best->items, true)] : ['topic' => null, 'items' => []]]);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'feature' => ['required', 'in:controls,causes,talk'],
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'keywords' => ['required', 'array', 'min:1', 'max:30'],
            'keywords.*' => ['required', 'string', 'min:2', 'max:40'],
            'items' => ['required', 'array', 'min:1', 'max:15'],
            'items.*.title' => ['required', 'string', 'max:200'],
            'items.*.group' => ['nullable', 'string', 'max:60'],
            'items.*.detail' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:active,disabled'],
            'miss_id' => ['nullable', 'uuid'],
        ], ['keywords.required' => 'Agrega al menos una palabra clave (cómo lo escribiría una persona).', 'items.required' => 'Agrega al menos una sugerencia.']);
        $d['keywords'] = array_values(array_unique(array_map(fn ($k) => mb_strtolower(trim($k)), $d['keywords'])));
        $d['items'] = array_map(fn ($i) => ['group' => trim((string) ($i['group'] ?? '')), 'title' => trim($i['title']), 'detail' => trim((string) ($i['detail'] ?? ''))], $d['items']);

        return $d;
    }

    private function pgArray(array $v): string
    {
        return '{'.implode(',', array_map(fn ($x) => '"'.str_replace(['"', '\\'], '', $x).'"', $v)).'}';
    }

    private function find(string $id): object
    {
        $t = Str::isUuid($id) ? $this->db()->selectOne('select id from assistant_knowledge where id = ?', [$id]) : null;

        return $t ?? throw ApiProblem::notFound('El tema no existe.');
    }
}
