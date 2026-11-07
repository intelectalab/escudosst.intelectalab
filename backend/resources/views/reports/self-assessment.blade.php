{{-- Informe PDF del autodiagnóstico Ley 29783 (RN-WEB-39). Lo arma dompdf: estilos simples, sin flex ni grid. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Autodiagnóstico Ley 29783 · {{ $company }}</title>
<style>
  @page { margin: 32px 40px; }
  body { font-family: DejaVu Sans, sans-serif; color: #1b2a3a; font-size: 11px; line-height: 1.45; }
  .head { background: #0D3B6E; color: #fff; padding: 22px 26px; border-radius: 10px; }
  .head .brand { font-size: 13px; font-weight: bold; letter-spacing: .5px; color: #9cc8f2; }
  .head h1 { margin: 6px 0 4px; font-size: 20px; }
  .head p { margin: 0; color: #d7e6f6; }
  .score { margin: 18px 0; width: 100%; border-collapse: separate; border-spacing: 0; }
  .score td { vertical-align: middle; }
  .big { font-size: 40px; font-weight: bold; color: #0D3B6E; }
  .level { display: inline-block; padding: 4px 12px; border-radius: 20px; color: #fff; font-weight: bold; font-size: 12px; }
  .low { background: #E2453C; } .medium { background: #C99500; } .high { background: #22A35A; }
  h2 { font-size: 13px; color: #0D3B6E; margin: 20px 0 8px; border-bottom: 1px solid #d6dde6; padding-bottom: 4px; }
  table.bars { width: 100%; border-collapse: collapse; }
  table.bars td { padding: 5px 0; }
  .bar { height: 10px; background: #EEF3F8; border-radius: 5px; }
  .fill { height: 10px; border-radius: 5px; }
  ul { margin: 4px 0 10px 16px; padding: 0; }
  li { margin: 3px 0; }
  .sec { font-weight: bold; color: #1F7FD1; margin-top: 8px; }
  .partial { color: #8a6a00; }
  .note { margin-top: 18px; padding: 10px 12px; background: #FFF8E1; border: 1px solid #F4D77B; border-radius: 6px; color: #5C4500; font-size: 10px; }
  .cta { margin-top: 16px; padding: 14px 16px; background: #EAF3FC; border-radius: 8px; }
  .muted { color: #5b6b7c; font-size: 10px; }
</style>
</head>
<body>
  <div class="head">
    <div class="brand">{{ $brand }}</div>
    <h1>Autodiagnóstico de la Ley 29783</h1>
    <p>{{ $company }} · {{ $date }} · Cuestionario v{{ $version }}</p>
  </div>

  <table class="score">
    <tr>
      <td style="width: 140px;"><span class="big">{{ round($r['score_total']) }}%</span></td>
      <td>
        <span class="level {{ $r['level'] }}">Nivel {{ mb_strtolower($r['level_label']) }}</span>
        <div style="margin-top: 6px;">{{ $message }}</div>
      </td>
    </tr>
  </table>

  <h2>Resultado por sección</h2>
  <table class="bars">
    @foreach ($r['score_by_section'] as $section => $pct)
      <tr>
        <td style="width: 38%;">{{ $section }}</td>
        <td style="width: 50%;"><div class="bar"><div class="fill" style="width: {{ max(2, (float) $pct) }}%; background: {{ $pct >= 70 ? '#22A35A' : ($pct >= 40 ? '#F4B90F' : '#E2453C') }};"></div></div></td>
        <td style="width: 12%; text-align: right; font-weight: bold;">{{ round($pct) }}%</td>
      </tr>
    @endforeach
  </table>

  @if (count($r['pending']))
    <h2>Lo que te falta trabajar</h2>
    @foreach ($r['pending'] as $section => $items)
      <div class="sec">{{ $section }}</div>
      <ul>
        @foreach ($items as $it)
          <li>{{ $it['question'] }} @if ($it['answer'] === 'partial')<span class="partial">(en parte)</span>@endif</li>
        @endforeach
      </ul>
    @endforeach
  @else
    <h2>¡Buen trabajo!</h2>
    <p>Respondiste «Sí» en todas las preguntas. Mantén tus registros al día para demostrarlo en una inspección.</p>
  @endif

  <div class="cta">
    <b>EscudoSST te ayuda a cerrar estas brechas</b><br>
    IPERC, capacitaciones, registros obligatorios y el expediente para SUNAFIL en un solo lugar. Agenda una demo en {{ $site }}/demo
  </div>

  <div class="note">
    Resultado referencial{{ $toValidate ? '; cuestionario en validación por especialista SST' : '' }}. No es una auditoría ni asesoría legal.
    Calculado con tus respuestas del {{ $date }}.
  </div>
  <p class="muted" style="margin-top: 10px;">{{ $legal }}</p>
</body>
</html>
