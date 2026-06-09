# AGENTS.md - Avomeal Web Entry Point

Este archivo es la entrada principal para cualquier agente, desarrollador o IA que trabaje en este repositorio.

## Contexto Actual Obligatorio

Este repositorio debe tratarse primero como **Avomeal Web**.

No es Ophyra generico. No es VNV Events completo. Avomeal es la marca/operacion de comida conectada a VNV Events y trabaja sobre la misma base de datos operativa.

## Correccion De Naming

La marca real/publica es **Avomeal**.

**VNV Gourmet** fue un nombre temporal/codename usado antes del lanzamiento. Puede aparecer todavia en rutas tecnicas, assets, carpetas, referencias historicas o repositorios heredados, pero no debe usarse como marca publica ni como nombre principal en textos, metadata, SEO, documentacion operativa o copy visible.

El business ID confirmado para Avomeal en esta estructura es:

```text
id_user_business = 2
AVOMEAL_BUSINESS_USER_ID=2
VNV_EVENTS_OWNER_ID=2
```

Ese ID corresponde al business owner principal de VNV Events y debe usarse para scoping de productos, categorias, carritos, pedidos, pagos, suscripciones, delivery, clientes y reportes de Avomeal.

La visibilidad publica del sitio se controla aparte con:

```text
site_key = avomeal
```

`id_owner = 2` indica ownership/operacion. No significa automaticamente que todo ese contenido deba mostrarse en Avomeal. Para contenido publico, configuracion SMTP/payment provider, CMS, locaciones, foros y catalogo, usar `site_key = avomeal` o un scope compartido documentado (`shared`, `global`, `all_sites`). Ver `docs/SITE_SCOPE_MODEL.md`.

## Identidad Comercial

Avomeal debe explicarse como una marca gastronomica amplia, no solo como meal prep.

Pilares comerciales actuales:

1. **Meal Preps**
   - comidas preparadas,
   - pedidos semanales,
   - productos listos para organizar la semana,
   - posibles suscripciones o pedidos recurrentes.

2. **Holiday Menus**
   - Thanksgiving,
   - Christmas,
   - New Year,
   - Valentine's,
   - Mother's Day,
   - Father's Day,
   - corporate holiday meals,
   - family celebrations.

3. **Party Boxes**
   - appetizers,
   - sweets,
   - party snacks,
   - dessert boxes,
   - themed boxes,
   - small event food kits,
   - dinner kits,
   - celebration boxes.

Tambien puede tener productos sueltos, cenas tematicas, catering-style kits, store checkout y suscripciones.

Regla comercial visible:

```text
$65 minimum order
```

## Relacion Entre Proyectos

Estos proyectos son hermanos y comparten historia, arquitectura, base de datos o patrones:

| Proyecto | Rol |
| --- | --- |
| Ophyra | Plataforma padre/referencia arquitectonica y futura integracion administrativa. |
| VNV Events | Business owner / parent operation para este scope. |
| Avomeal | Store/food brand bajo VNV Events con branding propio. |
| VNV Events mobile app | App conectada a los flujos de eventos/clientes/equipo. |
| Avomeal mobile app | App conectada a clientes, pedidos, store, suscripciones y notificaciones de Avomeal. |

Avomeal conserva su branding publico, pero opera bajo el owner `2`.

## Regla Critica De Base De Datos Compartida

No consultar ni modificar data global sin scope.

Toda operacion relevante debe filtrar por el campo de ownership real:

```text
id_owner
id_user_business
business_id
company_id
```

En este schema actual, `id_owner = 2` identifica ownership/operacion y `site_key = avomeal` identifica visibilidad/configuracion del sitio Avomeal.

Si una tabla no tiene campo de scope, documenta el riesgo antes de modificar. No inventes ownership ni migres datos automaticamente.

## Regla Critica De Rutas CMS

Las paginas publicas existentes tienen prioridad y no deben eliminarse, pisarse ni redirigirse automaticamente cuando Ophyra Growth Hub genere contenido nuevo.

Antes de publicar una ruta CMS, revisar el contrato de rutas, prioridades, API y SQL en:

```text
docs/OPHYRA_CMS_ROUTE_CONTRACT_REVIEW_2026_06_09.md
```

No confiar solamente en el sitemap para decidir si una ruta esta disponible. El sitemap es una senal secundaria; la fuente principal es el inventario de rutas, tablas CMS, rutas fisicas, rutas reservadas y prueba HTTP.

## Areas Activas

Prioriza:

- public Avomeal home,
- Store/catalog,
- products,
- categories,
- meal preps,
- holiday menus,
- party boxes,
- cart,
- checkout,
- orders,
- subscriptions,
- customers,
- payments,
- delivery zones/tracking,
- SEO,
- mobile app compatibility.

## Reglas Tecnicas

Antes de modificar, confirma:

- que la ruta pertenece a Avomeal o a un modulo compartido,
- que la query respeta `id_user_business = 2` / `id_owner = 2` y, si es publica/configuracion por marca, `site_key = avomeal`,
- que no rompe VNV Events,
- que no cambia endpoints usados por mobile sin compatibilidad,
- que no mezcla Store revenue con Ophyra SaaS revenue,
- que no convierte clientes finales en business owners.

No modificar sin justificacion fuerte:

- Kernel / Router,
- `BaseRepository`,
- `UserRepository`,
- login/auth,
- endpoints API usados por mobile,
- pagos,
- estructura JSON consumida por apps,
- tablas compartidas sin SQL revisado.

## Orden De Lectura

```text
docs/README.md
docs/AVOMEAL_CONTEXT.md
docs/DATABASE_SCOPE.md
docs/STORE_MODEL.md
docs/PRODUCT_MODEL.md
docs/PRODUCT_VISIBILITY.md
docs/SUBSCRIPTIONS_AND_ORDERS.md
docs/ORDERS_AND_TEAM_WORKFLOW.md
docs/CLIENT_FLOW.md
docs/SUBSCRIPTIONS.md
docs/CMS_AND_PUBLIC_CONTENT.md
docs/HOMEPAGE_POSITIONING.md
docs/VNV_EVENTS_RELATIONSHIP.md
docs/OPHYRA_FUTURE_INTEGRATION.md
docs/OPHYRA_CMS_ROUTE_CONTRACT_REVIEW_2026_06_09.md
docs/NEXT_AGENT_HANDOFF.md
```

## Principio Principal

Avomeal debe crecer como food/store brand dentro del ecosistema sin romper VNV Events, sin leer/escribir data global y sin diluirse como Ophyra generico.
## AI Content Assistant

Do not allow AI-generated content to publish without human approval.

First phase AI content work is limited to blog posts and location pages. Do not extend the assistant to service landing pages, forums, products, campaigns or store content unless the user explicitly starts a later phase.

In this VNV Events repo, the default AI content profile is:

```text
AI_CONTENT_SITE_KEY=vnv_events
```

The same implementation is portable to Avomeal by switching:

```text
AI_CONTENT_SITE_KEY=avomeal
```
