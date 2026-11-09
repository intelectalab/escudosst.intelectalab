{{-- Matriz IPERC en PDF (IpercExport). Formato referencial R.M. 050-2013-TR, horizontal. La arma dompdf: tablas simples. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>IPERC {{ $m->code }} v{{ $v->version_number }}</title>
<style>
  @page { margin: 22px 24px; }
  body { font-family: DejaVu Sans, sans-serif; color: #1b2a3a; font-size: 7.6px; line-height: 1.3; }
  .head { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .head td { vertical-align: top; padding: 2px 4px; }
  .title { font-size: 13px; font-weight: bold; color: #0D3B6E; }
  .muted { color: #5b6b7c; }
  .box { border: 1px solid #0D3B6E; padding: 4px 8px; text-align: right; }
  table.m { width: 100%; border-collapse: collapse; }
  table.m th, table.m td { border: 1px solid #b9c4d0; padding: 2px 3px; vertical-align: top; }
  table.m th { background: #0D3B6E; color: #fff; font-size: 7px; text-align: center; }
  .c { text-align: center; }
  .lv { font-weight: bold; text-align: center; }
  .green { background: #d8f0e0; } .amber { background: #fff1c2; } .red { background: #f8d0cc; }
  ul { margin: 0; padding-left: 9px; }
  .sign { width: 100%; margin-top: 18px; border-collapse: collapse; }
  .sign td { width: 33%; text-align: center; padding-top: 26px; }
  .sign span { display: block; border-top: 1px solid #1b2a3a; margin: 0 20px; padding-top: 3px; }
  .crit { margin-top: 8px; width: 100%; border-collapse: collapse; }
  .crit td { padding: 2px 4px; border: 1px solid #d6dde6; }
</style>
</head>
<body>
  <table class="head">
    <tr>
      <td>
        <div class="title">MATRIZ IPERC · {{ $m->title }}</div>
        <div><b>{{ $company->legal_name }}</b> · RUC {{ trim($company->ruc) }}</div>
        <div class="muted">
          @if($m->site_name)Sede: {{ $m->site_name }} · @endif
          @if($m->area_name)Área: {{ $m->area_name }} · @endif
          @if($m->process)Proceso: {{ $m->process }} · @endif
          Criterios: {{ $criteria['name'] }}
        </div>
      </td>
      <td style="width: 190px;">
        <div class="box">
          Código: <b>{{ $m->code }}</b><br>
          Versión: <b>{{ $v->version_number }}</b> ({{ $statusLabel }})<br>
          @if($v->approved_at)Aprobada: {{ $approved }}<br>@endif
          @if($m->next_review_on && $v->status === 'current')Próxima revisión: {{ \Illuminate\Support\Carbon::parse($m->next_review_on)->format('d/m/Y') }}@endif
        </div>
      </td>
    </tr>
  </table>

  <table class="m">
    <thead>
      <tr>
        <th rowspan="2">N.°</th><th rowspan="2">Proceso / actividad</th><th rowspan="2">Tarea</th><th rowspan="2">R / NR</th><th rowspan="2">Puesto</th>
        <th rowspan="2">Peligro</th><th rowspan="2">Riesgo</th><th rowspan="2">Consecuencia</th>
        <th colspan="{{ $isIndex ? count($criteria['factors']) + 1 : 1 }}">Probabilidad</th>
        <th rowspan="2">Sev.</th><th rowspan="2">P × S</th><th rowspan="2">Nivel</th><th rowspan="2">Sig.</th>
        <th rowspan="2">Controles</th><th rowspan="2">Residual</th>
      </tr>
      <tr>
        @if($isIndex)
          @foreach($criteria['factors'] as $f)<th>{{ $f['label'] }}</th>@endforeach
        @endif
        <th>Índice</th>
      </tr>
    </thead>
    <tbody>
      @foreach($rows as $r)
        <tr>
          <td class="c">{{ $r['row_number'] }}</td>
          <td>{{ $r['process'] }}@if($r['process']) / @endif{{ $r['activity'] }}</td>
          <td>{{ $r['task'] }}</td>
          <td class="c">{{ $r['is_routine'] ? 'R' : 'NR' }}</td>
          <td>{{ $r['position_name'] }}</td>
          <td>{{ $r['hazard'] }}</td>
          <td>{{ $r['risk'] }}</td>
          <td>{{ $r['consequence'] }}</td>
          @if($isIndex)
            @foreach($criteria['factors'] as $f)<td class="c">{{ $r['probability_detail'][$f['code']] ?? '' }}</td>@endforeach
          @endif
          <td class="c">{{ $r['probability_value'] }}</td>
          <td class="c">{{ $r['severity_value'] }}</td>
          <td class="c">{{ $r['risk_score'] }}</td>
          <td class="lv {{ $color($r['risk_level']) }}">{{ $label($r['risk_level']) }}</td>
          <td class="c">{{ $r['is_significant'] ? 'Sí' : 'No' }}</td>
          <td>
            @if($r['controls'])
              <ul>@foreach($r['controls'] as $c)<li><b>{{ $hierarchy[$c['hierarchy']] ?? $c['hierarchy'] }}:</b> {{ $c['description'] }}@if($c['responsible_name']) ({{ $c['responsible_name'] }}@if($c['due_on']), {{ \Illuminate\Support\Carbon::parse($c['due_on'])->format('d/m/Y') }}@endif)@endif</li>@endforeach</ul>
            @endif
            @if($r['notes'])<div class="muted">{{ $r['notes'] }}</div>@endif
          </td>
          <td class="lv {{ $color($r['residual_level']) }}">@if($r['residual_level']){{ $r['residual_probability'] }} × {{ $r['residual_severity'] }} = {{ $r['residual_score'] }}<br>{{ $label($r['residual_level']) }}@endif</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="crit">
    <tr>
      <td><b>Niveles:</b> @foreach($criteria['bands'] as $b){{ $b['label'] }} ({{ $b['min'] }}–{{ $b['max'] }})@if(! $loop->last) · @endif @endforeach</td>
      <td><b>Severidad:</b> @foreach($criteria['severity_scale'] as $s){{ $s['value'] }} = {{ $s['label'] }}@if(! $loop->last) · @endif @endforeach</td>
    </tr>
    @if($participants)
      <tr><td colspan="2"><b>Participaron en la elaboración:</b> @foreach($participants as $p){{ $p['name'] }}@if(! empty($p['role'])) ({{ $p['role'] }})@endif @if(! $loop->last), @endif @endforeach</td></tr>
    @endif
  </table>

  <table class="sign">
    <tr>
      <td><span>Elaboró: {{ $v->prepared_by_name ?? '' }}</span></td>
      <td><span>Revisó</span></td>
      <td><span>Aprobó: {{ $v->approved_by_name ?? '' }}</span></td>
    </tr>
  </table>
</body>
</html>
