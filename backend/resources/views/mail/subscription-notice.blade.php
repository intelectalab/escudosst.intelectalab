<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#eef1f5;font-family:'Segoe UI',Arial,sans-serif;color:#262b33;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f5;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="background:#0d3b6e;padding:18px 24px;color:#ffffff;font-size:20px;font-weight:bold;">EscudoSST</td>
                </tr>
                <tr>
                    <td style="padding:28px 24px;">
                        <p style="margin:0 0 12px;font-size:16px;">Hola, {{ $name }}:</p>
@php($colors = ['info' => ['#e3effb', '#0d3b6e'], 'warning' => ['#fdf3d7', '#7a5300'], 'danger' => ['#fbe4e4', '#a12626'], 'success' => ['#e2f5ea', '#1d6b3c']][$tone] ?? ['#e3effb', '#0d3b6e'])
                        <p style="margin:0 0 16px;padding:12px 14px;border-radius:10px;background:{{ $colors[0] }};color:{{ $colors[1] }};font-size:16px;font-weight:bold;">{{ $title }}</p>
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.5;">{{ $body }}</p>
                        <p style="margin:0 0 20px;text-align:center;">
                            <a href="{{ $url }}" style="display:inline-block;background:#0d3b6e;color:#ffffff;border-radius:10px;padding:12px 22px;font-size:15px;font-weight:bold;text-decoration:none;">Ingresar a EscudoSST</a>
                        </p>
                        <p style="margin:0;font-size:13px;color:#6b7684;">Tus registros nunca se borran: aunque la cuenta quede en solo lectura, puedes verlos y descargarlos.</p>
                    </td>
                </tr>
                <tr>
                    <td style="background:#f4b90f;height:6px;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
            </table>
            <p style="margin:16px 0 0;font-size:12px;color:#6b7684;">Si no esperabas este correo, ignóralo. Correo automático de EscudoSST. No respondas a este mensaje.</p>
        </td>
    </tr>
</table>
</body>
</html>
