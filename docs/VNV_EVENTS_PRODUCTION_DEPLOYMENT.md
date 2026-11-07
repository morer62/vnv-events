# VNV Events Production Deployment

Este es el procedimiento oficial para actualizar `vnvevents.com` en producción.

## Regla principal

El código se despliega exclusivamente desde el repositorio Git del servidor.

- No crear ni subir ZIP.
- No reemplazar carpetas desde File Manager.
- No copiar archivos sueltos sobre producción.
- No cambiar permisos como parte del despliegue.
- No usar `git reset --hard`, `git checkout --` ni `git clean` para forzar una actualización.
- No usar `git pull` si el servidor tiene cambios locales que coinciden con archivos entrantes; primero se investiga el conflicto.

## Acceso y ubicación

1. Entrar a cPanel con la configuración privada guardada en el `.env` local de VNV Events.
2. Abrir **Advanced > Terminal**.
3. Entrar al proyecto:

```bash
cd /home/ophyra/public_html/vnvevents.com
```

Repositorio:

```text
morer62/vnv-events
```

Rama de producción:

```text
main
```

## Preflight obligatorio

Antes de actualizar:

```bash
pwd
git branch --show-current
git status --short
git log -1 --oneline
```

El resultado esperado es:

- `pwd`: `/home/ophyra/public_html/vnvevents.com`
- rama: `main`
- `git status --short`: vacío

Si `git status --short` muestra archivos, no se debe borrar, sobrescribir ni guardar con `stash` automáticamente. Hay que identificar si son archivos generados por el entorno o cambios manuales y comprobar si se superponen con el commit entrante.

## Actualización

Con el preflight limpio:

```bash
git pull --ff-only origin main
```

Se usa `--ff-only` para impedir merges automáticos o commits de combinación dentro del servidor.

## Verificación posterior

```bash
git log -1 --oneline
git status --short
```

El commit debe coincidir con el último commit aprobado y publicado en `origin/main`. El estado debe quedar limpio.

Después se ejecutan solamente las tareas posteriores que correspondan al cambio, por ejemplo:

- migración SQL expresamente revisada;
- regeneración de sitemap/robots/LLM files desde el mecanismo oficial del proyecto;
- limpieza de caché de aplicación si el cambio realmente la requiere;
- pruebas HTTP y funcionales de las rutas modificadas.

## Registro confirmado del 7 de noviembre de 2026

Despliegue SEO ejecutado correctamente con el flujo anterior:

```text
73d1e69 Complete VNV public SEO and location safeguards
```

Estado comprobado en producción:

```text
branch: main
HEAD: 73d1e69
origin/main: 73d1e69
working tree: clean
```

## Regla para agentes futuros

Cuando Jonathan pida subir o actualizar VNV Events, el agente debe asumir este flujo como predeterminado:

1. probar y revisar localmente;
2. crear un commit enfocado;
3. publicar el commit en `origin/main`;
4. entrar al Terminal de cPanel;
5. revisar ruta, rama y estado;
6. ejecutar `git pull --ff-only origin main`;
7. comprobar commit y árbol limpio;
8. realizar QA de producción.

Un ZIP solo puede considerarse si Jonathan revoca expresamente esta regla y solicita otro método para una emergencia específica.
