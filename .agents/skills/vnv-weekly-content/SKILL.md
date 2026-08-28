---
name: vnv-weekly-content
description: Run the isolated weekly article workflow for VNV Events, Miami Tech Lab, or The Pasta Station. Use when Jonathan asks for six weekly articles, editorial optimization, publication, or sitemap verification for one of these brands. Resolve the active project and site key before research so content, services and links never cross brands.
---

# VNV Weekly Content

## Mandatory multisite routing — takes precedence

Despite the historical skill name, this workflow serves three isolated brands. These rules supersede every later VNV-specific hostname, brand or wording in this file whenever the active project is not VNV Events.

| Project root | Site key | Brand | Public origin |
|---|---|---|---|
| `C:\xampp\htdocs\vnv-events` | `vnvevents` | VNV Events | `https://vnvevents.com` |
| `C:\xampp\htdocs\miami-tech-lab` | `miamitechlab` | Miami Tech Lab | `https://miamitechlab.com` |
| `C:\xampp\htdocs\vnv-gourmet` | `avomeal` | The Pasta Station | `https://thepastastation.net` |

Before proposing a topic or opening an editorial provider:

1. Resolve the active project from the working directory, its `.env`/`SiteContext`, and the matching active row in `growth_sites`. All three must agree. Stop on disagreement.
2. Build a brand evidence packet using only that project's public origin and rows where `id_owner=2 AND site_key=<active site key>`.
3. Visit the active brand's homepage, primary navigation, sitemap, service/landing pages, public blog and public locations index. Include physical public pages from the active repository even when they are not CMS rows.
4. Inventory only same-site published `cms_contents`/active `cms_routes`, active public `store_products` and `site_visibility`, plus the active `growth_sites.main_services`, `main_products`, brand voice, CTA and target locations.
5. Record exact verified URLs, page titles, offers and themes. Do not use another brand as an internal-link source, factual source, style source or topic source.
6. Send Claude or ChatGPT the resolved brand name, domain, voice and closed inventory. State explicitly that sister-brand material is prohibited.
7. Create, edit, approve and publish records only under the active site key. Query duplicate topics, recent articles, routes, approvals and sitemaps within that site key.
8. Before saving each article, reject it if it names, links to, sells or structurally assumes a sister brand unless the article explicitly discusses that relationship and Jonathan requested it.
9. Before completion, assert that every internal URL belongs to the active public origin and every persisted row carries the active site key.

Brand boundary examples:

- A Pasta Station article may use its pasta services, menu/store, events, Journal and location pages; it may not infer DJ, decor or VNV catering offers.
- A Miami Tech Lab article may use its AI consulting, automation, software, Insights and location pages; it may not infer event or food services.
- A VNV Events article may use VNV's verified event, entertainment, catering, store and location content; it may not import Pasta Station or Miami Tech Lab offers merely because they share a database.

If a brand has little existing CMS content, use its homepage, physical public pages and verified `growth_sites` services. Do not fill the gap with content from a sister site.

Ejecutar el workflow semanal completo para crear, optimizar, publicar y registrar seis artículos nuevos en el ambiente de producción de VNV Events.

El proceso combina:

* Codex como coordinador y operador.
* Chrome/Computer Use para operar las interfaces.
* Claude para generar ideas y realizar la reescritura editorial definitiva.
* El generador de VNV Events para producir el primer borrador, metadata, featured image y thumbnail.
* Jonathan como supervisor del proceso.
* El SEO / AI Control Center para regenerar los archivos SEO y sitemaps.

# Principio obligatorio

No omitir fases aunque una parte del proceso parezca repetitiva.

El workflow completo es:

1. Conocer el contenido actual de VNV Events.
2. Pedir ideas a Claude.
3. Seleccionar seis ideas.
4. Generar seis artículos en VNV Events.
5. Generar metadata, featured image y thumbnail.
6. Utilizar `Generate more` hasta completar los seis.
7. Editar individualmente cada artículo.
8. Extraer `Body HTML / Twig code`.
9. Optimizar cada body mediante Claude.
10. Validar código, imágenes, Twig y enlaces; optimizar el schema y la estrategia de enlaces internos.
11. Reemplazar exclusivamente el body.
12. Cambiar el artículo a `Published`.
13. Verificar cada página pública.
14. Regenerar todos los archivos SEO.
15. Verificar los sitemaps.
16. Entregar un reporte final.

# Ambiente de producción

Trabajar directamente en:

[https://vnvevents.com](https://vnvevents.com/)

El administrador de VNV Events es un ambiente de PRODUCCIÓN.

No es un entorno de desarrollo, staging o pruebas.

Utilizar exclusivamente las sesiones ya iniciadas en Chrome para:

* VNV Events.
* Claude.ai.

No solicitar, copiar, mostrar, guardar ni escribir contraseñas, códigos de verificación, cookies, tokens o credenciales.

Si VNV Events o Claude no tienen una sesión iniciada:

1. Detener el workflow.
2. Informar cuál sesión falta.
3. Pedirle a Jonathan que tome el control e inicie sesión personalmente.
4. Continuar solamente después de que Jonathan confirme que la sesión está disponible.


## Proveedor editorial y fallback obligatorio

Claude es el proveedor editorial preferido cuando su sesión está disponible y tiene cuota. Si Claude informa falta de tokens, límite de uso o indisponibilidad, no detener el lote: abrir una conversación nueva en ChatGPT usando la sesión ya iniciada de Chrome y ejecutar allí las fases de ideas y optimización con exactamente las mismas entradas, reglas de preservación, investigación externa y validaciones exigidas a Claude.

No mezclar proveedores dentro de un mismo artículo. Registrar qué proveedor se usó para ideas y para cada body. Si tampoco existe una sesión iniciada de ChatGPT, detenerse y pedir a Jonathan que inicie sesión; nunca solicitar ni manipular credenciales. ChatGPT no autoriza relajar ninguna protección de HTML, Twig, imágenes, enlaces, schema o datos empresariales.

Cuando Jonathan confirme que Claude vuelve a tener cuota, interrumpir cualquier optimizacion editorial pendiente en ChatGPT y continuar con Claude. En ese caso, volver a recorrer con Claude todos los bodies del lote actual, incluidos los que hayan recibido una optimizacion provisional de otro proveedor, antes de publicarlos.

# Alcance autorizado

Durante una ejecución estándar, Codex está autorizado a:

* Navegar las páginas públicas de VNV Events.
* Consultar los servicios y páginas existentes.
* Revisar títulos de artículos publicados.
* Consultar a Claude.
* Crear exactamente seis artículos nuevos.
* Utilizar el generador de artículos.
* Activar `AI Assisted`.
* Introducir un `Suggested title`.
* Seleccionar content type, category, intent y keywords.
* Seleccionar una página base o de referencia.
* Seleccionar un servicio específico.
* Seleccionar páginas internas relacionadas.
* Generar el body inicial.
* Generar metadata.
* Generar una featured image.
* Generar un thumbnail.
* Utilizar `Generate more`.
* Editar exclusivamente los seis artículos creados durante la ejecución.
* Reemplazar `Body HTML / Twig code`.
* Optimizar `schema_json` exclusivamente en los seis artículos del lote actual.
* Añadir enlaces internos verificados a servicios, páginas públicas y páginas creadas recientemente cuando refuercen realmente el tema.
* Cambiar esos artículos a `Published`.
* Guardar esos artículos.
* Verificar sus URLs públicas.
* Ejecutar una vez `Regenerate All SEO Files`.
* Verificar los archivos SEO y sitemaps.

# Acciones no autorizadas

No:

* Modificar artículos creados antes de la ejecución actual.
* Eliminar artículos anteriores.
* Eliminar imágenes o archivos.
* Modificar clientes, contratos, órdenes o pagos.
* Modificar usuarios o credenciales.
* Cambiar configuraciones globales.
* Modificar código fuente.
* Ejecutar SQL.
* Instalar plugins o dependencias.
* Cambiar slugs manualmente.
* Utilizar `Indexing Queue` sin autorización adicional.
* Crear más de seis artículos.
* Modificar contenido ajeno al lote actual.
* Modificar el schema de contenido ajeno al lote actual.
* Agregar enlaces internos solo por cantidad o hacia una página que no haya sido verificada públicamente.
* Publicar un artículo que no pase las validaciones.
* Presionar repetidamente botones de generación o regeneración por impaciencia.

# Fase 1 — Preparación

Antes de comenzar:

1. El agente, no Jonathan, debe inspeccionar las capacidades de la sesión actual y las pestañas ya abiertas. No pedir una lista previa de sesiones.
2. Confirmar mediante una prueba real que Codex puede controlar Chrome/Computer Use. Si no puede, detenerse inmediatamente y pedir únicamente que se habilite `Full access / computer control`.
3. Validar la sesión existente de VNV Events y la sesión/cuota de Claude. Pedir a Jonathan que inicie sesión manualmente solamente en el servicio concreto que falte; nunca pedir credenciales ni solicitar que reabra una sesión que ya funciona.
4. Validar ChatGPT únicamente si Claude no está disponible y el fallback se vuelve necesario.
5. Después de que Jonathan confirme `listo`, comprobar la sesión faltante y continuar sin pedirle que repita la tarea.
6. Confirmar que se trabajará en producción.
7. Confirmar que la ejecución estándar creará exactamente seis artículos.
8. Crear un registro temporal del lote actual para no confundirlo con artículos anteriores.
9. Registrar la fecha y hora de inicio.
10. No almacenar credenciales en ese registro.

# Fase 2 — Comprender VNV Events

Antes de pedir ideas:

1. Navegar las páginas públicas relevantes de [https://vnvevents.com](https://vnvevents.com/).
2. Revisar los servicios activos.
3. Revisar las páginas internas disponibles.
4. Revisar las categorías editoriales.
5. Revisar las intenciones disponibles en el generador.
6. Revisar las páginas base o referencias disponibles.
7. Revisar los artículos publicados recientemente.
8. Identificar temas tratados recientemente para evitar repeticiones.
9. Identificar servicios que necesitan contenido.
10. Identificar temporada, ocasiones y tendencias relevantes.
11. Crear un inventario autorizado de páginas internas reales.

El inventario interno debe incluir, cuando esté disponible:

* Nombre de la página.
* Servicio relacionado.
* URL exacta.
* Tipo de página.
* Temas relacionados.
* Estado activo.
* Posibles anchor texts.
* Páginas públicas creadas o actualizadas recientemente que necesiten autoridad interna.
* Productos o servicios relacionados cuya URL pública y estado activo hayan sido comprobados.

Nunca inventar:

* Servicios.
* URLs.
* Slugs.
* Precios.
* Paquetes.
* Clientes.
* Testimonios.
* Estadísticas.
* Capacidades comerciales.
* Casos de VNV Events que no estén documentados.

Cada artículo debe conectar con un servicio específico real.

No forzar South Florida o referencias locales cuando el tema sea general para la industria.

Utilizar un enfoque local solamente cuando la intención del artículo sea verdaderamente local.

# Fase 3 — Pedir ideas a Claude

Abrir una conversación nueva en Claude.

Entregarle:

* Servicios activos verificados.
* Páginas internas verificadas.
* Categorías disponibles.
* Intenciones disponibles.
* Artículos recientes que no deben repetirse.
* Temporada y ocasiones relevantes.
* Prioridades comerciales observadas.
* Estilo editorial esperado.
* Regla de no inventar información de VNV Events.
* Regla de no forzar un enfoque local.

Pedirle entre 12 y 18 ideas de artículos.

Cada idea debe incluir:

* Suggested title.
* Servicio principal.
* Ángulo editorial.
* Intención.
* Categoría recomendada.
* Keywords.
* Página base o referencia.
* Páginas internas relacionadas.
* Justificación de la idea.
* Utilidad para el lector.
* Potencial comercial.
* Posibles fuentes externas.
* Indicación de enfoque general o local.

Preferir temas sobre:

* Decisiones importantes.
* Errores frecuentes.
* Riesgos.
* Problemas documentados.
* Logística.
* Costos relativos.
* Expectativas incorrectas.
* Tendencias.
* Cambios en la industria.
* Experiencia del cliente.
* Comparaciones.
* Casos públicos verificables.
* Preguntas frecuentes.
* Consecuencias de malas decisiones.
* Elementos técnicos que el cliente normalmente desconoce.

Rechazar ideas que:

* Sean demasiado genéricas.
* Repitan artículos existentes.
* Sean variaciones superficiales del mismo tema.
* Utilicen títulos vacíos como “The Ultimate Guide” sin un ángulo real.
* Inventen páginas o servicios.
* No puedan conectarse con un servicio específico.
* No tengan fuentes externas suficientes.
* Fuercen South Florida sin necesidad.

# Fase 4 — Seleccionar seis ideas

Seleccionar exactamente las seis mejores ideas.

Evaluarlas según:

1. Relación con un servicio real.
2. Utilidad para el lector.
3. Potencial comercial.
4. Originalidad.
5. Capacidad de respaldarse con fuentes externas.
6. Capacidad de conectar páginas internas.
7. Adecuación a la temporada.
8. Diversidad entre servicios.
9. Diversidad de intención.
10. Potencial SEO.
11. Capacidad de desarrollar un argumento profundo.
12. Ausencia de duplicación.

Crear una tabla interna con:

* Número.
* Suggested title.
* Servicio.
* Categoría.
* Intención.
* Keywords.
* Página de referencia.
* Páginas internas.
* Justificación.
* Alcance general o local.

Codex puede seleccionar las seis mejores ideas sin detenerse para pedir aprobación, salvo que las opciones sean insuficientes, repetitivas o impliquen información empresarial dudosa.

# Fase 5 — Generar los seis artículos

Abrir el generador de artículos del administrador de producción de VNV Events.

Para cada una de las seis ideas:

1. Seleccionar el content type correcto.
2. Seleccionar la category correcta.
3. Seleccionar el intent correcto.
4. Introducir las keywords.
5. Seleccionar la página base o de referencia.
6. Seleccionar el servicio específico.
7. Seleccionar páginas internas relacionadas.
8. Activar `AI Assisted`.
9. Introducir el título seleccionado en `Suggested title`.
10. Solicitar las propuestas del generador.
11. Revisar las opciones.
12. Seleccionar la opción que mejor conserve:

    * El título.
    * El servicio.
    * El enfoque.
    * La intención.
    * La conexión interna.
13. Generar el artículo.
14. Generar la metadata.
15. Generar una sola featured image.
16. Generar el thumbnail.
17. Confirmar que la imagen corresponde al tema.
18. No pedir imágenes adicionales salvo que la primera sea claramente incorrecta o inutilizable.
19. Confirmar que el artículo fue creado.
20. Registrar el ID o título del artículo generado.

Después de completar un artículo:

1. Utilizar `Generate more`.
2. Introducir la siguiente idea seleccionada.
3. Repetir el procedimiento.

Continuar hasta tener exactamente seis artículos nuevos.

No comenzar la optimización individual hasta completar el lote de seis.

# Fase 6 — Verificar el lote generado

Ir al listado de artículos.

Identificar exclusivamente los seis artículos creados durante la ejecución.

Verificar en cada uno:

* Título.
* ID.
* Estado.
* Categoría.
* Intención.
* Servicio.
* Metadata.
* Featured image.
* Thumbnail.
* Fecha de creación.
* Ausencia de duplicación.

Confirmar:

* Que existen exactamente seis artículos nuevos.
* Que no se generaron duplicados.
* Que todos tienen imagen.
* Que todos tienen thumbnail.
* Que todos tienen metadata.
* Que ninguno fue publicado prematuramente.

Si uno falló:

* Corregir o regenerar solamente ese artículo.
* No crear un séptimo artículo accidentalmente.
* Mantener el total final en seis.

# Fase 7 — Extraer Body HTML / Twig code

Procesar los seis artículos individualmente.

Para cada artículo:

1. Abrir `Edit`.
2. Localizar `Body HTML / Twig code`.
3. Copiar el código completo.
4. Conservar una copia temporal exacta del original.
5. Asociar esa copia con el título y el ID correctos.
6. No mezclar el body de un artículo con otro.

Crear un inventario de elementos protegidos:

* Rutas de imágenes.
* Valores `src`.
* Valores `srcset`.
* Elementos `<picture>`.
* Elementos `<source>`.
* Nombres de archivos.
* Variables Twig.
* Condiciones Twig.
* Includes.
* Macros.
* Expresiones Twig.
* Comentarios Twig.
* URLs internas.
* Clases.
* IDs.
* Atributos `data-*`.
* Formularios.
* CTA.
* Schema.
* JSON-LD.
* Componentes del CMS.

# Fase 8 — Optimizar cada artículo con Claude

Utilizar una conversación limpia de Claude para cada artículo o un contexto claramente separado que no mezcle artículos.

Entregarle:

* Título.
* Servicio.
* Categoría.
* Intención.
* Keywords.
* Página base.
* Páginas internas verificadas.
* Body HTML / Twig completo.
* Reglas de preservación.
* Requisito de investigación externa.

Codex selecciona los enlaces internos finales. Debe entregar al proveedor únicamente URLs públicas verificadas y explicar brevemente la relación semántica de cada una. Preferir páginas de servicio y páginas nuevas que necesiten autoridad cuando respondan de manera natural a una necesidad del lector. No imponer todas las páginas disponibles ni repetir el mismo anchor text de forma artificial.

Pedirle que:

* Tenga libertad editorial completa para reescribir todo el texto, cambiar el enfoque, reorganizar secciones, sustituir encabezados, agregar o retirar listas, tablas y preguntas frecuentes, y reconstruir el argumento cuando considere que eso produce el mejor articulo para VNV Events.
* Trate el body generado por VNV como materia prima, no como una estructura editorial obligatoria.
* No conserve por inercia el tono, la tesis ni la estructura del borrador producido por ChatGPT o por el generador. Si el borrador es generico, repetitivo, frio, irrelevante para VNV Events o parece escrito para cualquier empresa, debe descartarlo editorialmente y reconstruir el articulo desde cero, conservando solamente los elementos tecnicos protegidos.
* Escriba con identidad reconocible de VNV Events: experiencia real de produccion, hospitalidad, catering, entretenimiento y coordinacion de eventos, usando solo capacidades verificadas en las paginas publicas de VNV.
* Encuentre una tension humana o decision real que provoque leer: una expectativa equivocada, un riesgo poco visible, una consecuencia emocional, una pregunta incomoda, un detalle que cambia la experiencia del invitado o una decision que el cliente no sabia que debia tomar.
* Produzca una apertura fuerte y sensible, especifica para el tema, que genere curiosidad sin clickbait; mantenga esa promesa durante todo el articulo con observaciones concretas, utilidad practica y una conclusion memorable.
* Evite introducciones intercambiables, frases corporativas vacias, listas obvias, repeticion de keywords, afirmaciones infladas y parrafos que solo existen para aumentar longitud.
* Investigue fuentes externas reales.
* Compruebe que cada referencia externa abre y respalda la afirmacion concreta junto a la que se cita.
* Profundice el argumento.
* Convierta el borrador en un articulo genuinamente interesante: apertura con una tension o decision concreta, desarrollo no generico, ejemplos practicos verificables y conclusion util.
* Mejore la utilidad práctica.
* Mejore la precisión.
* Mejore la estructura.
* Mejore los encabezados.
* Mejore listas y tablas.
* Mejore preguntas frecuentes cuando corresponda.
* Elimine contenido genérico.
* Conecte el artículo naturalmente con el servicio.
* Devuelva exclusivamente HTML/Twig completo.

Claude debe ejecutarse una sola vez por articulo para controlar el consumo de creditos. Despues de recibir esa unica version optimizada, Codex realiza localmente la revision determinista: elimina cualquier corrupcion mecanica, comprueba referencias externas, confirma enlaces internos y valida todos los elementos protegidos. No pedir a Claude una segunda pasada, una regeneracion ni una correccion salvo autorizacion nueva y explicita de Jonathan.

El proveedor editorial debe cumplir obligatoriamente:

1. Conservar exactamente todas las rutas de imágenes.
2. No modificar `src`.
3. No modificar `srcset`.
4. No modificar `<picture>`.
5. No modificar `<source>`.
6. No modificar nombres de archivos.
7. No modificar variables Twig.
8. No modificar condiciones Twig.
9. No modificar includes.
10. No modificar macros.
11. No modificar expresiones Twig.
12. No modificar clases estructurales.
13. No modificar IDs.
14. No modificar atributos `data-*`.
15. No eliminar formularios.
16. No eliminar CTA.
17. No modificar schema.
18. No modificar JSON-LD.
19. Conservar las URLs internas existentes.
20. No inventar URLs ni slugs.
21. Verificar cualquier nueva URL interna antes de utilizarla.
22. No inventar precios.
23. No inventar paquetes.
24. No inventar clientes.
25. No inventar testimonios.
26. No inventar estadísticas.
27. No inventar experiencias.
28. No inventar capacidades.
29. Utilizar fuentes externas reales.
30. Relacionar cada fuente con una afirmación concreta.
31. Priorizar fuentes oficiales, asociaciones profesionales, estudios, publicaciones especializadas y medios confiables.
32. Utilizar fuentes locales solamente cuando sean pertinentes.
33. No forzar South Florida en artículos generales.
34. No convertir el artículo en publicidad vacía.
35. No escribir explicaciones antes o después del código.
36. Devolver el documento completo.

Las reglas de preservacion protegen integridad tecnica y datos empresariales; no deben interpretarse como una restriccion a la creatividad editorial de Claude. Claude puede reemplazar todo el copy situado dentro de los bloques protegidos y puede mover bloques editoriales cuando conserve intactos los activos, rutas, Twig, formularios y CTA requeridos.

## Control de identidad editorial

Antes de aceptar la unica respuesta de Claude, Codex debe comprobar que el articulo no podria publicarse sin cambios bajo el nombre de cualquier empresa de eventos. Debe contener un angulo claramente relacionado con VNV Events, sus servicios reales o las decisiones que enfrentan sus clientes.

Rechazar localmente como insuficiente un body que:

* Empiece con una definicion generica o una introduccion tipo manual.
* Se limite a enumerar consejos obvios.
* Repita el borrador del generador con sinonimos.
* No genere curiosidad, tension, empatia o una consecuencia concreta.
* Use a VNV Events solamente en el CTA final sin relacionar el desarrollo con su experiencia y servicios verificados.
* Parezca escrito para cumplir keywords en lugar de ayudar a una persona a tomar una decision.

Como Claude solo puede ejecutarse una vez por articulo, Codex debe incluir estas exigencias en el primer prompt. Si la respuesta aun falla este control, Codex puede mejorar localmente el hook, las transiciones y la conexion con VNV usando exclusivamente hechos ya verificados, sin solicitar otra corrida ni inventar experiencias empresariales.

# Fase 9 — Validar la respuesta del proveedor editorial

Antes de pegar el resultado:

1. Comparar el original con el optimizado.
2. Confirmar que todas las rutas de imágenes son idénticas.
3. Confirmar que `src` y `srcset` son idénticos.
4. Confirmar que `<picture>` y `<source>` permanecen.
5. Confirmar que las variables Twig son idénticas.
6. Confirmar que las condiciones Twig permanecen.
7. Confirmar que includes, macros y expresiones permanecen.
8. Confirmar que clases e IDs estructurales permanecen.
9. Confirmar que formularios y CTA permanecen.
10. Confirmar que schema y JSON-LD permanecen.
11. Confirmar que todas las URLs internas originales permanecen.
12. Confirmar que no se inventaron URLs internas.
13. Verificar nuevas URLs internas.
14. Verificar que las fuentes externas abran.
15. Verificar que las fuentes sean pertinentes.
16. Verificar estructura HTML.
17. Verificar correspondencia entre título y body.
18. Verificar relación con el servicio.
19. Verificar que no existan afirmaciones empresariales inventadas.
20. Verificar que no aparezcan instrucciones o comentarios del proveedor editorial.

## Optimización obligatoria de schema e internal linking

Después de validar el body y antes de publicar cada artículo, Codex debe revisar nuevamente `schema_json`; no debe aceptar automáticamente el schema producido por el generador.

Esta revision de schema se ejecuta despues de la unica pasada editorial de Claude y de la validacion determinista de Codex. Debe repetirse aunque el schema ya se haya corregido antes, para asegurar que `headline`, `description`, canonical, imagen, fechas, categoria y keywords continuen alineados con la version definitiva.

El schema final debe:

1. Ser JSON válido.
2. Utilizar `https://schema.org` como `@context`.
3. Utilizar `Article` o `BlogPosting` según corresponda.
4. Conservar exactamente el título real en `headline`.
5. Utilizar la descripción real del artículo.
6. Utilizar la canonical pública real en `url` y `mainEntityOfPage.@id`.
7. Utilizar la featured image real en `image`.
8. Utilizar la fecha real de publicación en `datePublished`.
9. Utilizar la fecha real de última revisión en `dateModified`.
10. Identificar a `VNV Events LLC` como author y publisher sin inventar personas.
11. Incluir categoría y keywords reales cuando estén disponibles.
12. No contener `example.com`, fechas heredadas, imágenes inexistentes, URLs inventadas ni afirmaciones no verificadas.

Codex está autorizado a reemplazar `schema_json` de los seis artículos del lote cuando el generado sea incompleto, obsoleto o falso. Debe comparar schema, canonical, featured image, título, descripción, categoría y fecha antes de guardar.

Revisar también el body final para confirmar que cada enlace interno:

* Abre públicamente.
* Es pertinente al párrafo y al servicio.
* Utiliza anchor text natural y descriptivo.
* No duplica innecesariamente otro enlace.
* Puede reforzar una página nueva o prioritaria sin degradar la utilidad editorial.

Si falla una validación:

1. No pegar el código.
2. No guardar.
3. Informar al proveedor editorial la diferencia exacta.
4. Pedir una versión corregida.
5. Repetir todas las validaciones.

Permitir un máximo de dos correcciones por artículo.

Después de dos fallos:

* Codex puede realizar únicamente una reparación mecánica determinista cuando la diferencia sea verificable: restaurar un atributo o bloque protegido desde el original, retirar sintaxis Markdown introducida dentro de HTML, normalizar una URL ya aprobada o agregar un enlace interno/externo previamente verificado sin alterar la afirmación editorial.
* Volver a ejecutar todas las validaciones después de la reparación.
* No reescribir argumentos, inventar afirmaciones empresariales ni cambiar hechos producidos por el proveedor durante esta reparación.
* Si el fallo no puede resolverse de manera mecánica y verificable, conservar el original, dejar el artículo sin publicar, registrar el motivo y continuar con los demás artículos.

# Fase 10 — Actualizar el artículo

Cuando el body optimizado pase todas las validaciones:

1. Volver al artículo correcto.
2. Confirmar título e ID.
3. Reemplazar exclusivamente `Body HTML / Twig code` y el `schema_json` validado del mismo artículo.
4. No cambiar título.
5. No cambiar slug.
6. No cambiar los campos de metadata salvo `schema_json`, que debe ser corregido cuando falle la validación obligatoria.
7. No cambiar category.
8. No cambiar intent.
9. No cambiar servicio.
10. No cambiar featured image.
11. No cambiar thumbnail.
12. No cambiar páginas relacionadas.
13. Cambiar el estado a `Published`.
14. Guardar.

La publicación es automática para todo artículo que supere las validaciones: no dejarlo en borrador esperando una aprobación editorial adicional. Jonathan puede retirarlo después si no desea conservarlo. Esta autorización no permite publicar un artículo que falle HTML/Twig, schema, imágenes, enlaces, identidad editorial o verificación pública.

# Fase 11 — Verificar la publicación

Abrir la URL pública de cada artículo.

Comprobar:

* Código HTTP exitoso.
* Página accesible.
* Título correcto.
* Body correcto.
* Featured image visible.
* Imágenes internas visibles.
* Thumbnail asignado.
* Diseño correcto.
* Twig renderizado correctamente.
* Ausencia de código Twig visible.
* Enlaces internos funcionales.
* Referencias externas funcionales.
* Metadata presente.
* Schema válido, actual y alineado con canonical, featured image y fecha real.
* Correspondencia entre título y contenido.
* Ausencia de instrucciones internas o texto del proveedor editorial.

Si existe un error:

* No eliminar el artículo.
* Devolverlo a estado no público si puede hacerse de forma segura.
* Conservar el código original.
* Registrar el error.
* No afectar otros artículos.

# Fase 12 — Regenerar archivos SEO

Después de publicar y verificar todos los artículos válidos, abrir:

[https://vnvevents.com/panel/seo-center](https://vnvevents.com/panel/seo-center)

Antes de regenerar, registrar:

* Last generation.
* Blog URLs.
* Total public URLs.
* Estado de los archivos generados.

Hacer clic una sola vez en:

Regenerate All SEO Files

Si aparece una confirmación:

* Verificar que corresponda al SEO / AI Control Center de VNV Events.
* Confirmar la acción.

No:

* Hacer clic repetidamente.
* Cerrar la página durante el proceso.
* Utilizar `Indexing Queue`.
* Regenerar archivos individuales innecesariamente.

Esperar hasta que el proceso termine.

Verificar:

* Nueva fecha de generación.
* Estado `Available`.
* Estado `Success`.
* sitemap.xml.
* sitemap-pages.xml.
* sitemap-blog.xml.
* sitemap-store.xml.
* sitemap-locations.xml.
* robots.txt.
* llms.txt.
* llms-full.txt.

Abrir:

* sitemap.xml.
* sitemap-blog.xml.

Confirmar:

* Que los artículos publicados aparecen en sitemap-blog.xml.
* Que Blog URLs aumentó correctamente.
* Que Total public URLs cambió de forma razonable.
* Que no desaparecieron URLs existentes inesperadamente.
* Que los archivos abren.
* Que no existen errores de generación.

Si falta un artículo:

1. No presionar nuevamente el botón repetidamente.
2. Confirmar que el artículo está Published.
3. Confirmar que su URL pública funciona.
4. Registrar el problema.
5. Detener cambios adicionales que puedan afectar producción.

# Fase 13 — Reporte final

Entregar una tabla con:

* Número.
* Título.
* Servicio.
* Categoría.
* Intención.
* URL pública.
* Estado final.
* Cantidad de referencias externas.
* Validación de imágenes.
* Validación de Twig.
* Validación de enlaces internos.
* Resultado del preview.
* Inclusión en sitemap-blog.xml.
* Problemas encontrados.

Incluir también:

* Ideas originales de Claude.
* Seis ideas seleccionadas.
* Justificación de selección.
* Cantidad de artículos generados.
* Cantidad de artículos optimizados.
* Cantidad de artículos publicados.
* Artículos no publicados.
* Motivo de cada fallo.
* Fecha y hora SEO anterior.
* Fecha y hora SEO nueva.
* Blog URLs antes y después.
* Total public URLs antes y después.
* Estado de cada archivo SEO.
* Recomendaciones de mejora.

# Criterio de éxito

La ejecución será exitosa solamente si:

* Se solicitaron ideas a Claude o, si no había cuota, a ChatGPT en una conversación nueva.
* Se seleccionaron seis ideas sólidas.
* Se crearon exactamente seis artículos.
* Se utilizó el generador real de VNV Events.
* Se utilizó `AI Assisted`.
* Se utilizó `Suggested title`.
* Se utilizaron servicios y páginas reales.
* Se utilizó `Generate more`.
* Cada artículo recibió metadata.
* Cada artículo recibió featured image.
* Cada artículo recibió thumbnail.
* Cada body fue enviado individualmente al proveedor editorial.
* El proveedor editorial realizó investigación externa.
* El proveedor editorial devolvió HTML/Twig completo.
* Las imágenes permanecieron.
* El Twig permaneció válido.
* No se inventaron rutas internas.
* Cada schema fue recorrido y optimizado después de generar el body.
* Ningún schema contiene dominios de ejemplo, fechas heredadas ni imágenes inexistentes.
* Los enlaces internos finales fueron seleccionados y verificados por Codex según relevancia editorial.
* Solamente se publicaron artículos válidos.
* Cada página pública fue verificada.
* Se ejecutó una sola vez `Regenerate All SEO Files`.
* Los nuevos artículos aparecen en sitemap-blog.xml.
* Se entregó el reporte final.

# Manejo de incertidumbre

Resolver decisiones editoriales menores utilizando el contexto disponible.

Detenerse y pedir intervención solamente cuando:

* Falte autenticación.
* El artículo objetivo sea ambiguo.
* Una acción pueda modificar contenido anterior.
* El proveedor editorial altere repetidamente código protegido.
* Aparezca una confirmación inesperada.
* Una acción pueda eliminar información.
* Exista un error de producción.
* Se requiera una credencial.
* Sea necesario utilizar Indexing Queue.
* La solución implique modificar código fuente o base de datos.

# Activación

Esta skill debe activarse cuando Jonathan escriba instrucciones como:

* “Ejecuta los artículos semanales de VNV.”
* “Crea los seis artículos de VNV Events.”
* “Ejecuta el agente de contenido de VNV.”
* “Haz el workflow semanal completo.”
* “Use $vnv-weekly-content.”
* “Corre VNV Weekly Content.”
