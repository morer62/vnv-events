# AGENTS.md - VNV Events Agent Entry Point

Este archivo es la puerta de entrada para cualquier agente, desarrollador o IA que trabaje en este repositorio.

## Contexto actual obligatorio

Este repositorio debe tratarse primero como **VNV Events**, no como Ophyra SaaS generico.

VNV Events es la empresa real de eventos que opera publicamente en:

```text
https://vnvevents.com
```

Ophyra, Avomeal/VNV Gourmet, VNV Venues y Planner Hub pueden aparecer en rutas, documentos o codigo heredado. Esas referencias son contexto historico o tecnico salvo que una tarea pida tocar explicitamente esos sistemas.

Para trabajo de lanzamiento en este repo, prioriza:

* Level 1: dueno/admin de VNV Events.
* Level 4: team members.
* Level 5: clientes y usuarios publicos registrados.
* Level 6: CMS/marketing cuando aplique.
* Ordenes, solicitudes de eventos, clientes, equipo, chat, payroll/time clock, CMS, landing pages, locaciones, blog, foros, catalogo/store, sesiones musicales, SEO Center y archivos publicos SEO/AI.

La documentacion completa vive dentro de `/docs`. Antes de tocar codigo, rutas, endpoints, vistas, base de datos, mobile apps o branding, lee este archivo y luego abre los documentos relevantes dentro de `/docs`.

## Idea central

Ophyra es el proyecto padre/original y la referencia estructural del ecosistema, pero no todos los proyectos derivados deben tratarse como si fueran el mismo producto.

Estos proyectos comparten historia, patrones tecnicos, codigo base o parte de la arquitectura, pero representan marcas y plataformas distintas:

| Proyecto | Repo / dominio | Rol en el ecosistema |
| --- | --- | --- |
| VNV Events | Este repo, `morer62/vnv-events`, `vnvevents.com` | Empresa de eventos, servicios, clientes, ordenes, equipo y ejecucion operativa. |
| Ophyra | Referencia heredada/relacionada | Plataforma modular de operaciones y referencia central. |
| Avomeal / VNV Gourmet | `morer62/VNV_Gourmet`, `avomeal.com` | Marca de comida, delivery, meals, kits gastronomicos, productos, ordenes y clientes. |
| Jonnys Media | `jonnys.media` | Marca personal/profesional del desarrollador y lider tecnico del ecosistema. |
| Avomeal mobile app | `morer62/vnv-gourmet-app` | App Expo/React Native conectada a Avomeal/VNV Gourmet. |
| VNV Events mobile app | `morer62/vnv-mobile-app` | App Expo/React Native conectada a VNV Events. |

No trates todos estos repositorios como un solo producto. Ophyra puede funcionar como referencia, central administrativa o base compartida, pero VNV Events, Avomeal y Jonnys Media mantienen identidad, branding, dominio, navegacion, experiencia visual y proposito de negocio propios.

## Orden de lectura recomendado

1. `docs/VNV_EVENTS_LAUNCH_READINESS.md` cuando el trabajo sea sobre este repositorio VNV Events.
2. `docs/README.md`
3. `docs/ECOSYSTEM_OVERVIEW.md`
4. `docs/OPHYRA_BUSINESS_MODEL.md`
5. Documento especifico del proyecto que vas a tocar:
   - `docs/VNV_EVENTS_PROJECT_CONTEXT.md`
   - `docs/AVOMEAL_PROJECT_CONTEXT.md`
   - `docs/JONNYS_MEDIA_PROJECT_CONTEXT.md`
   - `docs/MOBILE_APPS_ECOSYSTEM.md`
6. Documentos de flujo segun el area:
   - `docs/USER_COMPANY_ACCESS_MODEL.md`
   - `docs/STORE_COMMERCE_FLOW.md`
   - `docs/TEAM_CHAT_DELIVERY_OPERATIONS.md`
   - `docs/MOBILE_API_NOTIFICATIONS_FLOW.md`
   - `docs/ADMIN_USER_MODULE_REPORTS_CONTROL.md`
   - `docs/OPHYRA_COMMERCIAL_LAUNCH_READINESS.md`
   - `docs/OPHYRA_IMMEDIATE_LAUNCH_TASKS.md`
   - `docs/OPHYRA_BILLING_AUTOMATION.md`
   - `docs/OPHYRA_SUPPORT_TEAM_PLAYBOOK.md`
   - `docs/ORDER_ACCESS_PAYMENT_FLOWS.md`

## Modelo de usuarios por proyecto

En Ophyra, el modelo actual incluye:

* Level 1: super admin / Ophyra Global Admin / operaciones centrales.
* Level 2: Business Owner / Account Owner.
* Level 3: legacy.
* Level 4: team member / empleado / colaborador.
* Level 5: cliente final.
* Level 6: marketing / CMS / funciones especificas.

En proyectos derivados como VNV Events, Avomeal / VNV Gourmet y otras marcas independientes, la estructura fue simplificada:

* Level 1: dueno/admin principal de la marca.
* Level 4: team member / empleado / colaborador.
* Level 5: cliente.
* Level 6: usuario relacionado con marketing o funciones especificas.

En esos proyectos derivados, no asumas que Level 2 y Level 3 siguen siendo parte activa del flujo principal. Pueden existir por herencia historica, pero normalmente se ignoran o quedan como legacy.

## Reglas de identidad

* Ophyra no es VNV Events.
* Ophyra no es Avomeal.
* VNV Events no es Avomeal.
* Jonnys Media no es un cliente generico del sistema.
* Las apps moviles no contienen la logica central del negocio; normalmente conectan login, signup, dashboard, WebView, token de sesion y notificaciones con el backend/web.
* El signup web pertenece al flujo comercial de Ophyra y sirve para registrar nuevos negocios/clientes comerciales. El signup movil no pertenece al flujo comercial de Ophyra; existe unicamente para registrar o asociar clientes finales de una marca especifica, usando `id_user_business` o el identificador equivalente. Ophyra debe mantenerse como plataforma web para registro, billing, membresias, modulos y afiliados.
* Billing Automation debe tratarse como un sistema integrado: Stripe webhook, renovacion de Ophyra Base, renovacion de add-ons, fallos de pago/dunning, `payments_all`, comisiones afiliadas, logs e intervencion manual de Level 1. No implementar esas piezas como parches separados.

Cuando trabajes en una marca, respeta su dominio, lenguaje, visuales, rutas, publico y flujo operativo.

## Reglas tecnicas criticas

Antes de modificar, confirma:

* que modulo o marca estas tocando,
* que usuario/nivel lo usa,
* que tabla o endpoint afecta,
* si hay impacto en mobile/WebView,
* si hay impacto en pagos,
* si hay impacto en clientes, ordenes o chat,
* si el cambio afecta una marca distinta,
* si la respuesta JSON ya es consumida por una app movil.

No modificar sin justificacion fuerte:

* Kernel / Router,
* `BaseRepository`,
* `UserRepository`,
* login/auth,
* endpoints API usados por mobile,
* pagos,
* Affiliate/referral logic,
* rutas publicas existentes,
* estructura JSON usada por apps moviles.

Si necesitas cambiar una respuesta API usada por mobile, agrega campos nuevos en lugar de eliminar o renombrar campos existentes.

## Principio de trabajo

Cambios pequenos, controlados, reversibles y faciles de probar.

Si tienes duda, lee primero el documento de contexto del proyecto dentro de `/docs` y revisa un modulo similar antes de editar.
