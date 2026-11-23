# Guía de migración desde Mailchimp

Esta guía traslada la cuenta de Mailchimp a la plataforma de forma **íntegra, verificable, repetible y segura**, sin reactivar a nadie que se haya dado de baja. Sigue los pasos en orden. Los comandos se ejecutan en el servidor (o con `./vendor/bin/sail` delante en desarrollo).

## Resumen

```
mailchimp:extract  →  respaldo cifrado (JSON)  →  mailchimp:load --dry-run  →  revisar informe
                   →  mailchimp:load  →  mailchimp:verify  →  revisar docs/migracion_informe.md
```

## 0. Antes de empezar

1. **Crea una API key dedicada** en Mailchimp (_Profile → Extras → API keys → Create A Key_) con el nombre «Migración campañas». Mailchimp no ofrece claves de solo lectura: la plataforma garantiza por código que **solo hace consultas GET** (hay una prueba automática que lo comprueba).
2. Pega la clave en el `.env` del servidor (nunca en un chat ni en el repositorio):
    ```
    MAILCHIMP_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us21
    ```
3. Comprueba que existe la organización y un administrador (`php artisan app:create-admin`).
4. **Respalda el `APP_KEY`**: el respaldo de Mailchimp se cifra con él. Si se pierde, el respaldo no se puede leer.

## 1. Extracción cruda

```bash
php artisan mailchimp:extract
```

- Descarga audiencias, campos, contactos (de 1,000 en 1,000), etiquetas, segmentos, formularios, plantillas, automatizaciones, campañas enviadas y sus reportes.
- Cada respuesta se guarda **cifrada** en `storage/app/private/mailchimp/default/`, con un `manifest.json` (sin datos personales) de lo descargado.
- Ante errores 429/5xx reintenta con espera exponencial. **Si se interrumpe, vuelve a ejecutar el mismo comando**: continúa desde lo ya descargado sin volver a pedirlo a Mailchimp.
- Opciones útiles:
    - `--without-activity`: omite destinatarios y aperturas/clics por correo (mucho más rápido; útil para un primer ensayo).
    - `--campaigns-since=2024-01-01`: solo campañas recientes.
    - `--without-campaigns`: solo audiencias y contactos.
    - `--snapshot=nombre`: guarda en otra carpeta (por ejemplo, una extracción final distinta de la de prueba).

### Alternativa sin API (exportación CSV)

Si la API no está disponible, exporta cada audiencia desde el panel (_Audience → Export audience_). El ZIP trae los CSV de suscritos, dados de baja, limpiados y no suscritos:

```bash
php artisan mailchimp:import-export /ruta/export.zip --audience="Boletín" --snapshot=csv
```

Luego usa `--snapshot=csv` en los pasos siguientes. Los CSV extraídos del ZIP se borran tras convertirlos.

## 2. Ensayo en seco (obligatorio)

```bash
php artisan mailchimp:load --dry-run
```

Hace **todo** el proceso (staging, normalización y carga) dentro de una transacción que se deshace al final: no cambia nada y produce un informe exacto de lo que haría la carga real, en `storage/app/private/mailchimp/default/reports/ensayo-*.md`:

- contactos nuevos y actualizados por audiencia;
- estado resultante (suscritos, bajas, limpiados, pendientes, no suscritos);
- contactos **no reactivados** (porque ya estaban dados de baja o suprimidos en la plataforma);
- suscritos sin fecha de opt-in registrada en Mailchimp;
- valores descartados (teléfonos o fechas inválidas) y registros rechazados con su motivo (correos enmascarados).

La carga real se niega a ejecutarse si no hay un ensayo previo del mismo respaldo.

## 3. Carga

```bash
php artisan mailchimp:load
```

Orden: audiencias → campos personalizados → contactos y estados → supresiones → etiquetas. Además genera en `docs/`:

- `automatizaciones_pendientes.md` — Mailchimp no exporta su lógica; reconstrúyelas en el Módulo 6.
- `formularios_pendientes.md` — textos de los formularios para recrearlos en **Formularios**.
- `segmentos_pendientes.md` — condiciones de los segmentos guardados (Módulo 5). Las etiquetas ya se migran como etiquetas.

### Historial de campañas

Después de cargar los contactos:

```bash
php artisan mailchimp:load-history --dry-run
php artisan mailchimp:load-history
```

Carga las campañas enviadas como **históricas de solo lectura** (contenido, resumen del reporte y clics por enlace). Es idempotente. Las plantillas de Mailchimp no se pueden importar (la API no entrega su HTML): duplica una campaña histórica como borrador para reutilizar su diseño.

### Reglas que aplica

| Estado en Mailchimp                | Resultado en la plataforma                                            |
| ---------------------------------- | --------------------------------------------------------------------- |
| `subscribed`                       | Suscrito, con prueba de consentimiento (fecha e IP de opt-in, origen) |
| `unsubscribed`                     | Dado de baja + supresión de esa audiencia                             |
| `unsubscribed` por queja (_abuse_) | Dado de baja + supresión **global** por queja                         |
| `cleaned`                          | Limpiado + supresión **global**                                       |
| `pending`                          | Pendiente, **sin** consentimiento (nunca se trata como suscrito)      |
| `transactional`, `archived`        | No suscrito                                                           |

- Un rebote o queja en cualquier audiencia suprime a la persona en **todas**.
- Volver a ejecutar la carga es seguro (idempotente): no duplica nada y **nunca reactiva** a quien se dio de baja localmente.
- Los datos editados en la plataforma no se sobrescriben; solo se completan campos vacíos.

## 4. Verificación

```bash
php artisan mailchimp:verify
```

Compara, por audiencia y estado, los conteos del respaldo con la plataforma (aplicando la regla de supresión global) y revisa una **muestra aleatoria** de 50 contactos (`--sample=200` para más). Genera `docs/migracion_informe.md` con los correos enmascarados (este archivo está en `.gitignore`). Termina con error si hay diferencias.

## 5. Cierre

**No des de baja la cuenta de Mailchimp** hasta que:

1. `mailchimp:verify` termine **sin diferencias** relevantes;
2. hayas copiado fuera del servidor la carpeta `storage/app/private/mailchimp/` **junto con el `APP_KEY`** (sin la clave el respaldo es ilegible);
3. hayas revocado la API key en Mailchimp.

## Después de migrar

- Configura y verifica **SPF, DKIM y DMARC** del dominio de envío en ElasticEmail (Conexiones de envío → Verificar).
- **Calentamiento gradual**: empieza con los contactos más activos y aumenta el volumen por etapas (p. ej. 500, 2,000, 5,000… por día).
- Envía la primera campaña a un segmento pequeño antes de los envíos masivos.
