# VNV Events — guía principal para agentes y desarrolladores

Este archivo es el punto de entrada para trabajar en este repositorio con
Claude Code, Codex u otro agente. Resume el contexto vigente, las reglas de
seguridad y la arquitectura, pero no reemplaza los documentos especializados
de `docs/`.

Actualizado: 2026-07-27.

### Manager availability and pre-CRM Lead Intake (2026-08-13)

VNV Events now has a shared Main Manager availability engine. Lead Intake is a
separate pre-CRM queue for ManyChat; it does not insert contacts into CRM
silently. Orders reuse their existing event date/start/end and add Main Manager
and setup metadata. The engine enforces setup time, overlap, declared Level 4
availability and a default 180-minute Transition Time in both directions. First
payment is rechecked before any provider charge. See
`docs/VNV_MANAGER_AVAILABILITY_AND_LEAD_INTAKE.md` and run
`db/20260813_vnv_manager_availability_lead_intake.sql`.

## 1. Identidad y contexto vigente

Este repositorio debe tratarse primero como **VNV Events**:

```text
Marca pública: VNV Events
Dominio: https://vnvevents.com
Repositorio web: morer62/VNV_venues / morer62/vnv-events según el remoto activo
Owner operativo habitual: id_owner = 2
Business user habitual: id_user_business = 2
Site key público: vnvevents
```

VNV Events es un negocio independiente de producción y servicios para eventos.
No es una pantalla genérica de Ophyra y no debe mostrarse públicamente como
Avomeal, Ophyra, VNV Gourmet ni Planner Hub.

El código conserva módulos y documentos heredados de otros proyectos:

- **Ophyra** es la plataforma padre y referencia arquitectónica.
- **VNV Events** es el negocio de eventos activo en este repositorio.
- **Avomeal** es una marca de alimentos/Store relacionada, con
  `site_key = avomeal`.
- **Jonnys Media** es otra marca independiente.
- Las aplicaciones móviles son clientes separados del backend compartido.

`docs/AGENTS.md` y `docs/NEXT_AGENT_HANDOFF.md` contienen una etapa anterior
enfocada en Avomeal. Sus reglas de aislamiento de datos siguen siendo valiosas,
pero su declaración de que Avomeal es el contexto principal no aplica a este
repositorio VNV Events. Ante contradicción, usar esta prioridad:

1. solicitud actual del usuario;
2. este `CLAUDE.md`;
3. `docs/VNV_EVENTS_PROJECT_CONTEXT.md`;
4. documento especializado del módulo;
5. documentación histórica de Avomeal/Ophyra.

## 2. Propósito del sistema

VNV Events cubre el ciclo operativo completo:

- captación de prospectos y CRM;
- clientes;
- estimados, órdenes y subórdenes;
- servicios, contratos, firmas, archivos y pagos;
- calendario y ejecución del evento;
- equipo, asignaciones, reloj, geolocalización y nómina;
- chat, tickets, RSVP, invitaciones y sesiones musicales;
- Store y operaciones relacionadas cuando corresponda;
- CMS, blog, páginas de locaciones, SEO y foros;
- Growth Hub, creación de contenido y distribución social;
- agentes de IA con revisión humana;
- edición, transcripción y render de video.

## 3. Niveles de usuario

### Level 1 — propietario/administrador

Administra operaciones, órdenes, clientes, equipo, pagos, contratos, CMS,
agentes, aprobaciones, reportes y Growth Hub.

### Level 4 — team member

Ve trabajos asignados, calendario, archivos autorizados, chat, horas y reloj.
El clock-in puede requerir geolocalización.

### Level 5 — cliente

Ve solamente sus órdenes, contratos, pagos, archivos, invitaciones y áreas
privadas autorizadas. Puede entrar al área de ejecución mediante código.

### Level 6 — marketing/CMS

Opera contenido, campañas, CMS y SEO cuando el módulo lo habilita.

No asumir que Level 2 o Level 3 forman parte del flujo activo de VNV Events sin
comprobar el código y la tabla correspondiente.

## 4. Arquitectura técnica

```text
PHP + Twig + MySQL/MariaDB
Router y kernel propios
Composer/PSR-4: App\ -> src/
Frontend progresivo con JavaScript
Playwright para recorridos de navegador
FFmpeg para video
Cloudinary/AWS según el flujo de archivos
Stripe, Square, PayPal y DocuSign según configuración
```

Directorios principales:

```text
src/Repositories/   acceso a datos
src/Services/       lógica de negocio e integraciones
src/Utils/          utilidades compartidas
src/views/          controladores y plantillas por ruta
src/views/api/      API móvil, webhooks y acciones asíncronas
src/cron/           workers y tareas programadas
public/             entrada web, assets y recursos públicos
storage/            almacenamiento local no público
db/                 SQL manual y migraciones específicas
docs/               contratos y conocimiento del proyecto
tools/              pruebas y utilidades de desarrollo
test-results/       evidencias de QA; no son código productivo
```

Patrón habitual de una ruta:

```text
src/views/.../index.php   controlador con Router
src/views/.../index.twig  interfaz
Repository               consultas con scope
Service                  reglas e integración externa
```

Antes de cambiar `src/Kernel.php`, Router, autenticación, `BaseRepository`,
`UserRepository`, pagos o contratos de API móvil, leer el documento específico
y verificar todos los consumidores.

## 5. Reglas críticas de datos y multi‑marca

Nunca leer ni modificar datos globales sin scope. Verificar qué columna aplica:

```text
id_owner
id_user_business
business_id
company_id
site_key
site_visibility
```

Regla conceptual:

```text
ownership != visibilidad pública
```

`id_owner = 2` identifica normalmente al propietario operativo. No significa
que cada registro del owner 2 deba aparecer públicamente en VNV Events.

Claves públicas:

```text
VNV Events   -> vnvevents
Avomeal      -> avomeal
Jonnys Media -> jonnysmedia
```

Solo compartir contenido cuando el registro use explícitamente un scope
documentado como `shared`, `global` o `all_sites`.

Si una tabla compartida no tiene columna de scope:

1. detener la escritura expansiva;
2. documentar el riesgo;
3. revisar el schema y sus consumidores;
4. proponer SQL manual;
5. no inventar ownership ni migrar datos silenciosamente.

Lectura obligatoria: `docs/DATABASE_SCOPE.md`,
`docs/SITE_SCOPE_MODEL.md`, `docs/SITE_SCOPE_AND_VISIBILITY.md` y
`docs/USER_COMPANY_ACCESS_MODEL.md`.

## 6. Órdenes, pagos y reloj

Los formularios sensibles de órdenes y reloj usan envío asíncrono protegido:

- bloquear doble envío;
- mostrar progreso;
- interpretar la respuesta real del servidor;
- presentar una alerta clara;
- redirigir explícitamente al terminar.

En JavaScript usar:

```js
form.getAttribute('action')
```

No usar `form.action` cuando existe un campo llamado `action`, porque puede
quedar sombreado y causar pantallas blancas o destinos incorrectos.

Flujos que requieren prueba integral cuando se modifican:

1. crear estimado/orden;
2. editar orden;
3. crear y editar suborden;
4. agregar servicios/items;
5. cambiar estado;
6. agregar archivos;
7. firma y pago en sandbox;
8. asignación al equipo;
9. clock-in y clock-out Level 4.

No probar tarjetas de Stripe si las credenciales activas son de producción.
Usar `4242 4242 4242 4242` únicamente cuando el entorno esté confirmado como
Stripe test/sandbox. Nunca registrar secretos ni datos completos de tarjeta.

Leer `docs/ORDER_ACCESS_PAYMENT_FLOWS.md`,
`docs/ORDERS_AND_TEAM_WORKFLOW.md`, `docs/PAYMENT_AND_SMTP_SCOPE.md`,
`docs/TEAM_CHAT_DELIVERY_OPERATIONS.md` y
`docs/TEAM_MEMBER_CONTRACTS.md`.

## 7. Área privada de ejecución del evento

El área de ejecución permite acceso por un código corto asociado al evento.
Sus participantes pueden, según rol:

- entrar a una lista de karaoke;
- escribir manualmente canción e intérprete;
- crear solicitudes al DJ;
- dejar propina cuando esté disponible;
- subir fotografías con nombre del autor y comentario opcional;
- eliminar sus propias entradas/fotos;
- consultar actualizaciones de canciones en tiempo real.

Cliente contratante, team members y Level 1 tienen accesos adicionales según el
evento. El administrador/DJ puede moderar todas las solicitudes. Las fotos
tienen límites y retención documentados.

SQL: `db/event_execution_area_required.sql`.
Contrato: `docs/EVENT_EXECUTION_AREA.md`.

## 8. CMS, Growth Hub y rutas públicas

Los tipos canónicos de Growth Hub son:

```text
cms_contents.content_type = page | location | blog
cms_routes.route_type      = page | location | blog
```

Campos legacy como `cms_contents.type = page | post` pueden mantenerse por
compatibilidad, pero no deben reemplazar el contrato nuevo.

Prioridad de resolución pública:

1. páginas físicas existentes;
2. rutas y páginas CMS locales;
3. contenido Growth Hub publicado para el `site_key` activo;
4. categorías/productos dinámicos;
5. 404.

Antes de publicar:

- comprobar colisiones en rutas físicas y CMS;
- comprobar prefijos reservados;
- verificar `site_key`, idioma, estado y aprobación;
- usar el dominio público en canonical, schema y breadcrumbs;
- no confiar solamente en el sitemap;
- no sobrescribir una página existente automáticamente.

El generador puede usar una página o artículo base con búsqueda/autocomplete,
pero el resultado siempre debe pasar por revisión humana.

Leer:

- `docs/GROWTH_HUB_CMS_REPLICATION_GUIDE.md`
- `docs/GROWTH_HUB_PUBLIC_CONSUMPTION.md`
- `docs/GROWTH_HUB_PUBLIC_RENDERING_REPORT.md`
- `docs/OPHYRA_GROWTH_HUB_TEMPLATE_INTEGRATION.md`
- `docs/OPHYRA_CMS_ROUTE_CONTRACT_REVIEW_2026_06_09.md`
- `docs/VNV_EVENTS_UNIFIED_CMS.md`
- `docs/CMS_AND_PUBLIC_CONTENT.md`

## 9. Contenido y agentes de IA

Principio obligatorio:

```text
La IA prepara; una persona revisa; una acción separada publica, envía o ejecuta.
```

No publicar automáticamente artículos, páginas, carruseles o posts. No enviar
estimados, recordatorios o mensajes solo por aprobar un borrador.

El sistema incluye o proyecta agentes para:

- Blog Writer;
- Social Publisher;
- Instagram Carousel;
- Short Video;
- Meta Lead Estimator;
- Estimate Follow-up;
- Event Brief;
- Lead Qualification;
- Contract and Order Auditor;
- Content Refresh;
- Post-Event;
- Review and Reputation;
- Operations Risk;
- Client Concierge.

El área común de aprobaciones debe permitir:

- revisar el resultado;
- editar manualmente;
- enviar instrucciones de corrección;
- regenerar con OpenAI, Claude o Gemini cuando esté configurado;
- conservar versiones;
- aprobar;
- ejecutar/publicar mediante una acción final independiente;
- registrar errores e intentos.

Credenciales sociales son por red/cuenta y deben almacenarse de forma segura.
Facebook e Instagram usan Meta; LinkedIn y YouTube tienen conectores propios.
Los webhooks deben validar firma, token, idempotencia y owner.

Documentación:

- `docs/VNV_EVENTS_AI_AGENTS_ROADMAP.md`
- `docs/AI_CONTENT_ASSISTANT.md`
- `docs/AI_CONTENT_APPROVAL_WORKFLOW.md`
- `docs/AI_CONTENT_SETTINGS.md`
- `docs/AI_CONTENT_CRON.md`
- `docs/AI_CONTENT_SEO_SCHEMA_RULES.md`

SQL principal de esta fase: `db/vnv_ai_agents_required.sql`. No asumir que fue
ejecutado: verificar schema antes de depender de tablas o columnas.

## 10. Video Studio y archivos grandes

Video Studio administra proyectos reutilizables con:

- originales;
- transcripción y subtítulos;
- limpieza por texto y cortes;
- captions cinéticos;
- formatos vertical, horizontal, cuadrado, 4:5 y 4K;
- zooms, transiciones, color, overlays y motion graphics;
- intros, outros, logos, música y B-roll;
- planes de edición por IA;
- revisiones y render final;
- futura distribución social.

### Originales de 500 MB a 10 GB

No deben atravesar la subida del navegador/PHP. Usar la bandeja SFTP privada:

```env
VIDEO_INGEST_PATH=storage/video-ingest/incoming
VIDEO_INGEST_STABLE_SECONDS=60
FFMPEG_PATH=/usr/bin/ffmpeg
```

The path is resolved from the repository root, not from `APP_URL` and not from
hardcoded hosting account paths. El sistema agrega `owner-{id}` y lista las
carpetas visibles dentro de ese scope. Flujo:

1. crear una carpeta por proyecto vía SFTP;
2. subir como `archivo.mp4.uploading`;
3. renombrar a `archivo.mp4` al terminar;
4. esperar la ventana de estabilidad;
5. importar la carpeta desde Level 1.

Las referencias privadas usan `vnv-local://`; no exponer la ruta como URL
pública. La transcripción extrae audio comprimido en segmentos de 20 minutos y
el render lee el original directamente desde disco.

Mantener libre al menos cuatro veces el tamaño del original. Para un archivo de
10 GB, 40 GB es el mínimo y 60–80 GB es una reserva operativa más segura.

### Biblioteca de producción recomendada

```text
owner-2/
├── _library/
│   ├── logos/
│   ├── intros/
│   ├── outros/
│   ├── music/
│   ├── sound-effects/
│   ├── overlays/
│   ├── fonts/
│   └── b-roll/
└── projects/
    └── project-name/
        ├── source/
        ├── b-roll/
        ├── images/
        ├── music/
        ├── voice-over/
        └── exports/
```

La bandeja importa los originales de `source/` y usa el resto de las
subcarpetas como catálogo vivo del proyecto. Level 1 muestra rol, tipo, tamaño
y estado de cada archivo; permite referenciarlo por nombre en las instrucciones
de IA y ofrece los recursos compatibles en los selectores de intro, outro,
logo, overlay y audio. El catálogo no requiere registrar cada complemento en
la base de datos.

Consultar `docs/VNV_VIDEO_RENDER_PRODUCTION.md`.

## 11. API móvil y compatibilidad

Las apps móviles dependen de contratos existentes de:

- login/signup;
- token y sesión;
- WebView y `Panel/Tokenapi`;
- navegación por nivel;
- órdenes/clientes/chat;
- Expo push notifications;
- reloj y ubicación;
- Store/delivery cuando corresponda.

No cambiar nombres, tipos o estructura JSON sin revisar los consumidores.
Mantener compatibilidad hacia atrás cuando sea posible.

Leer:

- `docs/MOBILE_APPS_ECOSYSTEM.md`
- `docs/MOBILE_API_NOTIFICATIONS_FLOW.md`
- `docs/MOBILE_APP_BROADCAST_NOTIFICATIONS.md`
- `docs/VNV_EVENTS_MOBILE_APP_WEBVIEW_CONTRACT_2026_06_09.md`
- `docs/VNV_EVENTS_MOBILE_NAVIGATION_AND_CLOCK_CONTRACT.md`

## 12. Email, branding y configuración

Todo correo visible de VNV Events debe usar VNV Events como remitente y marca.
No debe decir “Welcome to Avomeal” u Ophyra salvo que el flujo pertenezca
realmente a esa marca.

Valores SMTP con espacios deben ir entre comillas:

```env
MAIL_FROM_EMAIL=no-reply@vnvevents.com
MAIL_FROM_NAME='VNV Events and Gourmet'
```

No copiar secretos al código, documentación, logs, capturas o commits. No leer
ni mostrar el contenido completo de `.env` cuando no sea estrictamente
necesario. Usar `.env.example` para documentar nombres y valores seguros.

## 13. Dependencias

Dependencias PHP se definen en `composer.json`; frontend en `package.json`.

Reglas:

- no ejecutar `composer update` por rutina;
- para desplegar dependencias ya bloqueadas, preferir
  `composer install --no-dev --optimize-autoloader`;
- ejecutar `composer update` solo cuando se cambia deliberadamente una versión,
  revisando `composer.lock` y regresiones;
- cambios de PHP/Twig propios no requieren Composer;
- FFmpeg es un binario del servidor, no un paquete Composer;
- `npm install` solo es necesario si faltan dependencias bloqueadas;
- no editar `vendor/` ni `node_modules/` manualmente.

## 14. SQL y migraciones

El proyecto usa SQL manual en `db/` además de Phinx. Nunca asumir que un SQL ya
fue aplicado en producción.

Antes de entregar SQL:

1. inspeccionar el schema actual;
2. identificar dependencias y duplicados;
3. hacer el script repetible cuando sea viable;
4. evitar pérdida de datos;
5. listar claramente los archivos y el orden;
6. separar SQL requerido de SQL opcional;
7. no ejecutarlo en producción sin autorización.

SQL relevante:

```text
db/event_execution_area_required.sql
db/vnv_ai_agents_required.sql
db/ai_content_assistant_required.sql
db/ai_content_assistant_vnv_events_seed.sql
db/team_member_contracts_required.sql
db/client_payment_methods_auto_charge_consents_and_manual_charges.sql
db/20260606_vnv_events_cms_origin_metadata.sql
db/20260624_cms_category_applicability.sql
db/20260624_cms_category_featured_images.sql
db/20260629_cms_content_type_backfill.sql
```

La ingestión SFTP de videos no requiere SQL adicional.

## 15. Método de trabajo seguro

Antes de modificar:

1. leer este archivo;
2. leer el documento específico;
3. inspeccionar rutas, repositorios, schema y consumidores;
4. revisar el estado Git y preservar cambios existentes;
5. definir scope de owner/site/role;
6. identificar efectos externos.

Durante la implementación:

- mantener controladores delgados;
- colocar queries en repositorios y reglas en servicios;
- usar consultas parametrizadas;
- validar autorización en servidor, no solo en Twig/JavaScript;
- proteger contra traversal, SSRF, archivos maliciosos y doble envío;
- mantener acciones externas idempotentes;
- no mezclar refactors amplios con correcciones sensibles;
- no eliminar módulos heredados sin confirmar uso.

Después:

1. `php -l` en cada PHP modificado;
2. `git diff --check`;
3. pruebas unitarias/smoke disponibles;
4. recorrido Playwright/Chromium proporcional al riesgo;
5. revisar consola, redirecciones y errores del servidor;
6. probar los roles afectados;
7. documentar cambios, variables, SQL y pendientes reales.

No declarar un flujo listo solamente porque devuelve HTTP 200.

## 16. Pruebas de navegador

Herramientas existentes:

```text
tools/e2e_ai_agents.js
tools/e2e_agent_operations.js
tools/e2e_video_editor.js
tools/video_render_smoke.php
```

Los resultados quedan bajo `test-results/`. No usar credenciales de producción
para operaciones irreversibles. En pruebas multiusuario verificar siempre:

- Level 1;
- varios Level 5 en un mismo evento;
- Level 4/DJ;
- aislamiento entre eventos y owners;
- tiempo real;
- permisos para editar/eliminar;
- límites de archivos;
- doble clic y reintentos;
- errores de red;
- estados vacíos y datos grandes.

## 17. Seguridad y acciones externas

Requieren especial cuidado:

- pagos y cobros automáticos;
- envío de correo, WhatsApp o DM;
- publicación social;
- aprobación/envío de estimados;
- firma de contratos;
- webhooks;
- eliminación de usuarios, archivos o contenido;
- cambios de permisos;
- SQL destructivo.

Una aprobación de contenido no equivale a autorizar el envío o publicación.
Mantener esas acciones separadas y auditables.

Para archivos:

- validar MIME real, extensión, tamaño y propietario;
- almacenar originales grandes fuera del web root;
- generar acceso temporal/proxy cuando el navegador necesite vista previa;
- no construir rutas desde input sin normalización;
- aplicar retención documentada;
- nunca exponer rutas privadas del servidor a usuarios no autorizados.

## 18. Estado y riesgos conocidos

- Existe herencia documental de Avomeal/Ophyra; confirmar identidad antes de
  cambiar copy, rutas o queries.
- Hay módulos legacy Level 2/3 que no deben eliminarse a ciegas.
- Los cambios de órdenes, pagos, reloj y API móvil son sensibles.
- El CMS debe proteger rutas físicas y contenido preexistente.
- Las integraciones sociales dependen de credenciales y webhooks de producción.
- La IA siempre debe conservar aprobación humana.
- Los videos de 10 GB requieren capacidad de disco, workers y FFmpeg adecuados.
- Los assets SFTP dependen de conservar la estructura de carpetas y permisos
  privados; las vistas previas proxy de originales grandes siguen siendo una
  mejora independiente del catálogo.

Usar `docs/VNV_EVENTS_LAUNCH_QA_CHECKLIST.md` como puerta final y
`docs/VNV_EVENTS_LAUNCH_READINESS.md` como contexto de lanzamiento.

## 19. Índice documental

### Entrada y contexto

- `docs/VNV_EVENTS_PROJECT_CONTEXT.md`
- `docs/ECOSYSTEM_OVERVIEW.md`
- `docs/VNV_EVENTS_RELATIONSHIP.md`
- `docs/AGENTS.md` — contexto Avomeal heredado; aplicar con la prioridad indicada.
- `docs/NEXT_AGENT_HANDOFF.md` — handoff histórico.
- `docs/AVOMEAL_CONTEXT.md`
- `docs/AVOMEAL_PROJECT_CONTEXT.md`
- `docs/JONNYS_MEDIA_PROJECT_CONTEXT.md`
- `docs/OPHYRA_BUSINESS_MODEL.md`
- `docs/OPHYRA_FUTURE_INTEGRATION.md`

### Acceso, datos y operación

- `docs/DATABASE_SCOPE.md`
- `docs/USER_COMPANY_ACCESS_MODEL.md`
- `docs/ADMIN_USER_MODULE_REPORTS_CONTROL.md`
- `docs/ORDER_ACCESS_PAYMENT_FLOWS.md`
- `docs/ORDERS_AND_TEAM_WORKFLOW.md`
- `docs/CLIENT_FLOW.md`
- `docs/TEAM_CHAT_DELIVERY_OPERATIONS.md`
- `docs/TEAM_MEMBER_CONTRACTS.md`
- `docs/OPHYRA_ORDERS_CALENDAR_VIEW.md`
- `docs/EVENT_EXECUTION_AREA.md`

### Store y Avomeal

- `docs/STORE_MODEL.md`
- `docs/STORE_COMMERCE_FLOW.md`
- `docs/PRODUCT_MODEL.md`
- `docs/PRODUCT_VISIBILITY.md`
- `docs/SUBSCRIPTIONS.md`
- `docs/SUBSCRIPTIONS_AND_ORDERS.md`
- `docs/SITE_SCOPE_MODEL.md`
- `docs/SITE_SCOPE_AND_VISIBILITY.md`
- `docs/PAYMENT_AND_SMTP_SCOPE.md`
- `docs/AVOMEAL_OPHYRA_INTEGRATION_MODEL.md`
- `docs/AVOMEAL_LAUNCH_AUDIT.md`
- `docs/AVOMEAL_LAUNCH_CHECKLIST.md`
- `docs/AVOMEAL_FINAL_QA_NOTES.md`

### CMS, contenido y SEO

- `docs/CMS_AND_PUBLIC_CONTENT.md`
- `docs/GROWTH_HUB_CMS_REPLICATION_GUIDE.md`
- `docs/GROWTH_HUB_PUBLIC_CONSUMPTION.md`
- `docs/GROWTH_HUB_PUBLIC_RENDERING_REPORT.md`
- `docs/OPHYRA_GROWTH_HUB_TEMPLATE_INTEGRATION.md`
- `docs/OPHYRA_CMS_ROUTE_CONTRACT_REVIEW_2026_06_09.md`
- `docs/VNV_EVENTS_UNIFIED_CMS.md`
- `docs/VNV_EVENTS_SEO_AI_CENTER.md`
- `docs/VNV_EVENTS_PUBLIC_FORUMS.md`
- `docs/HOMEPAGE_POSITIONING.md`
- `docs/AI_CONTENT_ASSISTANT.md`
- `docs/AI_CONTENT_APPROVAL_WORKFLOW.md`
- `docs/AI_CONTENT_SETTINGS.md`
- `docs/AI_CONTENT_CRON.md`
- `docs/AI_CONTENT_SEO_SCHEMA_RULES.md`

### Agentes y video

- `docs/VNV_EVENTS_AI_AGENTS_ROADMAP.md`
- `docs/VNV_VIDEO_RENDER_PRODUCTION.md`

### Mobile y notificaciones

- `docs/MOBILE_APPS_ECOSYSTEM.md`
- `docs/MOBILE_API_NOTIFICATIONS_FLOW.md`
- `docs/MOBILE_APP_BROADCAST_NOTIFICATIONS.md`
- `docs/VNV_EVENTS_MOBILE_APP_WEBVIEW_CONTRACT_2026_06_09.md`
- `docs/VNV_EVENTS_MOBILE_NAVIGATION_AND_CLOCK_CONTRACT.md`

### Lanzamiento, QA y soporte

- `docs/VNV_EVENTS_LAUNCH_READINESS.md`
- `docs/VNV_EVENTS_LAUNCH_QA_CHECKLIST.md`
- `docs/OPHYRA_COMMERCIAL_LAUNCH_READINESS.md`
- `docs/OPHYRA_IMMEDIATE_LAUNCH_TASKS.md`
- `docs/OPHYRA_FINAL_STABILIZATION_QA.md`
- `docs/OPHYRA_SUPPORT_TEAM_PLAYBOOK.md`
- `docs/OPHYRA_BILLING_AUTOMATION.md`
- `docs/OPHYRA_PUBLIC_CONVERSION_FUNNEL.md`

## 20. Regla final

Antes de decidir, identificar siempre:

```text
¿Qué marca?
¿Qué owner/company?
¿Qué site_key?
¿Qué nivel de usuario?
¿Qué módulo y consumidor?
¿Es borrador, aprobación o ejecución final?
¿Qué documento define el contrato?
```

Si una respuesta no está clara, investigar el código y el schema antes de
escribir. Preservar la identidad de VNV Events, el aislamiento de datos, la
compatibilidad móvil y la aprobación humana.
