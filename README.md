# Campañas AAPOS

Plataforma interna de email marketing: audiencias, campañas, reportes y migración desde Mailchimp.

> **Estado:** Fase 3 — migración de Mailchimp (extracción cifrada, ensayo en seco, carga y verificación) y Módulo 2 (formularios con doble opt-in, preferencias y baja en un clic). Fases anteriores: conexiones de envío y Módulo 1.
> Las decisiones de arquitectura están en [`docs/DECISIONES.md`](docs/DECISIONES.md).

## Stack

| Capa     | Tecnología                                                                                        |
| -------- | ------------------------------------------------------------------------------------------------- |
| Backend  | PHP 8.4, Laravel 13, Fortify (login + 2FA), spatie/laravel-permission, spatie/laravel-activitylog |
| Frontend | Inertia 3 + Vue 3 + TypeScript, Tailwind CSS 4, componentes shadcn-vue                            |
| Datos    | PostgreSQL 18, Redis (colas, caché, sesiones)                                                     |
| Colas    | Laravel Horizon                                                                                   |
| Calidad  | Pest, Larastan (nivel 8), Pint, vue-tsc, Vite+ lint/format, `composer audit`                      |

## Desarrollo local

Requisitos: **Docker** (Docker Desktop en macOS). No hace falta PHP ni PostgreSQL instalados en el equipo.

```bash
cp .env.example .env
```

```bash
docker run --rm -v "$PWD":/app -w /app composer:latest composer install --ignore-platform-req=ext-*
```

```bash
./vendor/bin/sail up -d
```

```bash
./vendor/bin/sail artisan key:generate && ./vendor/bin/sail artisan migrate --seed
```

```bash
./vendor/bin/sail npm ci && ./vendor/bin/sail npm run build
```

Abre <http://localhost:8080>. Cuentas de demostración (contraseña `password`, solo entorno local):

| Correo                 | Rol                                                |
| ---------------------- | -------------------------------------------------- |
| `admin@campanas.test`  | Administrador (se le pedirá activar 2FA al entrar) |
| `editor@campanas.test` | Editor                                             |
| `lector@campanas.test` | Solo lectura                                       |

Servicios auxiliares:

| Servicio                              | URL / puerto                                              |
| ------------------------------------- | --------------------------------------------------------- |
| Aplicación                            | <http://localhost:8080>                                   |
| Mailpit (correos de prueba)           | <http://localhost:8025>                                   |
| Horizon (colas, solo administradores) | <http://localhost:8080/horizon>                           |
| PostgreSQL                            | `localhost:54320` (usuario `sail`, contraseña `password`) |
| Redis                                 | `localhost:63790`                                         |

Para desarrollo con recarga en caliente usa `./vendor/bin/sail npm run dev` (Vite en el puerto 5174) y, en otra terminal, `./vendor/bin/sail artisan horizon`.

## Calidad y pruebas

Todo el flujo de calidad (el mismo que corre GitHub Actions en cada PR):

```bash
./vendor/bin/sail composer ci
```

Incluye Pint, Larastan, lint y formato del frontend, verificación de tipos TypeScript, la suite de Pest (sobre PostgreSQL, base `testing`) y `composer audit`.

Solo las pruebas:

```bash
./vendor/bin/sail artisan test
```

## Conexiones de envío

| Tipo                  | Uso           | Requisitos                                                                                                                                       |
| --------------------- | ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| ElasticEmail (API v4) | Envíos reales | Clave de API con permisos de envío por HTTP, ver cuenta y ver ajustes; dominio con SPF, DKIM y DMARC                                             |
| Gmail (SMTP)          | Pruebas       | Verificación en dos pasos y contraseña de aplicación                                                                                             |
| Gmail (OAuth)         | Pruebas       | `GOOGLE_CLIENT_ID` y `GOOGLE_CLIENT_SECRET` (app OAuth interna de Workspace); URI de redirección `https://<dominio>/connections/google/callback` |

Gmail limita los envíos diarios; la aplicación lleva la cuenta por conexión y no supera el límite configurado.

Las importaciones se procesan en la cola `imports` (Horizon, supervisor `supervisor-imports`). En desarrollo, ejecuta `./vendor/bin/sail artisan horizon` para que avancen.

## Migración desde Mailchimp

Guía paso a paso en [`docs/MIGRACION.md`](docs/MIGRACION.md): `mailchimp:extract` → `mailchimp:load --dry-run` → `mailchimp:load` → `mailchimp:verify`.

## Formularios públicos

Cada formulario tiene una página alojada (`/f/{código}`) y códigos para insertar (JavaScript, iframe o HTML). Para el captcha opcional configura `TURNSTILE_SITE_KEY` y `TURNSTILE_SECRET_KEY` (Cloudflare Turnstile). Los correos de confirmación salen por la conexión de envío predeterminada.

## Segmentos, pruebas A/B y automatizaciones

- **Segmentos** (_Contactos → Segmentos_): grupos dinámicos por etiquetas, campos, estado, fecha de alta y actividad en campañas, con conteo en vivo. Una campaña puede dirigirse a un segmento de su audiencia.
- **Pruebas A/B** (paso _Configuración_ de la campaña): asunto, remitente o contenido; se envía una muestra, y tras la espera la versión ganadora va al resto (`campaigns:decide-ab`).
- **Automatizaciones** (_Campañas → Automatizaciones_): disparadores «se suscribe», «se agrega una etiqueta» y «fecha de un campo», con pasos de correo, espera, etiquetas y condiciones. Las ejecuta `automations:run` cada minuto.

Ambos comandos los corre el _scheduler_ (`php artisan schedule:run` cada minuto en cron) y requieren Horizon activo.

## Primer administrador en un servidor nuevo

No existe registro público: los usuarios los invita un administrador. En una instalación nueva:

```bash
php artisan migrate --force && php artisan db:seed --force
```

```bash
php artisan app:create-admin admin@tu-dominio.org --name="Nombre Apellido"
```

El comando crea la organización si no existe, asigna el rol de administrador y muestra un enlace de un solo uso para definir la contraseña. Al entrar, se exigirá activar la verificación en dos pasos.

## Seguridad (resumen)

- Inicio de sesión con límite de intentos: 5/min por correo+IP, **bloqueo de 15 min** tras 10 fallos por cuenta, 30/min por IP.
- 2FA (TOTP) obligatoria para administradores; códigos de recuperación.
- Roles: Administrador, Editor, Solo lectura (permisos en `app/Enums`).
- Content-Security-Policy estricta con _nonce_ (sin `unsafe-inline` ni `unsafe-eval` en scripts) y cabeceras de endurecimiento.
- Registro de auditoría (`audit_logs`) de accesos, cambios de usuarios y 2FA; visible en **Administración → Auditoría**.
- Todas las tablas principales llevan `organization_id` con _scope_ global (preparado para multi-tenant).

## Despliegue

La guía de despliegue en Debian 13 con Apache + PHP-FPM, Supervisor y respaldos se entrega en la Fase 7 (`docs/DESPLIEGUE.md`).
