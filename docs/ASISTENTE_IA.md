# Asistente de redacción con IA

Revisa el borrador de una campaña mientras se escribe: ortografía, tono, claridad, spam, coherencia entre asunto y cuerpo, y el método Smart Brevity. **Solo recomienda**: nunca impide guardar ni enviar, y no cambia el checklist de envío.

## Dónde se configura

_Configuración → Asistente de IA_ (`/admin/ai`). Solo para quien tiene el permiso de configuración (administradores); pide confirmar la contraseña.

1. Pega la API key de Anthropic (consola de Anthropic → _API keys_) y pulsa **Guardar key**. Se verifica de inmediato.
2. Elige el modelo y activa el asistente.
3. Ajusta las categorías, Smart Brevity, la guía de tono y los límites.

La key:

- se guarda **cifrada** en la base de datos (con la `APP_KEY` de la aplicación);
- **nunca** va en `.env`, en el código, en los registros ni en el navegador: la pantalla muestra solo `••••` y los 4 últimos caracteres;
- un campo vacío no la borra; para quitarla está **Eliminar key** (con confirmación);
- la auditoría registra quién la agregó, reemplazó o eliminó, con los 4 últimos caracteres.

**Verificar conexión** consulta el modelo elegido en la API (`GET /v1/models/{modelo}`): no genera texto ni tiene costo. Si una revisión responde 401 o 403, el asistente queda en estado de error hasta volver a verificar.

## Modelos sugeridos

| Modelo                      | Cuándo                                                    |
| --------------------------- | --------------------------------------------------------- |
| `claude-opus-5-5` (defecto) | Mejor calidad de redacción y de sugerencias.              |
| `claude-sonnet-5-5`         | Buen equilibrio entre calidad, velocidad y costo.         |
| `claude-haiku-5-5`          | El más rápido y económico, para revisión en vivo intensa. |

Se usa `effort: low` y respuestas estructuradas (esquema JSON), así que cada revisión es corta.

## Costo y límites

Lo cobra **Anthropic** directamente a la cuenta dueña de la key, por tokens. Una revisión típica envía unas 1,500–4,000 palabras de instrucciones y contenido y recibe pocas decenas de líneas; consulta los precios vigentes del modelo elegido en la página de precios de Anthropic.

Controles de gasto:

- **Revisión en vivo** solo después de una pausa al escribir (`debounce`, 1.8 s por defecto) y solo si el texto cambió al menos _N_ caracteres.
- **Caché de 1 hora**: el mismo contenido con la misma configuración no se vuelve a enviar ni cuenta.
- **Límite por minuto** por usuario (30) y **cupos diario y mensual** por organización. Al agotarse, la revisión en vivo se pausa y el editor lo indica; los chequeos automáticos siguen.
- La pantalla de configuración muestra el uso del día y del mes.

## Qué datos se envían

Solo el borrador que se está editando: asunto, texto de vista previa, el **texto** del cuerpo (sin HTML, máximo 12,000 caracteres) y la lista de enlaces. **No** se envían contactos, listas, direcciones de correo ni datos de envío. Las variables como `{{ nombre }}` se envían sin reemplazar.

El contenido va entre `<contenido>` y `</contenido>` y las instrucciones indican al modelo que es material para revisar, no órdenes (protección contra _prompt injection_). La respuesta también se valida: solo se aceptan hallazgos con categorías permitidas y hasta el máximo configurado.

## Chequeos automáticos (sin IA)

Corren al instante en el navegador y también en el servidor, aun con la IA apagada:

- asunto vacío o todo en mayúsculas; falta el texto de vista previa;
- variables que no existen (`{{ apodo }}`);
- enlaces sin dirección o con `http://`;
- imágenes sin texto alternativo;
- métricas de Smart Brevity.

## Smart Brevity

Método de redacción de Axios para textos cortos y escaneables. El panel muestra:

- palabras del correo frente al máximo configurado (300 por defecto);
- caracteres del asunto frente a ~50;
- palabras promedio por frase (meta: 20 o menos);
- párrafos largos (más de 2 oraciones o 60 palabras);
- si hay viñetas y negritas.

La IA evalúa siete reglas y cada hallazgo trae una reescritura concreta:

1. Asunto corto y concreto, con la palabra más fuerte primero.
2. Primera frase con lo más importante, sin introducciones.
3. «Por qué importa» explícito para el lector.
4. Frases cortas, voz activa, párrafos de 1 o 2 oraciones.
5. Estructura escaneable: etiquetas en negrita, viñetas, un solo mensaje principal.
6. Extensión dentro del máximo; qué cortar o mover a «Ver más».
7. Una sola llamada a la acción, clara.

Se puede apagar para toda la organización (configuración) o solo en el editor de cada persona (**Aplicar Smart Brevity**, se recuerda en el navegador).

## En el editor

El panel _Revisión del contenido_ aparece junto al editor (visual o HTML, y en cada versión de una prueba A/B, con su propio asunto):

- **Revisión en vivo** (si la organización la permite) y **Revisar ahora**;
- hallazgos agrupados por importancia, con el fragmento y una sugerencia con botón **Copiar**;
- mensajes de estado accesibles (`aria-live`).

La revisión usa el asunto y el texto de vista previa **guardados** y el cuerpo **tal como está en el editor**.
