# Multi-tenant, planes y cobros (estructura preparada)

La plataforma sirve hoy a **una sola organización** (AAPOS), pero el diseño de datos permite convertirla en multi-tenant. Este documento describe lo que ya existe y lo que faltaría.

## Lo que ya existe

- **`organization_id` en todas las tablas principales** (contactos, audiencias, suscripciones, etiquetas, campañas, destinatarios, eventos, segmentos, automatizaciones, conexiones, webhooks, auditoría…).
- **Scope global** `OrganizationScope` (trait `BelongsToOrganization`): toda consulta Eloquent filtra por la organización activa y todo registro nuevo la recibe automáticamente.
- **Organización activa** (`CurrentOrganization`): se toma del usuario autenticado (panel y API) o se fija con `runAs()` en trabajos en cola, comandos y webhooks entrantes.
- **Roles por organización** (spatie/laravel-permission en modo equipos, `team_foreign_key = organization_id`).
- **Límites por organización** en `organizations.limits` (JSON), editables en _Administración → Organización_:

| Clave                        | Efecto                                                                                                                       |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `contacts`                   | Al alcanzarlo no se crean contactos nuevos (panel, importación, formularios, API).                                           |
| `monthly_sends`              | Contador atómico mensual (`organization_monthly_usage`); al agotarse, las campañas se pausan y las automatizaciones esperan. |
| `daily_sends_per_connection` | Tope que se aplica al límite diario de cada conexión de envío al guardarla.                                                  |

- **Panel de uso** (página _Uso_ del menú) y alertas en el inicio al 80 % y al 100 %.
- Pruebas de aislamiento entre organizaciones (`tests/Feature/Tenancy`, `tests/Feature/Api`).

## Lo que faltaría para vender a varias organizaciones

1. **Registro de organizaciones** (alta, verificación de dominio de envío por organización) y un rol de superadministrador fuera de las organizaciones.
2. **Planes**: tabla `plans` (nombre, precio, límites por defecto) y `organizations.plan_id`; al cambiar de plan se copian sus límites a `organizations.limits` (los límites individuales siguen permitiendo excepciones).
3. **Cobros**: integrar una pasarela (por ejemplo, Stripe con Laravel Cashier) sobre `organizations` como entidad facturable; webhooks de la pasarela → cambio de plan, suspensión por impago.
4. **Suspensión**: un estado `organizations.status` que bloquee envíos y la API sin borrar datos.
5. **Aislamiento adicional** (opcional): colas y conexiones de envío dedicadas por organización grande.
6. **Rutas públicas por organización**: hoy los formularios (`/f/{slug}`), el rastreo y las bajas resuelven la organización desde el registro (formulario, destinatario), así que ya funcionan con varias organizaciones; habría que revisar los dominios personalizados.

No se implementan cobros ni pasarela de pago en esta versión (decisión del Módulo 7).
