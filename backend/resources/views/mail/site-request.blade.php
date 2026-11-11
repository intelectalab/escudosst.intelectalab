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
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="background:#0d3b6e;padding:18px 24px;color:#ffffff;font-size:20px;font-weight:bold;">EscudoSST</td>
                </tr>
                <tr>
                    <td style="padding:28px 24px;">
                        <p style="margin:0 0 12px;font-size:16px;">{{ in_array(mb_strtolower(trim($name)), ['', 'hola'], true) ? 'Hola:' : 'Hola, '.$name.':' }}</p>
                        <p style="margin:0 0 16px;padding:12px 14px;border-radius:10px;background:#e3effb;color:#0d3b6e;font-size:16px;font-weight:bold;">{{ $title }}</p>
                        <p style="margin:0 0 18px;font-size:15px;line-height:1.5;">{{ $intro }}</p>
                        @if (count($rows))
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border:1px solid #e0e5eb;border-radius:10px;">
                                @foreach ($rows as [$label, $value])
                                    <tr>
                                        <td style="padding:10px 14px;font-size:14px;color:#6b7684;border-bottom:1px solid #eef1f5;">{{ $label }}</td>
                                        <td style="padding:10px 14px;font-size:14px;font-weight:bold;text-align:right;border-bottom:1px solid #eef1f5;">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                        @if ($note)
                            <p style="margin:0 0 18px;font-size:13px;color:#6b7684;line-height:1.5;white-space:pre-line;">{{ $note }}</p>
                        @endif
                        @if ($buttonUrl)
                            <p style="margin:0;text-align:center;">
                                <a href="{{ $buttonUrl }}" style="display:inline-block;background:#1f7fd1;color:#ffffff;border-radius:10px;padding:12px 22px;font-size:15px;font-weight:bold;text-decoration:none;">{{ $buttonText }}</a>
                            </p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="background:#f4b90f;height:6px;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
            </table>
            <p style="margin:16px 0 0;font-size:12px;color:#6b7684;">Recibes este correo porque se envió un formulario en el sitio de EscudoSST con esta dirección. Si no fuiste tú, ignóralo.</p>
        </td>
    </tr>
</table>
</body>
</html>
