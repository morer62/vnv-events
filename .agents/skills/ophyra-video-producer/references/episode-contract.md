# Contrato del episodio en Google Drive

## Jerarquia

```text
ophyra_space/
└── Programa/
    ├── Template/                         # solo lectura; nunca procesar
    └── Episodio NN - Titulo/
        ├── estado.txt o documento equivalente
        ├── descripcion.txt o documento equivalente
        ├── guion.txt o documento equivalente
        ├── video-original.ext
        └── recursos/
            └── archivos autorizados para ese episodio
```

El nombre del proyecto en CapCut debe ser exactamente el nombre completo de la carpeta del episodio. La carpeta de CapCut debe conservar el nombre del programa. No se crean proyectos sueltos.

En la estacion Windows de Jonathan, `Drive local` designa la unidad sincronizada `G:\My Drive\ophyra_space\`. El inventario y los estados se leen y escriben con Google Drive; CapCut puede tomar los bytes desde esa unidad mediante su selector nativo. Esto no autoriza descargar, copiar o mantener otra version del original.

Un archivo visible en `Materials` no equivale a un proyecto. El proyecto debe estar dentro de la carpeta CapCut del programa, contener el original una sola vez en la linea de tiempo y conservar una duracion verificada antes de comenzar la edicion.

## Template

Toda carpeta llamada `Template`, sin importar mayusculas o minusculas, queda excluida del inventario de trabajo. Sirve exclusivamente para que el equipo cree episodios nuevos. El agente no puede editarla, completarla, marcarla, copiarla ni usarla como episodio.

## Controles

Los nombres canonicos son `estado.txt`, `descripcion.txt` y `guion.txt`. Para episodios existentes se pueden reconocer variantes obvias como `ESTADO TXT`, `DESCRIPCION TXT` o `GUION TXT`, incluso si son Google Docs, pero su contenido sigue siendo obligatorio.

`estado` debe contener exactamente una de estas lineas:

```text
ESTADO: PENDIENTE
ESTADO: LISTO
```

- `PENDIENTE`: el episodio debe procesarse o todavia requiere trabajo.
- `LISTO`: la version editorial limpia fue revisada completa en CapCut.

No escribas fechas, avances, bloqueos ni explicaciones en estado. Esas notas pertenecen a `descripcion`.

El estado de Drive gobierna el ciclo:

- `LISTO` -> omitir sin abrir CapCut.
- `PENDIENTE` -> comprobar o crear el unico proyecto canonico, procesarlo y continuar con el siguiente.
- vacio o ausente + video original unico + descripcion o guion util -> inicializar como `PENDIENTE` y procesar.
- vacio o ausente sin insumos suficientes -> reportar y omitir.
- valor invalido distinto de los dos estados permitidos -> reportar y omitir; no sobrescribirlo.
- ninguna carpeta `PENDIENTE` -> finalizar sin iniciar CapCut.

Tras cada episodio completado se actualiza primero su estado y luego se vuelve al inventario. El agente no termina solo porque haya completado un proyecto si todavia quedan otros pendientes.

Las esperas de subida, transcripcion y guardado forman parte del trabajo. El agente las monitorea y continua al terminar; no requiere que Jonathan permanezca presente ni que envie mensajes de continuacion. Solo una pantalla que exija autenticacion o una seleccion nativa imposible de realizar con el control disponible permite solicitar una intervencion breve.

`Tomate tu tiempo`, `quedate pendiente` y expresiones equivalentes ordenan vigilancia prolongada: realizar comprobaciones sucesivas hasta que termine la operacion y reanudar el flujo sin intervencion del usuario. No autorizan publicar, exportar ni agregar produccion visual.

## Autenticacion

La autenticacion es parte del preflight del agente, no una instruccion que Jonathan debe recordar. Si una sesion expiro, el agente abre directamente la pagina oficial correspondiente en la pestana existente, la deja preparada y solicita solamente que Jonathan inicie sesion y responda `listo`. Despues valida el acceso y reanuda el mismo flujo. No abre multiples pestanas, no solicita credenciales y no repite la explicacion del proceso.

## Archivos

- Debe existir un solo video original reconocible en la raiz del episodio.
- `descripcion` define el trabajo autorizado y los recursos permitidos.
- `guion` sirve para corregir la transcripcion y decidir los cortes; no reemplaza la escucha y revision del audio.
- Si `descripcion` esta vacia pero `guion` es util, se aplica el alcance editorial predeterminado. Si ambos estan vacios, el episodio no es procesable.
- Los recursos quedan en `recursos/` y se importan como maximo una vez al proyecto canonico.
- No se crean carpetas de referencias, exportaciones, respaldos, proxies o versiones.
- Para archivos mayores de 50 MB, no uses adjunto directo por CDP: Chromium rechaza esa transferencia aunque el archivo sea local. Abre el selector nativo de Windows desde CapCut y elige el original sincronizado en `G:\My Drive\ophyra_space\...`.

## Alcance predeterminado

El agente produce solamente el corte editorial limpio: transcripcion, correcciones, eliminacion de errores, repeticiones y silencios, y revision de continuidad. La produccion visual es una tarea posterior y requiere una instruccion expresa.
