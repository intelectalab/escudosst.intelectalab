<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#eef1f5;font-family:'Segoe UI',Arial,sans-serif;color:#262b33;">
@if ($preheader)<div style="display:none;max-height:0;overflow:hidden;">{{ $preheader }}</div>@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f5;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="background:#0d3b6e;padding:18px 24px;color:#ffffff;">
                        <span style="font-size:20px;font-weight:bold;">EscudoSST</span>
                        <span style="display:block;font-size:12px;color:#9cc8f2;letter-spacing:.5px;">{{ $kicker }}</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 24px;font-size:15px;line-height:1.6;">
                        <h1 style="margin:0 0 18px;font-size:22px;line-height:1.3;color:#00254d;">{{ $subjectLine }}</h1>
                        @foreach ($blocks as $b)
                            @if ($b['t'] === 'h')
                                <h2 style="margin:22px 0 8px;font-size:17px;color:#0d3b6e;">{{ $b['text'] }}</h2>
                            @elseif ($b['t'] === 'ul')
                                <ul style="margin:0 0 14px;padding-left:20px;">
                                    @foreach ($b['items'] as $it)<li style="margin:4px 0;">{{ $it }}</li>@endforeach
                                </ul>
                            @else
                                <p style="margin:0 0 14px;">{{ $b['text'] }}</p>
                            @endif
                        @endforeach
                        @if ($legalNote)<p style="margin:22px 0 0;font-size:13px;color:#6b7684;">Información referencial; no reemplaza la lectura de la norma ni la asesoría legal.</p>@endif
                    </td>
                </tr>
                <tr>
                    <td style="background:#f4b90f;height:6px;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
            </table>
            <p style="margin:16px 0 0;font-size:12px;color:#6b7684;line-height:1.6;">
                {{ $reason }}<br>
                <a href="{{ $webUrl }}" style="color:#1f7fd1;">Ver en el navegador</a> ·
                <a href="{{ $unsubscribeUrl }}" style="color:#1f7fd1;">Darme de baja</a>
            </p>
        </td>
    </tr>
</table>
</body>
</html>
