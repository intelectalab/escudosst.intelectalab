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
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="background:#0d3b6e;padding:18px 24px;color:#ffffff;font-size:20px;font-weight:bold;">EscudoSST</td>
                </tr>
                <tr>
                    <td style="padding:28px 24px;">
                        <p style="margin:0 0 6px;font-size:16px;">Hola, {{ $name }}:</p>
                        <p style="margin:0 0 18px;font-size:15px;line-height:1.5;">Hay un aviso de <b>{{ $company }}</b> que requiere tu atención:</p>
@php($c = in_array($priority, ['critical', 'high'], true) ? ['#fbe4e4', '#a12626'] : ['#e3effb', '#0d3b6e'])
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;border-radius:10px;background:{{ $c[0] }};">
                            <tr>
                                <td style="padding:12px 14px;">
                                    <p style="margin:0 0 4px;font-size:15px;font-weight:bold;color:{{ $c[1] }};">{{ $title }}</p>
                                    <p style="margin:0;font-size:14px;line-height:1.45;">{{ $body }}</p>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:20px 0 0;text-align:center;">
                            <a href="{{ $url }}" style="display:inline-block;background:#0d3b6e;color:#ffffff;border-radius:10px;padding:12px 22px;font-size:15px;font-weight:bold;text-decoration:none;">Abrir en EscudoSST</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="background:#f4b90f;height:6px;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
            </table>
            <p style="margin:16px 0 0;font-size:12px;color:#6b7684;">Correo automático de EscudoSST. Puedes cambiar estos avisos en tus preferencias de notificación.</p>
        </td>
    </tr>
</table>
</body>
</html>
