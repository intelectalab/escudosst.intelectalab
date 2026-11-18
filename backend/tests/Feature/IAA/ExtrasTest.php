<?php

// Asistente (M-IAA): quita nombres, DNI, teléfonos y correos (RN-IAA-03), lee un requerimiento SUNAFIL en PDF y propone el
// expediente (SCR-IAA-03), y sugiere cursos por cargo que van a la bandeja del aula por aprobar (SCR-IAA-04, RN-IAA-09).

use App\Core\Auth\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $GLOBALS['sus_tokens'] = [];
    DB::connection('platform')->update("update feature_flags set mode = 'enabled' where company_id = ? and module_code in ('IAA', 'SUN', 'AUL')", [HUAYLAS_ID]);
    Permissions::flush();
    $this->h = authHeaders(tokenFor(CORPORATIVO_EMAIL), HUAYLAS_ID);
});

/** PDF de texto mínimo (con el flujo comprimido, como lo generan los programas). */
function iaaPdf(array $lines): string
{
    $ops = "BT /F1 11 Tf 50 780 Td 14 TL\n";
    foreach ($lines as $l) {
        $ops .= '('.strtr($l, ['(' => '[', ')' => ']']).") Tj T*\n";
    }
    $ops .= 'ET';
    $z = gzcompress($ops);

    return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
        ."3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R >> endobj\n4 0 obj << /Length ".strlen($z)." /Filter /FlateDecode >>\nstream\n{$z}\nendstream\nendobj\ntrailer << /Root 1 0 R >>\n%%EOF";
}

it('reemplaza DNI, teléfonos, correos y nombres de trabajadores por marcadores', function () {
    $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(60000000, 69999999), 'first_name' => 'Rosaura', 'paternal_last_name' => 'Quispillo'], $this->h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $w = DB::selectOne("select full_name from workers where paternal_last_name = 'Quispillo' and deleted_at is null limit 1");
    [$t, $found] = \App\Modules\IAA\Redactor::redact("Caída de {$w->full_name}, DNI 45678912, cel. 987 654 321, correo ana.p@empresa.pe");
    expect($t)->not->toContain('45678912')->not->toContain('987 654 321')->not->toContain('ana.p@empresa.pe')->not->toContain($w->full_name)
        ->toContain('[DNI]')->toContain('[TELÉFONO]')->toContain('[CORREO]')->toContain('[PERSONA]');
    expect($found)->toContain('PERSONA');

    $r = $this->postJson('/api/v1/iaa/suggest', ['feature' => 'controls', 'text' => 'Trabajo en altura de Juan, DNI 41234567'], $this->h)->assertOk();
    expect($r->json('data.redacted'))->toContain('DNI');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne("select input_summary from ai_requests where company_id = ? order by created_at desc limit 1", [HUAYLAS_ID])->input_summary)->not->toContain('41234567');
});

it('lee un requerimiento SUNAFIL en PDF y, al aceptarlo, crea el expediente con esos datos', function () {
    $pdf = iaaPdf(['SUPERINTENDENCIA NACIONAL DE FISCALIZACION LABORAL', 'Orden de Inspeccion N 4521-2026-SUNAFIL/IRE-LIM', 'Inspector: Rosa Medina Calle',
        'Comparecencia: 25/11/2026 a las 10:30 horas', 'Periodo a revisar: del 01/01/2026 al 31/10/2026', 'Documentos a exhibir:',
        '1. Matriz IPERC vigente de la obra', '2. Registro de entrega de EPP', '3. Programa anual de capacitaciones']);
    $file = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('requerimiento.pdf', $pdf), 'module' => 'SUN'], $this->h + ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    $r = $this->postJson('/api/v1/iaa/document/read', ['file_id' => $file], $this->h);
    expect($r->status())->toBe(201, $r->getContent());
    $p = $r->json('data.suggestion.payload');
    expect($p['inspection_order_number'])->toBe('4521-2026-SUNAFIL/IRE-LIM')->and($p['appearance_at'])->toBe('2026-11-25T10:30:00')
        ->and($p['inspector_name'])->toBe('Rosa Medina Calle')->and($p['period_from'])->toBe('2026-01-01')->and($p['requested_documents'])->toHaveCount(3);

    $a = $this->postJson('/api/v1/iaa/suggestions/'.$r->json('data.suggestion.id').'/accept', ['dossier' => ['requested_documents' => ['Matriz IPERC vigente de la obra', 'Registro de entrega de EPP', 'Programa anual de capacitaciones', 'Actas del comité']]], $this->h)->assertOk()->json('data');
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $d = DB::selectOne('select inspection_order_number, inspector_name, requirement_file_id, period_to from inspection_dossiers where id = ?', [$a['dossier_id']]);
    expect(json_decode(DB::selectOne('select scope from inspection_dossiers where id = ?', [$a['dossier_id']])->scope, true)['requested_documents'])->toHaveCount(4)->toContain('Actas del comité');
    expect($d->inspection_order_number)->toBe('4521-2026-SUNAFIL/IRE-LIM')->and($d->requirement_file_id)->toBe($file)->and($d->period_to)->toBe('2026-10-31');

    $img = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('escaneado.pdf', "%PDF-1.4\n%%EOF"), 'module' => 'SUN'], $this->h + ['Accept' => 'application/json'])->json('data.id');
    $this->postJson('/api/v1/iaa/document/read', ['file_id' => $img], $this->h)->assertStatus(422);
});

it('sugiere cursos por cargo y al aceptarlos quedan en la bandeja del aula sin enviarse', function () {
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $area = DB::selectOne('select id from areas where deleted_at is null and company_id = ? limit 1', [HUAYLAS_ID])->id;
    $pos = $this->postJson('/api/v1/records/TEN/positions', ['code' => 'P-'.substr(md5(uniqid()), 0, 5), 'name' => 'Soldador de estructuras', 'area_id' => $area], $this->h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/records/TRB/workers', ['document_number' => (string) random_int(60000000, 69999999), 'first_name' => 'Curso', 'paternal_last_name' => 'Sugerido',
        'current_position_id' => $pos], $this->h)->assertCreated();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $course = DB::selectOne("insert into courses (company_id, code, title, target_audience, status, published_at) values (?, ?, ?, ?, 'published', now()) returning id",
        [HUAYLAS_ID, 'IAA-'.substr(md5(uniqid()), 0, 5), 'Curso para el cargo', DB::selectOne('select name from positions where id = ?', [$pos])->name])->id;
    $s = collect($this->postJson('/api/v1/iaa/courses/suggest', ['position_id' => $pos], $this->h)->assertCreated()->json('data.suggestions'))
        ->firstWhere('payload.course_id', $course);
    expect($s)->not->toBeNull()->and($s['payload']['workers'])->toBeGreaterThan(0);
    expect(json_encode($s['payload']))->not->toContain('full_name');

    $a = $this->postJson("/api/v1/iaa/suggestions/{$s['id']}/accept", [], $this->h)->assertOk()->json('data');
    expect($a['recommendations'])->toBe($s['payload']['workers']);
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    $rows = DB::select("select status, source, sent_at from course_recommendations where course_id = ?", [$course]);
    expect(collect($rows)->pluck('status')->unique()->all())->toBe(['suggested'])->and(collect($rows)->pluck('sent_at')->filter()->all())->toBe([]);
});

it('la conexión de IA está apagada por defecto y solo acepta respuestas con el formato esperado', function () {
    expect(\App\Modules\IAA\AiProvider::enabled())->toBeFalse()
        ->and(\App\Modules\IAA\AiProvider::suggest('controls', 'trabajo en altura'))->toBeNull()
        ->and(\App\Modules\IAA\AiProvider::validate(['items' => [['title' => 'Arnés', 'detail' => 'Usar línea de vida']]]))->toHaveCount(1)
        ->and(\App\Modules\IAA\AiProvider::validate(['items' => []]))->toBeNull()
        ->and(\App\Modules\IAA\AiProvider::validate(['items' => [['title' => 5]]]))->toBeNull()
        ->and(\App\Modules\IAA\AiProvider::validate('texto libre'))->toBeNull();
    // Sin proveedor, la consulta sale de la biblioteca y así queda registrada.
    $this->postJson('/api/v1/iaa/suggest', ['feature' => 'controls', 'text' => 'Trabajo en altura sobre andamio'], $this->h)->assertOk();
    DB::select("select set_config('app.company_id', ?, false)", [HUAYLAS_ID]);
    expect(DB::selectOne('select provider from ai_requests where company_id = ? order by created_at desc limit 1', [HUAYLAS_ID])->provider)->toBe('escudosst_library');
});

it('lee con OCR gratuito un requerimiento escaneado (el PDF es solo una imagen)', function () {
    // Imagen con el texto dibujado, como sale de un escáner, dentro de un PDF sin texto.
    $lines = ['Orden de Inspeccion N 7788-2026', 'Comparecencia: 28/11/2026 a las 09:30 horas', '1. Registro de entrega de EPP'];
    $img = imagecreatetruecolor(1700, 600);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    foreach ($lines as $i => $l) {
        imagettftext($img, 44, 0, 80, 150 + $i * 140, imagecolorallocate($img, 0, 0, 0), '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', $l);
    }
    ob_start();
    imagejpeg($img, null, 95);
    $jpg = (string) ob_get_clean();
    $content = "q 612 0 0 216 0 576 cm /Im0 Do Q";
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
        ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >> endobj\n"
        ."4 0 obj << /Length ".strlen($content)." >>\nstream\n{$content}\nendstream\nendobj\n"
        ."5 0 obj << /Type /XObject /Subtype /Image /Width 1700 /Height 600 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpg)." >>\nstream\n{$jpg}\nendstream\nendobj\n"
        ."trailer << /Root 1 0 R >>\n%%EOF";

    [$text, $ocr] = \App\Modules\IAA\PdfText::read($pdf);
    expect($ocr)->toBeTrue()->and($text)->toContain('7788-2026');
    $f = \App\Modules\IAA\IaaExtrasController::sunafilFields($text);
    expect($f['inspection_order_number'])->toBe('7788-2026')->and($f['appearance_at'])->toBe('2026-11-28T09:30:00');
});
