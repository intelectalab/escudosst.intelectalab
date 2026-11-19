{{-- Informe de cumplimiento del Plan Anual SST cerrado (M-PLA RN-PLA-08): avance final del PASST y de los objetivos. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Informe de cumplimiento · Plan Anual SST {{ $plan->year }}</title>
<style>
  @page { margin: 34px 38px; }
  body { font-family: DejaVu Sans, sans-serif; color: #1b2a3a; font-size: 9px; line-height: 1.4; }
  .title { font-size: 15px; font-weight: bold; color: #0D3B6E; }
  .muted { color: #5b6b7c; }
  h2 { font-size: 11px; color: #0D3B6E; border-bottom: 1px solid #0D3B6E; padding-bottom: 2px; margin: 14px 0 6px; }
  table.t { width: 100%; border-collapse: collapse; }
  table.t th, table.t td { border: 1px solid #b9c4d0; padding: 3px 4px; vertical-align: top; text-align: left; }
  table.t th { background: #0D3B6E; color: #fff; font-size: 8px; }
  table.f td { padding: 2px 4px; vertical-align: top; }
  table.f td.k { width: 32%; color: #5b6b7c; }
</style>
</head>
<body>
  <div class="title">INFORME DE CUMPLIMIENTO · PLAN ANUAL SST {{ $plan->year }}</div>
  <div><b>{{ $company->legal_name }}</b> · RUC {{ trim($company->ruc) }}</div>
  <div class="muted">{{ $plan->title }} · versión {{ $plan->version_number }} · cerrado el {{ $date($plan->closed_at) }}</div>

  <h2>1. Programas del plan</h2>
  @if(count($programs))
    <table class="t">
      <tr><th style="width:30%">Programa</th><th>Título</th><th style="width:18%">Estado</th><th style="width:16%">Avance final</th></tr>
      @foreach($programs as $g)
        <tr><td>{{ $g['kind'] }}</td><td>{{ $g['title'] }}</td><td>{{ $g['status'] }}</td>
          <td>{{ $g['progress'] !== null ? rtrim(rtrim(number_format((float) $g['progress'], 1, ',', ''), '0'), ',').' %' : '—' }}</td></tr>
      @endforeach
    </table>
  @else <p class="muted">El plan no tenía programas vinculados.</p> @endif

  <h2>2. Objetivos</h2>
  @php($objs = array_values(array_filter($items, fn ($i) => $i->objective_id)))
  @if(count($objs))
    <table class="t">
      <tr><th style="width:10%">Código</th><th>Objetivo</th><th style="width:20%">Meta</th><th style="width:16%">Resultado</th></tr>
      @foreach($objs as $i)
        <tr><td>{{ $i->objective_code ?? $i->code }}</td><td>{{ $i->objective_title ?? $i->description }}</td>
          <td>{{ $i->target_value !== null ? trim($i->target_value.' '.($i->unit ?? '')) : ($i->goal ?: '—') }}</td>
          <td>{{ $objectiveStatus[$i->objective_status] ?? '—' }}</td></tr>
      @endforeach
    </table>
    @php($achieved = count(array_filter($objs, fn ($i) => $i->objective_status === 'achieved')))
    <p>Objetivos logrados: <b>{{ $achieved }} de {{ count($objs) }}</b>.</p>
  @else <p class="muted">El plan no tenía objetivos vinculados.</p> @endif

  <h2>3. Datos del cierre</h2>
  <table class="f">
    <tr><td class="k">Plan aprobado el</td><td>{{ $date($plan->approved_at) }}</td></tr>
    <tr><td class="k">Cerrado el</td><td>{{ $date($plan->closed_at) }}</td></tr>
  </table>
</body>
</html>
