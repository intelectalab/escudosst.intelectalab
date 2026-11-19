{{-- Plan Anual SST en PDF (AnnualPlanDocs). Estructura referencial R.M. 050-2013-TR; M-PLA criterio 4: política, objetivos, programas vinculados y acta. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Plan Anual SST {{ $plan->year }}</title>
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
  .draft { border: 1px solid #b42318; color: #b42318; padding: 4px 8px; margin: 8px 0; font-weight: bold; }
  .sign { width: 100%; margin-top: 26px; border-collapse: collapse; }
  .sign td { width: 50%; text-align: center; padding-top: 30px; }
  .sign span { display: block; border-top: 1px solid #1b2a3a; margin: 0 30px; padding-top: 3px; }
</style>
</head>
<body>
  <div class="title">PLAN ANUAL DE SEGURIDAD Y SALUD EN EL TRABAJO {{ $plan->year }}</div>
  <div><b>{{ $company->legal_name }}</b> · RUC {{ trim($company->ruc) }}</div>
  <div class="muted">{{ $plan->title }} · versión {{ $plan->version_number }}</div>
  @unless(in_array($plan->status, ['approved', 'current', 'closed'], true))
    <div class="draft">BORRADOR · el plan aún no está aprobado</div>
  @endunless

  <h2>1. Datos del plan</h2>
  <table class="f">
    <tr><td class="k">Año</td><td>{{ $plan->year }}</td></tr>
    <tr><td class="k">Objetivos generales</td><td>{{ $plan->objectives_summary ?: '—' }}</td></tr>
    <tr><td class="k">Aprobación</td><td>{{ $approvedBy ?? '—' }} · {{ $date($plan->approved_at) }}</td></tr>
    <tr><td class="k">Acta de aprobación</td><td>
      @if($meeting) Sesión {{ ['ordinary' => 'ordinaria', 'extraordinary' => 'extraordinaria', 'installation' => 'de instalación'][$meeting->meeting_type] ?? '' }}{{ $meeting->number ? ' N.° '.$meeting->number : '' }} del Comité SST · {{ $date($meeting->held_at ?? $meeting->scheduled_at) }}
      @elseif(count($signatures)) Firma del Supervisor SST
      @else — @endif
    </td></tr>
  </table>

  <h2>2. Diagnóstico de línea base</h2>
  @if($baseline)
    <table class="f">
      <tr><td class="k">Diagnóstico</td><td>{{ $baseline->title }}</td></tr>
      <tr><td class="k">Fecha</td><td>{{ $date($baseline->assessment_on) }}</td></tr>
      <tr><td class="k">Cumplimiento</td><td>{{ $baseline->score_pct !== null ? rtrim(rtrim(number_format((float) $baseline->score_pct, 1, ',', ''), '0'), ',').' %' : '—' }}</td></tr>
    </table>
  @else <p class="muted">Sin diagnóstico vinculado.</p> @endif

  <h2>3. Política de seguridad y salud en el trabajo</h2>
  @if($policy)
    <p class="muted">{{ $policy->title }} · versión {{ $policy->version_number }} · vigente desde {{ $date($policy->effective_on) }}</p>
    <p>{!! nl2br(e($policyText)) !!}</p>
  @else <p class="muted">Sin política vinculada.</p> @endif

  <h2>4. Objetivos, metas e indicadores</h2>
  @php($objs = array_values(array_filter($items, fn ($i) => in_array($i->item_type ?? ($i->objective_id ? 'objective' : null), ['objective', 'goal', 'indicator', null], true))))
  @if(count($objs))
    <table class="t">
      <tr><th style="width:9%">Código</th><th style="width:12%">Tipo</th><th>Descripción</th><th style="width:20%">Meta e indicador</th><th style="width:15%">Responsable</th><th style="width:14%">Plazo</th></tr>
      @foreach($objs as $i)
        <tr>
          <td>{{ $i->code ?: ($i->objective_code ?? '') }}</td>
          <td>{{ $itemType[$i->item_type ?? 'objective'] ?? '—' }}</td>
          <td>{{ $i->objective_title && $i->objective_title !== $i->description ? $i->objective_title.' · ' : '' }}{{ $i->description }}</td>
          <td>{{ $i->goal ?: '—' }}@if($i->indicator)<br><span class="muted">{{ $i->indicator }}</span>@endif</td>
          <td>{{ $i->responsible ?? '—' }}</td>
          <td>{{ $date($i->starts_on) }} a {{ $date($i->ends_on) }}</td>
        </tr>
      @endforeach
    </table>
  @else <p class="muted">Sin objetivos registrados.</p> @endif

  <h2>5. Programas vinculados</h2>
  @if(count($programs))
    <table class="t">
      <tr><th style="width:30%">Programa</th><th>Título</th><th style="width:12%">Año</th><th style="width:20%">Estado</th></tr>
      @foreach($programs as $g)
        <tr><td>{{ $g['kind'] }}</td><td>{{ $g['title'] }}</td><td>{{ $g['year'] }}</td><td>{{ $g['status'] }}</td></tr>
      @endforeach
    </table>
  @else <p class="muted">Sin programas vinculados.</p> @endif

  <h2>6. Recursos</h2>
  <p>{{ $plan->resources_text ?: '' }}</p>
  @php($res = array_values(array_filter($items, fn ($i) => in_array($i->item_type, ['human_resource', 'material_resource', 'financial_resource'], true) || $i->resources)))
  @if(count($res))
    <table class="t">
      <tr><th style="width:20%">Tipo</th><th>Recurso</th><th style="width:20%">Responsable</th></tr>
      @foreach($res as $i)
        <tr><td>{{ in_array($i->item_type, ['human_resource', 'material_resource', 'financial_resource'], true) ? $itemType[$i->item_type] : 'Del componente '.($i->code ?? '') }}</td>
          <td>{{ in_array($i->item_type, ['human_resource', 'material_resource', 'financial_resource'], true) ? $i->description : $i->resources }}</td><td>{{ $i->responsible ?? '—' }}</td></tr>
      @endforeach
    </table>
  @elseif(! $plan->resources_text) <p class="muted">Sin recursos registrados.</p> @endif

  <table class="sign"><tr>
    <td><span>Responsable del SG-SST</span></td>
    <td><span>{{ $company->sst_model === 'committee' ? 'Presidente del Comité SST' : 'Supervisor SST' }}</span></td>
  </tr></table>
</body>
</html>
