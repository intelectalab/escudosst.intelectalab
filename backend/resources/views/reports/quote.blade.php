{{-- Cotización en PDF (SalesQuotesService). La arma dompdf: estilos simples, tablas, sin flex ni grid. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Cotización {{ $q->number }}</title>
<style>
  @page { margin: 32px 40px; }
  body { font-family: DejaVu Sans, sans-serif; color: #1b2a3a; font-size: 10.5px; line-height: 1.45; }
  .head { width: 100%; border-collapse: collapse; }
  .head td { vertical-align: top; }
  .brand { font-size: 18px; font-weight: bold; color: #0D3B6E; }
  .muted { color: #5b6b7c; font-size: 9.5px; }
  .box { background: #0D3B6E; color: #fff; padding: 10px 14px; border-radius: 8px; text-align: right; }
  .box b { font-size: 14px; }
  h2 { font-size: 12px; color: #0D3B6E; margin: 18px 0 6px; border-bottom: 1px solid #d6dde6; padding-bottom: 4px; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data td, table.data th { padding: 5px 6px; border-bottom: 1px solid #e6ebf1; text-align: left; }
  table.data th { background: #EEF3F8; color: #0D3B6E; font-size: 9.5px; text-transform: uppercase; }
  .r { text-align: right !important; white-space: nowrap; }
  .co { background: #F7F9FC; font-weight: bold; }
  .tot td { font-weight: bold; font-size: 12px; color: #0D3B6E; border-bottom: 0; }
  .note { margin-top: 14px; padding: 9px 12px; background: #F7F9FC; border: 1px solid #d6dde6; border-radius: 6px; white-space: pre-line; }
  .steps { margin-top: 14px; padding: 10px 12px; background: #EAF3FC; border-radius: 6px; }
  .steps ol { margin: 4px 0 0 16px; padding: 0; }
</style>
</head>
<body>
  <table class="head">
    <tr>
      <td>
        <div class="brand">{{ $s['trade_name'] ?: 'EscudoSST' }}</div>
        <div class="muted">{{ $s['legal_name'] }}@if($s['ruc']) · RUC {{ $s['ruc'] }}@endif</div>
        @if($s['legal_address'])<div class="muted">{{ $s['legal_address'] }}</div>@endif
        @if($s['sales_email'] ?: $s['contact_email'])<div class="muted">{{ $s['sales_email'] ?: $s['contact_email'] }}@if($s['contact_phone']) · {{ $s['contact_phone'] }}@endif</div>@endif
      </td>
      <td style="width: 210px;">
        <div class="box">COTIZACIÓN<br><b>{{ $q->number }}</b><br>Emitida: {{ $issued }}<br>Válida hasta: {{ $valid }}</div>
      </td>
    </tr>
  </table>

  <h2>Cliente</h2>
  <table class="data">
    <tr><td style="width: 140px;">Contacto</td><td>{{ $q->contact_name }}</td></tr>
    <tr><td>Correo</td><td>{{ $q->contact_email }}@if($q->contact_phone) · {{ $q->contact_phone }}@endif</td></tr>
    <tr><td>Periodo de pago</td><td>{{ $period === 'anual' ? 'Anual (pago por el año completo)' : 'Mensual' }}</td></tr>
  </table>

  <h2>Detalle {{ count($companies) > 1 ? '(grupo de '.count($companies).' empresas: cada una con su propia suscripción)' : '' }}</h2>
  <table class="data">
    <tr><th>Concepto</th><th class="r">Mensual de referencia</th><th class="r">Monto {{ $period }}</th></tr>
    @foreach($companies as $c)
      <tr class="co"><td colspan="3">{{ $c['legal_name'] }} · RUC {{ $c['ruc'] }} · {{ $c['headcount'] }} trabajadores</td></tr>
      @foreach($c['lines'] as $l)
        <tr><td>&nbsp;&nbsp;{{ $l['name'] }}</td><td class="r">{{ $money($l['monthly']) }}</td><td class="r">{{ $money($l['amount']) }}</td></tr>
      @endforeach
      @if(count($companies) > 1)
        <tr><td class="r" colspan="2">Subtotal {{ $c['legal_name'] }}</td><td class="r">{{ $money($c['amount']) }}</td></tr>
      @endif
    @endforeach
    <tr><td class="r" colspan="2">Subtotal</td><td class="r">{{ $money($q->subtotal) }}</td></tr>
    @if((float) $q->discount_amount > 0)
      <tr><td class="r" colspan="2">Descuento</td><td class="r">− {{ $money($q->discount_amount) }}</td></tr>
    @endif
    <tr class="tot"><td class="r" colspan="2">TOTAL {{ $period === 'anual' ? 'POR EL AÑO' : 'POR EL MES' }}</td><td class="r">{{ $money($q->total) }}</td></tr>
  </table>
  @if($period === 'anual' && (float) $q->annual_discount_pct > 0)
    <p class="muted">El monto anual incluye {{ rtrim(rtrim(number_format((float) $q->annual_discount_pct, 2), '0'), '.') }} % de descuento por pago anual.</p>
  @endif
  <p class="muted">Montos en soles (PEN).</p>

  @if($q->client_notes)
    <div class="note"><b>Condiciones y notas</b><br>{{ $q->client_notes }}</div>
  @endif

  <div class="steps">
    <b>¿Cómo sigue?</b>
    <ol>
      <li>Desde el enlace de nuestro correo, indica si aceptas la cotización.</li>
      <li>Al aceptar, verás los datos para el pago. Paga y envíanos la constancia desde el mismo enlace.</li>
      <li>Confirmamos el pago y te llega el correo para crear tu contraseña y entrar a EscudoSST.</li>
    </ol>
  </div>
</body>
</html>
