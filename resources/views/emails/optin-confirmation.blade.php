@php($primary = $form->color('primary_color'))
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $form->text('confirmation_subject') }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px;">
                    <tr><td style="font-size:20px;font-weight:bold;padding-bottom:16px;">{{ $form->text('confirmation_subject') }}</td></tr>
                    <tr><td style="font-size:15px;line-height:1.6;padding-bottom:24px;">{{ $form->text('confirmation_intro') }}</td></tr>
                    <tr>
                        <td style="padding-bottom:24px;">
                            <a href="{{ $url }}" style="display:inline-block;background:{{ $primary }};color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:bold;">Confirmar mi suscripción</a>
                        </td>
                    </tr>
                    <tr><td style="font-size:13px;color:#6b7280;line-height:1.5;">El enlace caduca en {{ $days }} días. Si el botón no funciona, copia esta dirección en tu navegador:<br><span style="word-break:break-all;">{{ $url }}</span></td></tr>
                    <tr><td style="font-size:12px;color:#9ca3af;padding-top:24px;">{{ $organization->name }}@if($organization->physical_address) · {{ $organization->physical_address }}@endif</td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
