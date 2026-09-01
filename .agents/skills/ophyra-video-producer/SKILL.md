---
name: ophyra-video-producer
description: Revisa Ophyra Space en Google Drive y prepara en CapCut Web una unica version editorial de cada episodio pendiente: carga, transcripcion, correccion y cortes. Usar tambien cuando Jonathan diga simplemente "activa el agente de video", "corre el agente de video" o equivalente. No usar para publicar ni para producir efectos visuales finales.
---

# Ophyra Video Producer

La fuente de verdad es la carpeta compartida de Google Drive `ophyra_space`:

`https://drive.google.com/drive/folders/17LZt3s0q-i-bA_83xOJql9dn3FnzIMNX`

Usa el conector de Google Drive para inventariarla y leer los archivos de control. Lee completamente [references/episode-contract.md](references/episode-contract.md) antes de operar. La carpeta local antigua es solo una alternativa si Jonathan la pide expresamente; nunca mezcles ambas fuentes durante una ejecucion.

La invocacion del usuario puede ser minima. Una orden como `Activa el agente de video y procesa los pendientes` autoriza el flujo completo descrito aqui. No le devuelvas al usuario estas instrucciones ni le pidas que repita URLs, convenciones, estados, nombres o pasos que el agente ya conoce.

Esta es una tarea terminal y persistente: una actualizacion de progreso no termina la ejecucion. Despues de crear, cargar, transcribir o cortar un episodio, continua con sus pasos restantes y luego con el siguiente pendiente. No devuelvas el turno solo para anunciar que una carga empezo, termino o esta cerca de terminar.

Cuando Jonathan diga `tomate tu tiempo`, `quedate pendiente`, `espera pacientemente`, `no te detengas` o equivalente, tratalo como una instruccion explicita de vigilancia prolongada. Mantente observando las operaciones lentas, incluso si requieren mucho tiempo, y continua el flujo cuando cambie el estado. Estas frases amplian la duracion y persistencia de la ejecucion, no el alcance editorial ni los permisos.

En este equipo, cuando Jonathan diga `Drive local`, se refiere al Google Drive sincronizado montado en Windows bajo `G:\My Drive\ophyra_space\`. No significa `Downloads`, una copia descargada ni la antigua carpeta local. El conector de Google Drive sigue siendo la fuente de verdad para inventario, controles y estados; la unidad sincronizada es solamente la fuente de bytes para el selector nativo de CapCut cuando la importacion directa de Drive no funcione.

## Invariantes

- `Template` es de solo lectura: no procesarla, renombrarla, moverla, copiarla ni cambiar sus archivos o permisos.
- Cada episodio genera exactamente un proyecto canonico de CapCut Cloud con el mismo nombre completo de la carpeta del episodio, dentro de la carpeta del mismo programa.
- Subir un archivo a `Materials` no crea el proyecto editorial. El episodio no esta preparado hasta que exista el proyecto canonico, el original este agregado una sola vez a su linea de tiempo y se haya verificado la duracion.
- Si ese proyecto ya existe, reanudalo. No abras ni crees otro.
- Trabaja en CapCut Web, en una sola pestana. No abras CapCut Desktop.
- Para autenticacion, usa como maximo una pestana por servicio. Reutiliza cualquier pestana de Google Drive o CapCut que ya este abierta; no acumules paginas de login.
- No hagas duplicados de proyectos, versiones, respaldos, proxies manuales, exportaciones intermedias ni carpetas auxiliares.
- Importa cada video y recurso una sola vez. Prefiere la importacion directa de Google Drive disponible en CapCut. Si CapCut no puede importar el archivo directamente desde Drive, conserva `PENDIENTE` y explica el bloqueo; no descargues una copia local por iniciativa propia.
- En Chromium conectado por CDP, `setInputFiles` no puede transferir archivos mayores de 50 MB. Para los originales grandes, abre el selector nativo de Windows desde CapCut y selecciona el archivo existente en `G:\My Drive\ophyra_space\<Programa>\<Episodio>\`; no intentes adjuntarlo por CDP, no lo copies a `Downloads` y no crees otra descarga.
- La tarea termina en la version editorial limpia: carga, transcripcion, correccion del texto y cortes. No agregues captions estilizados, zoom, transiciones, imagenes de referencia, color, intro, outro, musica ni otros efectos, aunque existan recursos, salvo que Jonathan amplie expresamente la tarea en esa ejecucion.
- No publiques ni programes contenido.

## Preflight

1. Verifica acceso a Google Drive y construye primero el inventario completo: carpeta raiz, programas, episodios y estado de cada episodio.
2. Ignora cualquier carpeta cuyo nombre normalizado sea `Template`.
3. Clasifica cada episodio antes de interactuar con CapCut: `LISTO` se omite y `PENDIENTE` entra en la cola. Si el estado esta ausente o vacio, inicializalo como `ESTADO: PENDIENTE` unicamente cuando exista un video original unico y al menos uno entre `descripcion` o `guion` tenga contenido util. Un valor distinto de los estados permitidos se reporta y no se sobrescribe.
4. Si la cola queda vacia, informa que no hay proyectos nuevos y termina. No abras CapCut.
5. Solo cuando exista al menos un episodio procesable, verifica una sola sesion activa de CapCut Web. Reutiliza la pestana y proyecto existentes.
6. Si falta autenticacion y existe control del navegador, abre o reutiliza la pestana del servicio requerido y navega directamente a su pagina oficial de inicio de sesion. Dejala visible y lista para que Jonathan complete el acceso; no le des una lista de menus ni le pidas que encuentre la pagina.
7. Pide unicamente `Inicia sesion aqui y dime listo`. Nunca pidas credenciales ni solicites que las escriba en el chat.
8. Cuando Jonathan diga `listo`, valida la sesion en esa misma pestana y continua exactamente donde quedo el flujo, sin pedir que repita la tarea, la URL o las instrucciones.
9. Si el entorno realmente no ofrece control del navegador, explica una sola vez esa limitacion concreta y proporciona el enlace oficial exacto. No inventes botones, escudos, permisos o controles que no hayas observado.

## Persistencia y esperas

- Durante cargas, transcripciones y guardados largos, manten la ejecucion activa y revisa periodicamente el progreso. La ausencia temporal de cambios es normal y no es un bloqueo.
- Para una vigilancia prolongada, usa esperas acotadas y comprobaciones sucesivas para conservar capacidad de informar y reaccionar. No abandones la tarea por el numero de comprobaciones ni conviertas una espera larga en una solicitud para que Jonathan vigile por el agente.
- Si la interfaz muestra velocidad, porcentaje, archivos completados o tiempo restante, registra el ultimo valor observado y comprueba que la operacion sigue viva. Una velocidad baja o un porcentaje inmovil durante una sola comprobacion no justifican cancelar ni reiniciar.
- Cuando una operacion termine, valida el resultado y continua inmediatamente. No esperes que Jonathan escriba `sigue`, `listo` o repita la orden.
- Una intervencion humana justifica devolver el turno solamente si el flujo esta realmente detenido en autenticacion, permiso del navegador o un selector nativo que el control disponible no puede completar. Deja la interfaz exacta preparada y pide una sola accion corta.
- Despues de esa accion, reanuda desde el punto exacto y completa toda la cola. No vuelvas a explicar el flujo ni conviertas el desbloqueo en un nuevo final parcial.
- Si una operacion falla, intenta alternativas seguras dentro del alcance y verifica el estado actual antes de declarar bloqueo. Un boton que no respondio una vez, una carga lenta o una pestaña que se refresco no bastan para detener el ciclo.
- El unico final exitoso es el inventario releido con toda la cola procesable en `LISTO`. Si quedan `PENDIENTE`, el reporte debe identificar trabajo o bloqueo concreto; nunca presentes un hito parcial como tarea terminada.

## Flujo obligatorio

1. Selecciona unicamente episodios con estado valido `ESTADO: PENDIENTE`, empezando por el pendiente mas antiguo salvo prioridad expresa. Nunca uses la existencia de un proyecto en CapCut como sustituto del estado de Drive.
2. Lee estado, descripcion y guion antes de abrir el proyecto. Acepta archivos de texto o Google Docs cuyos nombres equivalgan claramente a esos tres controles. Una descripcion vacia no bloquea el episodio si el guion contiene instrucciones suficientes: en ese caso aplica solamente el alcance editorial predeterminado de esta skill. Si ambos estan vacios, omite el episodio.
3. Confirma que existe un unico video original creible en la raiz y que los recursos estan dentro de `recursos/`. Si hay ambiguedad o faltan controles, no cambies nada y continua con otro episodio procesable.
4. En CapCut Cloud, entra en la carpeta del programa. Crea el proyecto con el nombre exacto del episodio solo si no existe; si existe, abre ese mismo proyecto.
   - Verifica el breadcrumb de la carpeta antes de crear. CapCut puede crear un lienzo en la raiz de Ophyra Space aunque la biblioteca de medios muestre la carpeta del programa.
   - Si un proyecto fue creado en la raiz por error, usa `Move to` y muevelo a la carpeta del programa; no lo dupliques ni lo recrees.
   - Si CapCut impone un limite menor que el nombre completo, conserva primero `Episodio NN - ` y abrevia solo el titulo hasta el maximo permitido. No elimines el numero ni inventes otro nombre; informa la equivalencia en el reporte.
5. Importa una sola vez el original y solo los recursos autorizados por `descripcion` o `guion`.
   - Si el original ya aparece en `Materials`, reutilizalo; no lo vuelvas a subir.
   - Si no aparece y la importacion directa de Google Drive falla, usa el mismo archivo de la unidad sincronizada mediante el selector nativo de Windows.
   - Agrega el original una sola vez a la linea de tiempo y verifica que la duracion coincida con el material antes de transcribir.
   - No coloques recursos auxiliares en la linea de tiempo salvo instruccion expresa.
6. Activa la edicion basada en transcripcion. Usa el guion para corregir palabras, hablantes, silencios, fragmentos truncados y repeticiones. Cuando haya varios intentos, conserva solamente el ultimo intento completo.
7. Realiza los cortes sobre el original y revisa la continuidad completa. No conviertas esta fase en produccion visual.
8. Verifica inicio y final, sincronizacion, ausencia de silencios o repeticiones no deseadas, cortes limpios y texto corregido.
   - La deteccion automatica de muletillas o pausas no prueba que los cambios fueron aplicados. Comprueba que la duracion o la linea de tiempo cambien y revisa la continuidad despues de cada operacion.
   - Si CapCut muestra varios intentos de una misma frase, conserva el ultimo intento completo segun el guion y elimina los intentos anteriores. No marques `LISTO` solo porque la transcripcion exista.
9. Cambia el estado de ese mismo episodio a `ESTADO: LISTO` solamente cuando la carga unica, transcripcion, correcciones, cortes y revision hayan terminado correctamente. Si queda cualquier duda o trabajo, conserva `PENDIENTE`.
10. Regresa al inventario y procesa el siguiente `PENDIENTE`. Continua hasta vaciar la cola; no te detengas despues del primer episodio correcto.
11. Al terminar, vuelve a leer los estados de Drive y reporta cuantos quedaron `LISTO`, `PENDIENTE` o invalidos. No abras proyectos ya marcados `LISTO`.

## Compuerta de finalizacion por episodio

Antes de escribir `ESTADO: LISTO`, confirma todos estos hechos observables:

- proyecto unico dentro de la carpeta CapCut del programa;
- original importado una vez y presente una vez en la linea de tiempo;
- duracion inicial verificada;
- transcripcion terminada en el idioma del guion;
- palabras, hablantes e intentos repetidos corregidos contra el guion;
- silencios y muletillas retirados solo cuando el corte resultante fue comprobado;
- revision completa de inicio a final con sincronizacion y continuidad correctas;
- guardado automatico de CapCut confirmado.

Si cualquiera falta, el episodio sigue `PENDIENTE`, aunque el archivo ya este cargado o el proyecto ya exista.

`LISTO` significa listo para revision editorial posterior, no exportado ni publicado.
