<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Código de verificación</title>
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
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.5;">Usa este código para terminar de iniciar sesión en EscudoSST:</p>
                        <p style="margin:0 0 20px;text-align:center;">
                            <span style="display:inline-block;background:#e3effb;color:#0d3b6e;border-radius:10px;padding:14px 22px;font-size:32px;font-weight:bold;letter-spacing:8px;font-family:Consolas,monospace;">{{ $code }}</span>
                        </p>
                        <p style="margin:0 0 8px;font-size:14px;color:#6b7684;">Vence en {{ $minutes }} minutos y sirve una sola vez.</p>
                        <p style="margin:0;font-size:14px;color:#6b7684;">Si no intentaste ingresar, ignora este correo y cambia tu contraseña: alguien la conoce. Nunca te pediremos este código por teléfono ni por WhatsApp.</p>
                    </td>
                </tr>
                <tr>
                    <td style="background:#f4b90f;height:6px;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
            </table>
            <p style="margin:16px 0 0;font-size:12px;color:#6b7684;">Correo automático de EscudoSST. No respondas a este mensaje.</p>
        </td>
    </tr>
</table>
</body>
</html>
