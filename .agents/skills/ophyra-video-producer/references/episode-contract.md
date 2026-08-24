# Contrato del episodio

## Estructura

```text
Ophyra_space/
└── Programa/
    └── Episodio NN - Título/
        ├── estado.txt
        ├── descripcion.txt
        ├── guion.txt
        ├── video-original.ext
        └── recursos/
            ├── intro.ext
            ├── outro.ext
            ├── logo.ext
            └── otros archivos mencionados en descripcion.txt
```

No crear carpetas locales de referencias o exportaciones. Las necesidades de imágenes y videos se declaran en `descripcion.txt`; los archivos existentes van directamente en `recursos/`. Los proyectos y resultados permanecen en CapCut Cloud.

## Estados válidos

Solo son válidas estas líneas:

```text
ESTADO: PENDIENTE
ESTADO: LISTO
```

- `PENDIENTE`: debe intentarse el procesamiento. También se conserva cuando falta un insumo, hay ambigüedad, queda trabajo o se requiere aprobación.
- `LISTO`: todo lo solicitado fue aplicado y revisado de principio a fin.

`estado.txt` contiene exactamente una de esas líneas y nada más. Nunca escribir fechas, notas, bitácoras, rutas, avances, impedimentos ni estados como cargado, procesando, captions listos, bloqueado, revisión requerida o exportado. Toda instrucción y contexto pertenecen a `descripcion.txt` o `guion.txt`.

## Orden de procesamiento

Procesa primero el episodio pendiente con la fecha de modificación más antigua, salvo que Jonathan establezca una prioridad. No modifiques `estado.txt` durante el trabajo; cambia la única línea a `ESTADO: LISTO` solamente al terminar y verificar el episodio completo.
