# API pública y webhooks salientes

## API REST `/api/v1`

- **Documentación interactiva (OpenAPI):** `/docs/api` (solo administradores con permiso de API). El documento JSON está en `/docs/api.json`; también se puede exportar con `php artisan scramble:export`.
- **Tokens:** _Administración → API_. Cada token tiene permisos concretos y nunca más de los que permite el rol de su dueño; se muestra una sola vez y se puede revocar. Opcionalmente vence.
- **Autenticación:** cabecera `Authorization: Bearer <token>`.
- **Límite:** 120 solicitudes por minuto por token (`API_RATE_PER_MINUTE`).

| Permiso               | Rutas                                                                                                     |
| --------------------- | --------------------------------------------------------------------------------------------------------- |
| `audiences:read`      | `GET /audiences`, `GET /audiences/{id}`                                                                   |
| `contacts:read`       | `GET /contacts`, `GET /contacts/{id}`                                                                     |
| `contacts:write`      | `POST /contacts` (crea o actualiza por correo), `PATCH /contacts/{id}`, `POST/DELETE /contacts/{id}/tags` |
| `subscriptions:write` | `POST /audiences/{id}/subscribe`, `POST /audiences/{id}/unsubscribe`                                      |
| `tags:read`           | `GET /tags`                                                                                               |
| `campaigns:read`      | `GET /campaigns`, `GET /campaigns/{id}` (con estadísticas)                                                |

Reglas: suscribir exige `consent_detail` (dónde y cómo aceptó la persona); una dirección suprimida (rebote, queja o baja previa) nunca se reactiva y la API responde 422 con el motivo. La API no envía el correo de doble opt-in: quien integre es responsable del consentimiento.

Ejemplo:

```bash
curl -X POST https://campanas.example.org/api/v1/audiences/1/subscribe \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"email":"ana@example.com","first_name":"Ana","consent_detail":"Formulario de donación del sitio web"}'
```

## Webhooks salientes

_Administración → Webhooks salientes_. Eventos: `contact.subscribed`, `contact.unsubscribed`, `email.bounced`, `email.clicked`, `campaign.sent` (y `ping` para probar).

Cada entrega es un `POST` JSON:

```json
{ "id": "uuid", "event": "contact.subscribed", "occurred_at": "2026-11-24T15:00:00+00:00", "data": { … } }
```

Cabeceras: `X-AAPOS-Event`, `X-AAPOS-Delivery` y `X-AAPOS-Signature: t=<timestamp>,v1=<firma>`, donde la firma es `hex(hmac_sha256(secreto, "<timestamp>.<cuerpo>"))`. Verificación en PHP:

```php
[$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $_SERVER['HTTP_X_AAPOS_SIGNATURE']));
$ok = hash_equals(hash_hmac('sha256', $t.'.'.file_get_contents('php://input'), $secret), $v1) && abs(time() - (int) $t) < 300;
```

Reintentos: hasta 6 intentos (1 min, 5 min, 30 min, 2 h, 6 h). Tras 20 fallos seguidos el destino se desactiva. Solo se envía a direcciones públicas de internet (protección SSRF), sin seguir redirecciones. Cada intento queda en el registro de entregas, desde donde se puede reenviar.
