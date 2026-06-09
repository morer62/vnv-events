# Contrato móvil VNV Events (web + API + rutas) — revisión en base al commit `06922ca`

Fecha de emisión: 2026-06-09

## 1) Alcance

Este documento queda como contrato funcional para que `morer62/vnv-mobile-app` consuma correctamente la base y panel de `vnv-events` (no de Avomeal).

Ruta base esperada del backend:

```text
https://vnvevents.com/
```

No usar `localhost` ni `https://opyhra.com/` para producción.

## 2) Contrato de API consumido por la app (desde `src/views/api`)

La app móvil no consume endpoints sueltos de `views/app`; su contrato actual usa:

- Autenticación y sesión: `src/views/api/auth/*`
- Push token: `src/views/api/set-expo-push-token.php`
- Peticiones de cliente: `src/views/public/search/client_request/index.php`
- Endpoints auxiliares: `src/views/api/version.php`, `src/views/api/auth/forgot-password.php`

### 2.1 Auth (relevante para VNV Events)

| Endpoint | Método | Origen app | Campos que debe enviar la app | Respuesta clave |
| --- | --- | --- | --- | --- |
| `/api/auth/login` | POST | `SignInScreen` | `email`, `password`, `expo_token` opcional, `id_user_business=2`, `source=vnv_mobile_app` | `success`, `token`, `api_token`, `user` |
| `/api/auth/signup` | POST | `SignUpScreen` | `name`, `lastname`, `email`, `phone`, `password`, `passwordConfirmation`, `level`, `id_user_business=2`, `source=vnv_mobile_app` | `success`, `token`, `api_token`, `user` (o `data.token` / `data.user` en despliegues legacy) |
| `/api/auth/validate-token` | POST | `Root` / refresco sesión | `token` o `api_token` en body/headers | `success`, `user` |
| `/api/auth/change-level` | POST (Authorization bearer) | cambio de tipo de cuenta | `level` | `success`, `user` |
| `/api/auth/forgot-password` | POST | recuperación | `email`, `id_user_business=2` | `success` |

### 2.2 Reglas de negocio que deben mantenerse

- `id_user_business`/`id_owner`/`business_id`/`owner_id` debe resolver al `users.id = 2` (owner VNV Events).
- Signup móvil solo puede crear/asociar **nivel 5**.
- Signup/login deben rechazar o forzar rechazo de perfiles que no sean clientes cuando se use flujo móvil.
- `change-level` debe bloquear cambios a roles empresariales desde app.
- El token móvil es `users.api_token` (`token` y `api_token` pueden ir ambos).
- `user` devuelto debe incluir:
  - `id`, `level`, `id_owner` (o `id_user_business`), `owner_scope_id`, `api_token`, `membership_type`, `membership_due_date`, `is_active`, etc.

## 3) Contrato de WebView (`Panel/Tokenapi`)

La app usa WebView para paneles:

```text
${BUSINESS_CONFIG.baseUrl}Panel/Tokenapi/${token}/${cleanUrl}
```

`cleanUrl` se construye con URLs internas sin host.

Backend:

1. detecta `panel/tokenapi/<token>/<internal-route>`
2. valida `LoginService::validateToken`
3. crea sesión para ese usuario
4. marca `$_SESSION['IS_MOBILE_APP'] = true`
5. hace redirect a `<internal-route>`
6. si token inválido, redirige a `login`.

Punto importante:
- La ruta se resuelve en minúsculas y con normalización (`Kernel::getUrlViews()`).
- No enviar `http://localhost` desde producción; eso rompe rutas internas en algunos teléfonos.

## 4) Mapeo de rutas desde home del panel (app) por nivel

### Nivel 1 / 2 / 3 (administración de VNV Events)

Hero y tarjetas:

- `panel/planner-hub/management/orders`
- `panel/planner-hub/management/users`
- `panel/planner-hub/management/crm`

Secundarias:

- `panel/planner-hub/management/payroll`
- `panel/planner-hub/management/storage`
- `panel/planner-hub/team/chat`
- `panel/planning-tools`
- `panel/settings`

Adicionales por nivel 1:
- `panel/multimedia-sessions`

### Nivel 4 (team)

- `panel/planner-hub/team/payroll/clock`
- `panel/planner-hub/team/payroll/pending`
- `panel/planner-hub/team/orders/orders/` *(con slash final, mantenerlo)*
- `panel/planner-hub/team/my-work`
- `panel/planner-hub/planner-hub`
- `panel/planning-tools`
- `panel/planner-hub/team/chat`
- `panel/settings`

### Nivel 5 (cliente final)

- `panel/planner-hub/orders/orders`
- `panel/music-sessions`
- `panel/planning-tools`
- `panel/chat`
- `panel/settings`

Acciones externas que **no** deben ir por WebView:
- `tel:+13052045427`
- `https://maps.google.com/?q=10258+NW+47th+St,+Sunrise,+FL+33351`

## 5) ¿Qué revisar en `morer62/vnv-mobile-app` para que no se rompa?

- `src/config/businessConfig.js`
  - `baseUrl` desde `API_URL` de `.env`
  - `businessId` debe ser `"2"` para VNV Events
  - email del owner `info@vnvevents.com`
- `.env.example`
  - `API_URL=https://vnvevents.com/`
  - `BUSINESS_ID=2`
- `src/screens/Panel/Panel.js`
  - rutas por nivel deben coincidir con tabla anterior
- `src/screens/PanelWebView/index.js`
  - URL debe estar en formato `Panel/Tokenapi/${token}/${cleanUrl}`
- `src/http/getUserData.js`
  - validar con `api/auth/validate-token` enviando token (form o header permitido por backend)
- `src/http/changeAccountType.js`
  - endpoint `/api/auth/change-level` con `Authorization: Bearer`

## 6) Instrucción para Ophyra / equipo App (resumen operativo)

Para Ophyra, lo que debe hacer el equipo de integración:

1. Mantener `id_user_business = 2` en mobile signup/login para VNV Events.
2. Mantener la URL base de app en `https://vnvevents.com/`.
3. Mantener parsing dual de respuestas `token/api_token` y `user` en raíz o `data`.
4. Validar que `change-level` no permita niveles empresa desde app.
5. No duplicar lógica de panel en app; siempre navegar por WebView con `Panel/Tokenapi/...`.

Si falta este contrato, la app cae al entrar en `WebView` o abre rutas 404.

## 7) Consulta SQL para validar estructura entre DBs (producción vs local)

Sustituir `db_prod` y `db_local` por los nombres reales.

```sql
SELECT
  COALESCE(p.table_name, l.table_name) AS table_name,
  COALESCE(p.column_name, l.column_name) AS column_name,
  p.column_type AS prod_type,
  l.column_type AS local_type,
  CASE
    WHEN p.column_name IS NULL THEN 'missing_in_prod'
    WHEN l.column_name IS NULL THEN 'missing_in_local'
    WHEN p.column_type <> l.column_type OR p.is_nullable <> l.is_nullable THEN 'type_nullable_diff'
    WHEN p.column_key <> l.column_key THEN 'index_role_diff'
    ELSE 'ok'
  END AS diff
FROM (
  SELECT table_name, column_name, column_type, is_nullable, column_key
  FROM information_schema.columns
  WHERE table_schema = 'db_prod'
    AND table_name IN ('users', 'clients_users', 'brand_site_settings', 'cms_routes', 'cms_contents')
) p
FULL OUTER JOIN (
  SELECT table_name, column_name, column_type, is_nullable, column_key
  FROM information_schema.columns
  WHERE table_schema = 'db_local'
    AND table_name IN ('users', 'clients_users', 'brand_site_settings', 'cms_routes', 'cms_contents')
) l
ON p.table_name = l.table_name AND p.column_name = l.column_name
ORDER BY table_name, column_name;
```

Para MySQL sin `FULL OUTER JOIN`, correr con dos consultas (`LEFT JOIN` invertido) y unir resultados.

Si aparecen diferencias con campos críticos del contrato móvil, aplicar en la DB que falte la definición faltante (revisar `ALTER TABLE`) y documentar el DDL exacto antes de ejecutar.

## 8) Punto de control mínimo (pre-release)

1. Login/Signup móvil valida con `id_user_business=2` contra user 2.
2. Abrir cada ruta de nivel desde dashboard y confirmar no 404.
3. Abrir WebView de `panel/planner-hub/team/orders/orders/` en nivel 4 y confirmar no rompe por slash final.
4. Cambiar rol de usuario (si aplica) y validar que backend bloquea niveles improcedentes.
5. Confirmar token de push se guarda con formato válido `ExponentPushToken[...]` o `ExpoPushToken[...]`.

## 9) Equivalencia para otros clientes

La misma plantilla aplica para Avomeal y Jonnys:

- cambiar `businessId` y owner id.
- cambiar `API_URL` al dominio correcto.
- mantener la misma regla de contrato móvil (`tokenapi`, `id_user_business`, `level=5` en signup, rutas WebView por nivel).
