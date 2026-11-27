<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tu código para entrar a {{ $flotilla }}</title>
</head>
<body style="margin:0;padding:0;background:#f7f6fb;font-family:Arial,Helvetica,sans-serif;color:#1b1530;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f6fb;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:440px;background:#ffffff;border-radius:20px;overflow:hidden;">
                    <tr>
                        <td style="background:#5b21b6;padding:24px 28px;color:#ffffff;font-size:22px;font-weight:bold;">
                            {{ $flotilla }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 8px;font-size:18px;font-weight:bold;">Tu código para entrar</p>
                            <p style="margin:0 0 24px;font-size:15px;color:#6b6780;">Escríbelo en la app para continuar.</p>
                            <p style="margin:0 0 24px;text-align:center;font-size:40px;font-weight:bold;letter-spacing:10px;color:#5b21b6;background:#f5f3ff;border-radius:14px;padding:18px 0;">{{ $codigo }}</p>
                            <p style="margin:0 0 8px;font-size:14px;color:#6b6780;">Sirve {{ $minutos }} minutos y solo una vez.</p>
                            <p style="margin:0;font-size:14px;color:#6b6780;">Si no pediste este código, ignora este correo. Nadie puede entrar a tu cuenta sin él.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
