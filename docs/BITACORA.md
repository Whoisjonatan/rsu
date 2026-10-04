# Bitácora

Registro de lo trabajado por sesión, para retomar desde aquí.

---

# 4 de octubre de 2026 — El front pasa de React a Blade

**Por qué:** manejar una sola carpeta. El SPA en React (`rsu-front`, ~11 000 líneas) se
reescribió como vistas Blade dentro de `rsu-back`. Se consideró meter el React dentro de
Laravel tal cual (una tarde de trabajo, sin reescribir), pero se eligió Blade.

## Cómo quedó

- **Las 21 pantallas en Blade**, con las mismas URL (`/vehiculos`, `/personal/2?tab=contratos`,
  `/programacion/calendario`…), la misma paleta y los mismos textos.
- **Servidor primero:** listados, filtros (GET), paginación y formularios (POST con
  FormRequest, redirección y aviso flash). **Alpine.js** solo para lo que tiene que pasar sin
  recargar: modales, pestañas, mapas, vista previa de fotos.
- **Lo vivo sigue yendo al API**, con la sesión (`statefulApi()`): consulta de DNI, checklist
  de W18, simulación de W19, inicio de recorrido W21 y alta de sector desde el modal de zona.
- **Login por sesión** en el panel; el API mantiene sus tokens Bearer para la app móvil.
  "Mantener sesión activa" es la cookie de recuerdo; sin marcarla, la sesión muere al cerrar
  el navegador (`SESSION_EXPIRE_ON_CLOSE=true`).
- **Mensajes en español** (`lang/es/validation.php`, `APP_LOCALE=es`): antes los tapaba Zod en
  el front; ahora los muestra el servidor, y de paso el API también responde en español.

## Reglas sacadas de los controladores del API

Para no tener dos versiones de cada regla (una web y otra API), lo que vivía en métodos
privados de los controladores del API pasó a sitios compartidos:

- **FormRequests nuevos:** Brand, BrandModel, VehicleType, VehicleColor (acepta HEX), UserType,
  Contract (eventual de 2 meses, sin cruces), Vacation (elegibilidad, 30 días, sin cruces),
  Zone, Sector, Schedule.
- **`deletionBlocker()`** en cada modelo: el 409 del API y el aviso de la web salen de ahí.
- **Resúmenes** (`Vehicle::summary()`, `User::staffSummary()`, `VehicleRoute::daySummary()`,
  `Scheduling::summary()`), **filtros** (`User::scopeFilter()`, `Scheduling::scopeFilter()`) y
  **guardados en transacción** (`Vehicle::createWithImages()`, `Route::saveWithZones()`,
  `Scheduling::saveAndSync()`, `Zone::replaceCoords()`).
- **`AttendanceService`**: marcación y reportes de asistencia.

El API se probó con token después del cambio: mismos códigos (200, 201, 204, 409, 422) y
mismos mensajes.

## Bugs encontrados probando en el navegador

- **El alta de vehículo acababa en `/api/vehicles`.** `apiResource` registra nombres de ruta
  iguales a los de la web y los pisa. Solución: `Route::name('api.')` en todo `api.php`.
- **`crypto.randomUUID is not a function`** al agregar fotos: solo existe en https/localhost.
- **Error de sintaxis en el calendario** por un `@php(...)` en línea antes de un bloque
  `@php … @endphp`. Se pasaron todos a bloque.

## Mejoras de paso

- El combo de **ruta** en W18 lista las rutas de la zona elegida (en React salía vacío).
- W12 lista **a todo el personal** en "Por persona" (React solo traía la primera página).
- W19 muestra **todas** las programaciones de la zona (React solo traía 10).
- Páginas de error propias (404, 419, 500) y favicon con el reciclaje en verde.
- La barra lateral se abre como cajón en pantallas chicas (en React desaparecía).

---

# 13 de septiembre de 2026 (6) — Programación, mapas y cierre de las 21 pantallas

Sesión larga. Se cerró el módulo de programación entero (W17–W21), se remató el panel
principal (W02) y se hicieron los mapas (W14, W15). **Con esto quedan implementadas las 21
pantallas del informe.**

## Programación (W17–W21)

Dos tablas, como ya se había decidido: `schedulings` con la regla maestra y `vehicleroutes`
con el recorrido de cada día. Guardar una programación genera los recorridos, con tres
reglas que valen la pena recordar: no se programa hacia atrás, lo que ya arrancó no se
toca, y un reemplazo de reserva no se deshace al reeditar.

Las cuatro validaciones del informe viven en `SchedulingRules`, fuera del FormRequest,
porque hay **tres entradas a las mismas reglas**: el alta de W18, la checklist viva del
panel derecho y la edición masiva de W19. El primer intento fue instanciar el FormRequest
a mano desde el bulk — un truco frágil que simulaba el route resolver. Se tiró.

**La validación 4 se aplica por recurso, no literal.** El informe dice "que coincidan en
Turno, Vehículo, Conductor y Ayudantes"; exigir los cuatro a la vez dejaría pasar al mismo
conductor con dos vehículos a la misma hora.

## Lo que aportaron los wireframes

Revisarlos (exportados a PNG, porque el MCP de OpenPencil no conectaba) destapó **seis
cosas que el texto del informe no dice**: el código `PRG-NNNN`, que la columna ESTADO de W17
es la del recorrido y no la de la programación, la checklist viva de W18, la edición por
campo de W19, la hora de marcación en W21 y —la importante— **las vacaciones registradas
después de programar**, que era un agujero real: las validaciones corren al guardar, así que
unas vacaciones posteriores dejaban la programación en pie como si nada.

## Mapas (W14, W15) con Leaflet

Sin API key ni cuenta. Lo que costó decidir no fue la librería sino **qué es una ruta**:

`routes` guarda la ruta planificada (partida y fin) y `routepaths` los puntos por donde pasó
el vehículo de verdad, colgando de `vehicleroutes`. **El GPS del vehículo y el "marcar punto
por punto" son la misma tabla, y ninguna de las dos es W15.** Por eso las dos pantallas se
pudieron hacer enteras sin tocar nada del móvil, y sin dejar deuda.

Rutas duplicadas: se bloquea por nombre repetido en la zona **y** por extremos a menos de
50 m de otra ruta de esa zona. El margen es a propósito — nadie hace clic dos veces en el
mismo píxel.

## Bugs encontrados probando, no compilando

- El `<select>` de personal filtraba por estado "activo", lo que **excluía a quien estuviera
  de vacaciones hoy** y dejaba el campo vacío al editar. La trampa documentada, colada por
  la puerta de atrás.
- Tras reemplazar a alguien en W21, el botón seguía diciendo "Reemplazar" con la checklist
  entera en verde.
- La vista general de rutas trataba los puntos como lista plana: solo la primera salía y sin
  línea. En modo `endpoints` van **en pares**.
- El mapa daba un salto de zoom al poner el primer vértice y descolocaba los clics
  siguientes.
- `attendances.check_in` se llama así, no `time_in`. Un `create` con la clave equivocada
  **no falla**: Eloquent la descarta en silencio.

## El documento cambió de nombre

`SIGUIENTE-SESION.md` pasó a ser **`GUIA-TECNICA.md`**: ya no dice "qué falta" sino "cómo
funciona esto". Se reordenó (los mapas junto a programación) y lo pendiente quedó recogido
en una última sección de mejoras, que no son huecos del informe sino cosas que harían mejor
al sistema.

---


# 13 de septiembre de 2026 (5) — Cambio de proveedor de DNI

Se cambió el proveedor de la consulta de DNI y se dejó **uno solo**.

## Por qué

Crinver ya tiene contratado un servicio de consulta de documentos
(`consultasapi.devmiguelrevilla.com`). No tenía sentido gastar la cuota de un plan
distinto en RSU teniendo ese pagado.

Verificado contra un DNI real antes de decidir:

```
GET /api/test/{dni}/{token}     header  x-api-key: <key>
→ {"document":"76091348","name":"LEVI JONATAN","last_name":"CHING AYACILA",
   "razon_social":null,"estado":null,"direccion":null,"state":1,"boo":0}
```

Encaja mejor que Decolecta: devuelve los nombres en un campo y los dos apellidos ya
juntos en otro, que es exactamente como los guarda la ficha. Decolecta los separaba en
paterno y materno y había que volver a unirlos. Los campos `razon_social`, `estado` y
`direccion` solo traen algo con RUC; con DNI vienen `null`.

Dos comportamientos que solo se descubren probando, y que el driver maneja:

- Un **DNI que no existe responde 200 con `name` vacío**, no 404.
- Credenciales inválidas responden **401 con `{"message":"No"}`**.

## Qué se eliminó

Fuera `DecolectaLookup` y `FakeLookup`. Quedan dos drivers: `consultasapi` y `null`.
Menos superficie que mantener y una sola cosa que explicar.

```
app/Services/Dni/DniLookup.php            interfaz
app/Services/Dni/NullLookup.php           apagado
app/Services/Dni/ConsultasApiLookup.php   el proveedor contratado
```

Añadir un proveedor nuevo sigue siendo escribir su clase y una línea en el `match` de
`AppServiceProvider`: la interfaz no se tocó.

## El plan B si el proveedor cae

Esto era el requisito: **el alta nunca puede depender de la API.**

- El botón "Traer datos" solo hace `setValue` de dos campos del formulario. No bloquea
  nada, no deshabilita nada. Los diez campos de W08 se escriben a mano igual que antes;
  comprobado dando de alta a una persona por `POST /api/people` sin pasar por la consulta.
- Todo fallo del proveedor (caída, timeout, 401, 429, 503) termina en un mensaje que
  dice explícitamente **"Escribe los datos a mano"**, en vez de solo reportar el error.
- Si el proveedor entra en mantenimiento largo, `DNI_LOOKUP_DRIVER=null` hace que el
  botón ni se renderice y el sistema queda 100 % manual.
- `timeout` tiene mínimo de 1 s en el config: con 0, `Http::timeout()` entiende "sin
  límite" y el alta podría quedarse colgada esperando a un tercero.

## El caché, corregido

Existía de antes pero tenía dos agujeros:

- **`Cache::remember` no guarda `null`**, así que cada consulta de un DNI inexistente
  volvía a salir a internet — y esas también gastan cuota. Ahora el "no encontrado" se
  guarda como marca, con 1 día de vida en lugar de 30, por si el padrón del proveedor
  estuviera desactualizado.
- **La clave no llevaba el driver.** Ahora es `dni:{driver}:{dni}`, para que cambiar de
  proveedor no sirva respuestas del anterior.

Un fallo del proveedor nunca se cachea: se reintenta a la próxima.

Medido contra el endpoint real: primera consulta 0.52 s (sale a internet), segunda
0.17 s (caché, sin tocar la API). Las filas quedan en la tabla `cache` de MySQL, así que
el caché sobrevive al reinicio.

## Configuración

```
DNI_LOOKUP_DRIVER=consultasapi   # consultasapi | null (apagado)
DNI_LOOKUP_TOKEN=...
DNI_LOOKUP_API_KEY=...
```

**Las credenciales están en `.env`, que está en `.gitignore`.** Son de un servicio
pagado: ojo al comprimir el proyecto para entregarlo. Para sustentar sin exponerlas —y
sin depender de internet— basta `DNI_LOOKUP_DRIVER=null` y llenar la ficha a mano.

---


# 13 de septiembre de 2026 (4) — Consulta de DNI

## Lo que se averiguó antes de escribir código

**RENIEC no tiene API pública.** Su servicio oficial (*Consulta en Línea*) exige convenio
institucional; no se obtiene registrándose en una web. Lo que existe son intermediarios.

Al verificar los proveedores apareció algo que cambió el plan:

- **apis.net.pe retiró la consulta de DNI.** Su propia página lo dice: *"En atención a la
  normativa vigente en materia de protección de datos personales, este servicio de consulta
  de DNI ya no se ofrece de forma pública."* Solo mantienen RUC y tipo de cambio.
- **Decolecta sí la mantiene**, bajo "acuerdos privados para finalidades legítimas".
  Endpoint verificado en su documentación:
  `GET https://api.decolecta.com/v1/reniec/dni?numero=XXXXXXXX` con `Authorization: Bearer`.
  Devuelve `first_name`, `first_last_name`, `second_last_name`, `full_name`,
  `document_number`.

**Solo devuelve el nombre.** Ni fecha de nacimiento, ni dirección, ni por supuesto correo,
teléfono o licencia. De los diez campos de W08 ahorra dos.

## Cómo quedó implementado

Enriquecimiento **opcional**, nunca dependencia. Con el driver por defecto (`null`) el botón
ni siquiera se renderiza y la ficha se llena a mano igual que antes.

```
config/dni.php                     driver, token, timeout (3 s), caché (30 días)
app/Services/Dni/DniLookup.php     interfaz
app/Services/Dni/NullLookup.php    desactivado (por defecto)
app/Services/Dni/FakeLookup.php    datos de prueba, sin salir a internet
app/Services/Dni/DecolectaLookup.php
```

El driver se elige en `AppServiceProvider` con un `match`: añadir un proveedor nuevo es
escribir su clase y una línea más.

| Ruta | Qué hace |
|------|----------|
| `GET /api/dni-lookup` | Estado: si está habilitado y qué proveedor. El front lo consulta una vez para decidir si muestra el botón. |
| `GET /api/dni-lookup/{dni}` | La consulta. Limitada a 20 por minuto para no agotar la cuota. |

Detalles que importan:

- **Caché de 30 días** por DNI: el nombre de una persona no cambia, y así no se gasta cuota
  repitiendo consultas.
- **Si el DNI ya está registrado**, responde 409 sin salir a internet y sin pisar lo escrito.
- **Timeout de 3 segundos**: el alta nunca debe quedarse colgada esperando a un tercero.
- Cada fallo del proveedor (401, 429, caída) tiene su propio mensaje en español.

## Configuración

```
DNI_LOOKUP_DRIVER=null        # null | fake | decolecta
DNI_LOOKUP_TOKEN=
```

Para la sustentación está puesto en `fake`. Para usar el real: sacar el token en
https://decolecta.com/profile, ponerlo en `DNI_LOOKUP_TOKEN` y cambiar el driver a
`decolecta`.

## Nota legal

La Ley 29733 de protección de datos aplica. Para que un empleador registre a su propio
personal hay base legítima, pero conviene tenerlo presente: por eso el sistema no depende
de la consulta y funciona completo sin ella.

---

# 13 de septiembre de 2026 (3) — Asistencias

## 1. La zona horaria estaba mal

Al probar la marcación salió que el reloj del sistema marcaba las 16:02 (hora de Perú) y
Laravel las 21:02: `config/app.php` tenía `'timezone' => 'UTC'`. En un sistema de fichaje
eso desplaza **cinco horas** todas las marcaciones, y una marcación de la tarde se
registraría con la fecha del día siguiente.

Corregido: `config/app.php` lee `env('APP_TIMEZONE', 'America/Lima')` y `APP_TIMEZONE` está
en `.env` y en `.env.example`.

## 2. Ajuste al esquema

`add_schedule_to_attendances_table` — W12 lista la asistencia con su **TURNO**. La marcación
ocurre dentro de la ventana horaria de un turno, así que hay que guardar cuál fue.

## 3. La ventana de marcación

W11 describe una ventana por turno ("el turno Mañana admite marcación entre las 05:30 y las
06:30") y distingue Presente de Tardanza, pero sus propios números no cuadran: la fila de
W12 marca 06:41 como Tardanza, fuera de esa ventana. Se implementó la **forma** de la regla
con valores coherentes, en tres constantes de `Attendance`:

| Constante | Valor | Significado |
|-----------|-------|-------------|
| `OPENS_BEFORE` | 30 min | La marcación abre 30 minutos antes del inicio del turno |
| `GRACE_AFTER` | 10 min | Hasta 10 minutos después del inicio cuenta como **Presente** |
| `CLOSES_AFTER` | 120 min | Pasadas 2 horas ya no se admite marcación: el día queda como **Ausente** |

Con los turnos actuales eso da: Mañana 05:30–08:00, Tarde 11:30–14:00, Noche 17:30–20:00.
Cambiar las constantes cambia la regla en el terminal y en el mensaje que ve el trabajador.

`Schedule::forMarking()` elige el turno cuya ventana cubre el instante actual; si dos se
solapan, gana el que empieza más cerca.

## 4. API

| Ruta | Acceso | Para qué |
|------|--------|----------|
| `POST /attendances/mark` | **pública** | Terminal de fichaje (W11) |
| `GET /attendances/windows` | **pública** | Horarios que muestra el terminal |
| `GET /attendances/by-person` | con sesión | Pestaña "Por persona" de W12 |
| `GET /attendances/by-date` | con sesión | Pestaña "Por fecha" de W12 |

Las dos primeras son públicas **a propósito**: W11 no tiene navegación lateral, es un punto
de fichaje compartido donde cada trabajador se identifica con su DNI y su clave, como pide
el informe.

La marcación rechaza: DNI o clave incorrectos, trabajador inactivo, **sin contrato vigente**,
**de vacaciones**, y fuera de horario (devolviendo las ventanas de todos los turnos para que
el terminal las liste). Si ya marcó ese día, lo dice en vez de duplicar.

**Los ausentes son sintéticos:** no hay fila en la tabla para una falta. El reporte recorre
el rango y, por cada día hábil ya transcurrido sin marcación, emite una fila `falta` — que es
lo que W12 muestra con un guion en la hora. Día hábil = lunes a sábado; **cuando exista el
módulo de programación esto debería salir de los días realmente programados a la persona**.

## 5. Frontend

- **`/marcacion`** (W11) — ruta pública, fuera de `RequireAuth`, sin navegación lateral.
  Reloj en vivo, listado de horarios, acuse de recibo que se borra solo a los 12 segundos
  para que el siguiente trabajador no lo vea, y el estado "fuera de horario" con las
  ventanas de cada turno.
- **`/asistencias`** (W12) — dos pestañas, los cuatro indicadores, y exportación a CSV con
  BOM para que Excel respete los acentos.

## 6. Dos correcciones durante la verificación

1. **Los indicadores se calculaban sobre el filtro.** Al filtrar por "Ausente", "días
   hábiles" bajaba de 18 a 2. Ahora el resumen se calcula antes de aplicar el filtro: los
   indicadores describen el rango completo y el filtro solo recorta la tabla.
2. **El terminal rebotaba al login.** Con un token caducado en `localStorage` de ese
   navegador, la consulta global de `/me` daba 401 y el interceptor mandaba a `/login`
   incluso en rutas públicas. Un terminal compartido con un token viejo quedaba inutilizable.
   Ahora el interceptor limpia el token pero **no** redirige si la ruta actual es pública.

## Seeder

`AttendanceSeeder` siembra 30 días de marcaciones de forma **determinista** (sin azar, a
partir del id de la persona y el día del año), para que dos corridas den lo mismo y los
números del reporte sean reproducibles. Reparte a las personas entre los turnos y deja
1 de cada 9 días sin marcar y 1 de cada 5 con tardanza.

---

# 13 de septiembre de 2026 (continuación) — Personal, turnos y zonas

## 1. Ajustes al esquema que revelaron los wireframes

Al contrastar W08, W09 y W10 aparecieron tres huecos en el esquema de la mañana:

| Migración | Motivo |
|-----------|--------|
| `add_license_fields_to_users_table` | W08 pide **categoría de licencia** y avisa "licencia no vigente": sin guardar el vencimiento no se puede validar. Se añadió `license_category` y `license_expiration`. |
| `add_code_and_regime_to_contracts_table` | W09 lista los contratos por **N.° contrato** y su alta pide el **régimen**. Se añadió `code` (único) y `regime`. |
| `restructure_vacations_table` | W10 muestra **varios períodos por año** (2025 aparece dos veces, 15 días cada uno) con su propio estado. La tabla original guardaba un solo rango con `scheduled_days`/`pending_days` por fila, que no representa eso. Ahora cada fila es un período (`days`, `status`) y los totales del año se calculan sumando. |

## 2. Reglas de negocio, en el modelo

- `Contract::TEMPORARY_MONTHS = 2` y `duration_label` (la columna DURACIÓN de W09).
- `Contract::nextCode()` genera el correlativo `CON-AAAA-NNN`.
- `Vacation::usedDays()` / `pendingDays()` calculan el saldo del año sobre el tope de 30.
- `User::operationalStatus()` — la columna ESTADO de W07 es un **estado derivado** de
  contrato y vacaciones de hoy, no la columna `status` de la tabla. Devuelve
  `activo · vacaciones · culminado · sin_contrato · inactivo`.
- `User::hasValidLicense()` e `isDriver()`, para la validación de conductor.

## 3. API de personal

| Recurso | Rutas |
|---------|-------|
| Funciones (W06) | `apiResource usertypes` |
| Personal (W07, W08) | `apiResource people` + `GET /people/summary` + foto (`POST`/`DELETE /people/{p}/photo`) |
| Contratos (W09) | `apiResource people.contracts` |
| Vacaciones (W10) | `apiResource people.vacations` (el índice devuelve además el saldo del año) |
| Zonas (W13) | `apiResource zones` + `GET /districts`, CRUD de `sectors` |
| Turnos (W16) | `apiResource schedules` |

Validaciones probadas con curl, todas devolviendo el mensaje correcto:

- DNI de 8 dígitos y único; correo con formato válido y único.
- **Conductor sin licencia** y **conductor con licencia vencida** → 422.
- **Contrato eventual de 4 meses** → "solo puede pactarse por períodos de 2 meses"; el de
  2 meses pasa y su `duration_label` sale "2 meses".
- **Vacaciones a personal eventual** → "solo el personal nombrado o con contrato permanente".
- **20 días con 15 pendientes** → "solo quedan 15 días pendientes"; con 15 pasa y el saldo
  queda en 0.
- **Eliminar la función Conductor** → 409, es predefinida.
- Contratos y vacaciones que se solapan → 422.

## 4. Frontend

Nuevas pantallas: **W06** funciones (bajo `Maestros › Funciones del personal`), **W07**
listado con búsqueda y tres filtros, **W08** ficha con pestañas Datos / Contratos /
Vacaciones y panel de fotografía, **W09** contratos con sus reglas, **W10** vacaciones con
los cuatro indicadores y el cálculo de días en vivo, **W13** zonas (con alta de sector al
vuelo) y **W16** turnos.

Verificado en el navegador de punta a punta.

## 5. Tres correcciones durante la verificación

1. **Los `<select>` de edición salían vacíos.** El `reset()` de React Hook Form volcaba el
   valor antes de que llegaran las opciones, y el navegador lo descartaba. Ahora el
   formulario no se renderiza hasta que las listas están cargadas, y `useBrandModels`
   conserva la lista anterior mientras refresca (`placeholderData`). Afectaba también a la
   ficha de vehículo.
2. **"Culminado" para quien nunca tuvo contrato.** El administrador aparecía así en W07.
   `operationalStatus()` ahora distingue `sin_contrato`.
3. **Nombres sin tildes.** Los seeders se escribieron sin acentos y eso llegaba a la
   interfaz ("Monsefu", "Furgon", "Acompana"). Corregidos en los cuatro seeders.

**Detalle de MySQL que conviene recordar:** la colación es insensible a tildes, así que
`updateOrCreate(['name' => 'Mañana'])` **encuentra** la fila 'Manana' y no la renombra
(el nombre va en los atributos de búsqueda, no en los de actualización). Hubo que
renombrar las filas ya sembradas a mano. El lado bueno: `Rule::unique` sobre `name` trata
'Mañana' y 'Manana' como duplicados, que es lo deseable.

**Nota sobre la sesión:** el login revoca el token `web` anterior, así que solo hay una
sesión web por usuario a la vez. Entrar desde otro sitio cierra la anterior.

---

# 13 de septiembre de 2026 — Esquema, shell y módulo de vehículos

## 0. Decisiones tomadas al inicio

| Decisión | Elección | Motivo |
|----------|----------|--------|
| Autenticación del SPA | **Tokens Bearer** (Sanctum) | Un solo flujo para web y para React Native. Sin cookie de sesión ni CSRF, el front puede apuntar a `localhost:8000` o a `rsu-back.test` indistintamente. Cierra la decisión que quedó abierta en la sesión anterior. |
| Color de la interfaz | **Verde de reciclaje sobre base slate** | Los wireframes son en gris porque son una guía estructural; la app real lleva color. Se conserva la estructura del `.fig` (layout, tipografía, jerarquía) y el color aporta el semáforo de estados. |
| Estilos | **Tailwind CSS 4** | Pedido explícito. Se configura con `@tailwindcss/vite` y tokens en `@theme`. |

`bootstrap/app.php` ya no llama a `statefulApi()` y `config/cors.php` tiene
`supports_credentials: false`.

## 1. Esquema de base de datos (24 migraciones)

Se derivó del ER de referencia del PDF (decodificado desde la imagen de la última
página) más los requisitos que el ER no cubre.

**Geografía y zonas** — `departments`, `provinces`, `districts`, `sectors`, `zones`,
`zonecoords` (con `position` para ordenar los vértices del perímetro).

**Vehículos** — `brands`, `brandmodels`, `vehicletypes`, `vehiclecolors` (con
columnas `red`/`green`/`blue` porque el informe exige guardar el RGB; el ER solo
tenía nombre y descripción), `vehicles`, `vehicleimages` (con `profile` para la
imagen de perfil).

**Personal** — `usertypes` (con `slug` e `is_system` para proteger Conductor y
Ayudante, que el informe prohíbe eliminar), campos RSU añadidos a `users`
(`lastname`, `dni`, `birthdate`, `license`, `phone`, `address`, `status`,
`usertype_id`, `zone_id`), `contracts`, `vacations`, `attendances`.

**Rutas y programación** — `routes`, `routezones`, `schedules` (turnos),
`schedulings`, `vehicleroutes`, `vehicleoccupants`, `routepaths`.

### Decisión de diseño: la programación necesita dos tablas

El ER de referencia solo trae `vehicleroutes` (con un único `date_route`), pero el
informe pide programar con **días de la semana** y **fecha de inicio y término**,
permitir edición **individual o masiva**, e **iniciar el recorrido por día y turno**.
Eso no cabe en una sola fila por fecha, así que:

- **`schedulings`** es la programación maestra: zona, ruta, vehículo, turno,
  conductor, ayudante, `weekdays` (JSON con días ISO 1–7) y el rango de fechas.
  Es lo que edita W18, y la casilla "aplicar a toda la zona" de W19 actúa sobre ella.
- **`vehicleroutes`** es el recorrido de un día concreto, generado desde la
  programación maestra (`scheduling_id` + `date_route`, con índice único). Es lo que
  W20 pinta en el calendario y lo que W21 valida e inicia; su `status` recorre
  `programado → iniciado → bloqueado → finalizado`.
- **`vehicleoccupants`** cuelga de `vehicleroutes` (no de `vehicles` como en el ER)
  porque el personal se asigna por salida, y así `status` + `replaced_user_id`
  registran el reemplazo por personal de reserva que pide el informe.

Confirmado contra W18 antes de escribirlo.

## 2. Modelos Eloquent

Uno por tabla, con `$table` explícito donde el nombre no es el convencional
(`brandmodels`, `vehicletypes`, `vehiclecolors`, `vehicleimages`, `zonecoords`,
`usertypes`, `vehicleroutes`, `vehicleoccupants`, `routepaths`).

Las reglas de negocio viven en los modelos:

- `Vehicle::PLATE_REGEX` — los tres formatos del informe, y `scopeSearch`.
- `VehicleColor::hex` — atributo calculado a partir de R/G/B.
- `Contract::scopeActiveOn()` y `grantsVacations()` (solo nombrado y permanente).
- `Vacation::MAX_DAYS_PER_YEAR = 30` y `scopeCovering()`.
- `User::hasActiveContractOn()`, `isOnVacationOn()`, `attendedOn()` — las tres
  validaciones que gobiernan la programación y el inicio del recorrido.
- `Scheduling::occurrenceDates()` — expande el rango de fechas a los días marcados.

## 3. Seeders

- **`UbigeoSeeder`** — los 25 departamentos con su código INEI y Lambayeque completo
  (3 provincias, 38 distritos). Pendiente: cargar el resto del catálogo nacional.
- **`OperationSeeder`** — funciones (Administrador, Supervisor, Conductor, Ayudante,
  todas `is_system`), 3 turnos, 5 zonas con su sector y distrito, y el usuario
  administrador.
- **`VehicleCatalogSeeder`** — 5 marcas con 11 modelos, 5 tipos, 6 colores RGB y la
  flota de 11 vehículos del wireframe W04.

**Acceso de prueba:** `admin@rsu.usat.edu.pe` / `password` (también entra con el DNI
`10000001`).

## 4. API

`POST /api/login` acepta correo **o** DNI en el campo `username` — el informe pide
marcar asistencia con DNI y clave, y W01 usa un único campo "Usuario".

| Recurso | Rutas |
|---------|-------|
| Sesión | `POST /login`, `GET /me`, `POST /logout` |
| Maestros | `apiResource` de `brands`, `brandmodels`, `vehicletypes`, `vehiclecolors` |
| Vehículos | `apiResource vehicles` + `GET /vehicles/summary` |
| Imágenes | `POST /vehicles/{v}/images`, `PUT .../{img}/profile`, `DELETE .../{img}` |

`VehicleRequest` valida placa (formato + unicidad), código único, año entre 1950 y el
próximo, y que el **modelo pertenezca a la marca**; normaliza placa y código a
mayúsculas. El `destroy` de cada maestro devuelve **409** si hay registros que
dependen de él, en lugar de reventar con un error de clave foránea.

Verificado con curl: login por correo y por DNI, 401 sin token, las cuatro
validaciones disparando a la vez, alta válida y borrado.

## 5. Frontend

Tailwind 4 (`@tailwindcss/vite`), paleta `--color-brand-*` en `@theme` y las clases
`.field` / `.label` como base de formularios.

```
src/
  lib/          api.ts (token Bearer + interceptor 401), auth.tsx, useDebounced.ts
  components/   AppLayout.tsx (nav lateral + PageHeader + PageBody)
                ui/ Button, Field, Badge, Card, Modal, DataTable
  features/vehicles/  queries.ts (TanStack Query), schema.ts (Zod)
  routes/       LoginPage (W01), DashboardPage (W02), PlaceholderPage
                vehicles/ VehicleListPage (W04), VehicleFormPage (W05),
                          VehicleImages, VehicleMastersPage (W03)
```

Las 8 entradas de navegación del W02 están todas enrutadas; las de módulos aún no
implementados muestran un `PlaceholderPage` con la lista de sus pantallas.

**Verificado en el navegador:** login, panel con datos reales, listado con búsqueda
(debounced), tres filtros y paginación, alta completa, validación en cliente con
mensajes en español, borrado con confirmación, y los cuatro maestros con el editor
RGB y su muestra de color en vivo.

### Dos correcciones durante la verificación

El campo Color (opcional) daba error y Año vacío mostraba "no puede ser anterior a
1950". Ambos venían de `z.coerce.number('')` → `0`. Se normaliza con un `preprocess`
que convierte vacío en `undefined`; en el campo opcional el `.optional()` tiene que ir
**dentro** del preprocess, porque `z.coerce.number(undefined)` da `NaN`.

---

## Pendiente

1. **Programación** (W17–W21): la generación de `vehicleroutes` desde `schedulings`, las
   cuatro validaciones del informe, el calendario semanal y el inicio de recorrido con
   reemplazo por personal de reserva.
2. **Perímetro de zona** (W14) y **rutas de la zona** (W15): trazado sobre mapa y rutas
   con punto de partida y fin.
3. Cuando exista programación: que los "días hábiles" del reporte de asistencias salgan de
   los días realmente programados a cada persona, en vez de asumir lunes a sábado.
4. Completar el catálogo INEI de provincias y distritos del resto del país.
5. Reemplazar los indicadores en blanco del panel principal por datos reales.

---

# 11 de septiembre de 2026 — Wireframes y arranque de los proyectos

## 1. Revisión de los wireframes contra el informe

Se contrastaron las 29 pantallas de `RSU-Wireframes.fig` con `PROYECTO RSU RECICLAJE.pdf`.
El informe solo define la sección **"A. APLICACIÓN WEB"** con 4 módulos (vehículos, personal,
zonas, programación); no incluye una sección de aplicación móvil. La última página del PDF es
una imagen con el diagrama entidad-relación de referencia, que se decodificó para comparar
campos y relaciones.

**Resultado: la maqueta cubría ~90% de los requisitos**, incluidas casi todas las validaciones
de negocio (formato y unicidad de placa, año válido, código único, contratos eventuales de
2 meses, máximo 30 días de vacaciones, las 4 validaciones de programación, y el bloqueo del
recorrido por inasistencia).

Huecos detectados:

| # | Hueco | Pantalla |
|---|-------|----------|
| 1 | Sin acción de edición individual de programaciones | W17 / W18 |
| 2 | Maestros sin editar/eliminar ni formularios de alta (modelo con marca, tipo, color RGB) | W03 |
| 3 | Sin estados de validación para DNI y correo | W08 |
| 4 | Capacidad única, sin separar carga y ocupantes; zona sin sector ni área | W05 / W13 |
| 5 | Sin acciones editar/eliminar en filas de vehículos, personal, zonas, rutas y turnos | W04, W07, W13, W15, W16 |

## 2. Correcciones aplicadas al .fig

Todas verificadas exportando imagen de cada pantalla antes de guardar.

- **W17** — columnas comprimidas y nueva columna ACCIONES con "Editar" por fila. El subtítulo
  de **W18** pasó a "Programación › Crear o editar (edición individual)".
- **W03** — columna ACCIONES en marcas, modelos y colores; botones "+ Nuevo modelo",
  "+ Nuevo tipo" y "+ Nuevo color"; y dos formularios nuevos: **Nuevo modelo** (con selector de
  marca) y **Nuevo color (RGB)** con campos R/G/B y muestra.
- **W08** — error de formato en el campo Correo (borde marcado + mensaje), regla de DNI
  ("8 dígitos y no repetirse") y línea de validaciones al pie del panel.
- **W05** — "Capacidad (m³)" → **"Capacidad de carga (m³)"** y nuevo campo **"Capacidad de
  ocupantes"** (`load_capacity` / `occupant_capacity` del ER). N.° de motor reubicado.
- **W13** — el alta de zona ahora pide **Sector** y **Área (km²)** (`zones.sector_id`, `zones.area`).
- **W04, W07, W13, W15, W16** — acciones "Editar · Eliminar" (y "Ver · Editar · Eliminar" en
  personal) con su encabezado ACCIONES.

## 3. Pantallas móviles añadidas

El informe no define pantallas móviles, así que se derivaron de sus requisitos y de los botones
que en M01–M08 no llevaban a ninguna pantalla. La maqueta móvil pasó de 8 a **11 pantallas**:

- **M09 Detalle del recorrido asignado** — destino de "Ver detalle" (M03). Zona, ruta, turno,
  vehículo, conductor y ayudante, más mapa con perímetro y trayecto A→B.
- **M10 Personal de reserva** — destino de "Solicitar personal de reserva" (M04). Equivalente
  móvil del reemplazo de W21, con la regla de asistencia + contrato vigente + sin vacaciones.
- **M11 Mis asistencias** — consulta por rango de fechas con resumen y detalle de marcaciones
  (versión del trabajador del requisito que en web cubre W12).

También se añadió el botón "Ver mis asistencias" en M07 y se actualizó el mapa de flujo
(página 00): el bloque "Solicitar reserva al supervisor" pasó a ser **M10 Personal de reserva**,
y se sumaron los bloques M09 y M11.

## 4. Proyectos creados

```
C:\laragon\www\rsu-back                       Laravel 12.69.2 + Eloquent + Sanctum
D:\Sistemas\Tópicos\rsu-reciclaje\rsu-front   React 19 + Vite 8 + TypeScript
```

Entorno verificado: PHP 8.2.29, Composer 2.10.1, Node 24.16.0, npm 11.13.0, MySQL 8.0.30.

**Decisión de ORM:** Eloquent en el backend (es el ORM oficial de Laravel y el esquema del
informe encaja directo en sus relaciones). En React no aplica un ORM: su equivalente para datos
remotos es Axios + TanStack Query.
