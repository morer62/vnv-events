---
name: ophyra-video-producer
description: Revisa Ophyra_space y produce en CapCut los episodios marcados PENDIENTE siguiendo sus archivos descripcion.txt y guion.txt. Usar cuando Jonathan pida revisar, cargar, editar, terminar o informar el estado de sus podcasts, sesiones musicales, clips editoriales o videos de The Ground Floor. No usar para publicar contenido.
---

# Ophyra Video Producer

Usa `C:\Users\jonat\OneDrive\Desktop\Ophyra_space` como fuente de verdad. Cada episodio es autocontenido: el video original y los archivos de control están en su raíz; los materiales autorizados están directamente en `recursos/`.

Antes de operar, lee [references/episode-contract.md](references/episode-contract.md). Ejecuta `scripts/scan_episodes.ps1` para obtener el inventario y procesa únicamente episodios cuyo `estado.txt` contenga exactamente `ESTADO: PENDIENTE`.

## Flujo obligatorio

1. Lee completamente `estado.txt`, `descripcion.txt` y `guion.txt` antes de abrir CapCut.
2. Confirma que existe un único video fuente creíble. Si faltan archivos esenciales o existen varios originales ambiguos, conserva `PENDIENTE` sin modificar el archivo y continúa con otro episodio procesable. Toda explicación pertenece a `descripcion.txt`, nunca a `estado.txt`.
3. Trabaja en CapCut Web dentro de la misma jerarquía `programa > episodio > proyecto`. Usa una sola pestaña y un solo proyecto canónico. No abras CapCut Desktop ni proyectos sueltos.
4. Importa el video del root y solamente los archivos presentes en `recursos/` que `descripcion.txt` o `guion.txt` autoricen.
5. Cuando exista diálogo, activa primero la edición basada en transcripción. Usa `guion.txt` para corregir texto, hablantes, silencios, palabras truncadas, errores y repeticiones. Conserva la última toma completa cuando se anuncie o detecte un reintento.
6. Aplica literalmente el nivel de producción descrito. Una sesión musical puede requerir solo intro, outro y logo; un podcast puede requerir limpieza, tarjetas, referencias y edición visual.
7. No inventes personas, posiciones, recursos, hechos ni decisiones visuales que los archivos del episodio no permitan.
8. Antes de la fase visual compleja se permite una única copia identificable del proyecto editorial limpio. No generes más duplicados, descargas intermedias, proxies manuales ni carpetas auxiliares.
9. Revisa el episodio completo en CapCut. Verifica sincronización, cortes, captions, audio, continuidad, recursos, nombres, intro, outro y ausencia de elementos fuera de línea.
10. Cambia a `ESTADO: LISTO` solamente cuando el proyecto editable y todo lo solicitado estén completos y verificados. Si queda cualquier trabajo o aprobación pendiente, conserva `ESTADO: PENDIENTE`.
11. `estado.txt` debe contener exactamente una sola línea: `ESTADO: PENDIENTE` o `ESTADO: LISTO`. No añadas fechas, notas, bitácoras, detalles ni líneas vacías deliberadas.

No publiques ni programes contenido. Un episodio `LISTO` significa listo para revisión o entrega conforme a su descripción, no publicado.
