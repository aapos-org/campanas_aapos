# Webhooks de ElasticEmail

ElasticEmail avisa a la plataforma de entregas, rebotes, quejas de spam y bajas. Con eso se mantiene limpia la lista de supresión.

## Configuración

1. En el servidor, genera el token y agrégalo al `.env`:
    ```bash
    php artisan app:webhook-token
    php artisan config:cache
    ```
2. En ElasticEmail: **Settings → Webhooks → Add webhook**.
    - URL: la que muestra el comando (también aparece en **Administración → Webhooks**). Contiene el token: no la compartas.
    - Método: **POST JSON** (GET también funciona).
    - Eventos: **Sent, Error (bounces), Unsubscribed, AbuseReport**. No hace falta Opened ni Clicked: la plataforma mide aperturas y clics por su cuenta.
3. Envía una campaña de prueba y revisa **Administración → Webhooks**: cada notificación aparece con su contenido y el resultado.

## Qué hace con cada evento

| Evento                        | Resultado                                                                  |
| ----------------------------- | -------------------------------------------------------------------------- |
| Sent / Delivered              | Se registra como entregado y reinicia el contador de rebotes suaves.       |
| Error con categoría NoMailbox | Rebote duro: supresión **global** y suscripciones marcadas como limpiadas. |
| Error con otra categoría      | Rebote suave; al 5.º seguido, el correo se marca como limpiado.            |
| AbuseReport                   | Queja de spam: supresión **global**.                                       |
| Unsubscribed                  | Baja de la audiencia de la campaña.                                        |
| Opened / Clicked              | Se ignoran (rastreo propio).                                               |

Las notificaciones repetidas se aplican una sola vez y las de más de 30 días se ignoran.

## Calibración pendiente

ElasticEmail no documenta el formato exacto del cuerpo. El lector acepta las variantes conocidas, pero conviene revisar las primeras notificaciones reales en **Administración → Webhooks** y ajustar si algún campo llega con otro nombre. Requiere una URL pública (servidor publicado o túnel) y la cuenta de ElasticEmail con la tarjeta verificada.
