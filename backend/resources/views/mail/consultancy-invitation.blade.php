<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invitación de tu consultora SST</title>
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
                        <p style="margin:0 0 12px;font-size:16px;">Hola:</p>
                        <p style="margin:0 0 16px;font-size:15px;line-height:1.5;"><b>{{ $consultancy }}</b> te invita a gestionar la seguridad y salud en el trabajo de <b>{{ $companyName }}</b> (RUC {{ $ruc }}) en EscudoSST.</p>
@if ($companyExists)
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.5;">Tu empresa ya usa EscudoSST. Si aceptas, la consultora podrá trabajar en ella; puedes quitarle el acceso cuando quieras.</p>
@else
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.5;">Crea la cuenta de tu empresa y elige tu plan{{ $planName ? ' (la consultora te sugiere el plan '.$planName.')' : '' }}. Luego vuelve a este enlace para aceptar.</p>
@endif
                        <p style="margin:0 0 20px;text-align:center;">
                            <a href="{{ $url }}" style="display:inline-block;background:#0d3b6e;color:#ffffff;border-radius:10px;padding:12px 22px;font-size:15px;font-weight:bold;text-decoration:none;">Ver la invitación</a>
                        </p>
                        <p style="margin:0 0 8px;font-size:14px;color:#6b7684;">El enlace vence en {{ $days }} días y sirve una sola vez.</p>
                        <p style="margin:0;font-size:12px;color:#6b7684;word-break:break-all;">Si el botón no funciona, copia este enlace en tu navegador: {{ $url }}</p>
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
