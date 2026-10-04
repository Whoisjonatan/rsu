# Guía técnica

Cómo arrancar el proyecto, dónde está cada cosa, por qué está hecha así y con qué trampas
ya tropezamos. **Las pantallas del informe están implementadas** (el panel W02 se retiró a propósito); lo que queda son
mejoras, y viven en la última sección.

El detalle cronológico está en [`BITACORA.md`](BITACORA.md); la visión general del proyecto,
en [`README.md`](../README.md).

> **Desde el 4 de octubre de 2026 el front es Blade y vive en este mismo proyecto.** El SPA
> en React (`rsu-front`) quedó reemplazado; el API sigue igual para la app móvil.

---

## 1. Arrancar en dos minutos

```bash
# 1. MySQL desde el panel de Laragon (botón "Iniciar todo")

# 2. Todo desde C:\laragon\www\rsu-back
npm run build          # compila estilos y JS (o `npm run dev` mientras se editan vistas)
                       # → http://rsu-back.test  (vhost de Laragon)

# Sin Laragon: `php artisan serve` → http://localhost:8000
# `composer dev` levanta servidor + Vite + logs a la vez
```

Si una vista nueva usa clases de Tailwind que no salían en ninguna otra, hay que volver a
compilar (`npm run build`) o tener `npm run dev` corriendo: Tailwind solo genera las clases
que encuentra en los `.blade.php` y `.js`.

Si la base quedó rara: `php artisan db:seed` la repone — los seeders son **idempotentes**,
se pueden repetir sin duplicar nada (verificado). Para empezar de cero,
`php artisan migrate:fresh --seed`.

El orden de siembra **importa** y está fijado en `DatabaseSeeder`: Ubigeo → Operation (usa
distritos) → VehicleCatalog → Personnel (usa funciones y zonas) → Attendance (usa turnos y
personas). Si se añade un seeder nuevo, respetar esa cadena.

**Credenciales**

| Para qué | Usuario | Clave |
|----------|---------|-------|
| Panel web | `admin@rsu.usat.edu.pe` o DNI `10000001` | `password` |
| Marcación (terminal) | cualquier DNI del personal sembrado | `password` |

**URLs que conviene tener a mano**

- `http://rsu-back.test` — el panel
- `http://rsu-back.test/marcacion` — terminal de fichaje, **pública**, sin sesión
- `http://rsu-back.test/api/ping` — comprobar que API y base responden

---

## 2. Estado

| Módulo | Pantallas | Estado |
|--------|-----------|--------|
| Acceso | W01 | ✅ |
| Panel principal | W02 | Retirado (4/10/2026): repetía lo que muestra Inicio |
| Vehículos | W03, W04, W05 | ✅ |
| Personal | W06–W10 | ✅ |
| Asistencias | W11, W12 | ✅ |
| Zonas | W13 | ✅ |
| Perímetro de zona | W14 | ✅ |
| Rutas de la zona | W15 | ✅ |
| Turnos | W16 | ✅ |
| **Programación** | **W17–W21** | ✅ |

**Las pantallas del informe están implementadas**, salvo W02, que se retiró a propósito. Lo que queda son mejoras, en la
sección 10.

Números actuales: 25 departamentos · 3 provincias · 38 distritos (Lambayeque completo) ·
5 zonas · 11 vehículos · 11 personas · 10 contratos · 3 períodos de vacaciones ·
195 marcaciones · 3 turnos.

Las rutas y las programaciones salen vacías a propósito: son datos de operación, no de
catálogo, y se crean usando el sistema.

---

## 3. Mapa del código

Todo en `C:\laragon\www\rsu-back`. 33 migraciones (4 son de Laravel), 23 modelos, 5 seeders.

### Reglas de negocio — compartidas por el panel y el API

```
app/Models/              Un modelo por tabla. Las reglas de negocio viven aquí:
                         User::hasActiveContractOn() · isOnVacationOn() · attendedOn()
                         User::scopeFilter() (filtros de W07) · staffSummary() · findForLogin()
                         Contract::TEMPORARY_MONTHS · nextCode() · duration_label
                         Vacation::MAX_DAYS_PER_YEAR · usedDays() · pendingDays() · summaryFor()
                         Scheduling::occurrenceDates() · syncVehicleRoutes() · saveAndSync()
                         Scheduling::scopeFilter() · attachDayStatus() · summary()   ← W17-W20
                         VehicleRoute::readiness() · availableReserves() · daySummary() ← W21
                         Zone::replaceCoords() · coordsRules()                       ← W14
                         Vehicle::createWithImages() · summary() · PLATE_REGEX
                         <Modelo>::deletionBlocker()  por qué no se puede borrar, o null

app/Http/Requests/       Un FormRequest por formulario: Brand, BrandModel, VehicleType,
                         VehicleColor, Vehicle, UserType, Person, Contract y Vacation
                         (con las reglas de contratación y vacaciones del informe), Zone,
                         Sector, Route (duplicados por nombre y extremos), Schedule,
                         Scheduling (las cuatro validaciones de W18)
app/Services/Scheduling/ SchedulingRules: las reglas de W18, que usan también la
                         checklist viva y la edición masiva (W19)
app/Services/Attendance/ AttendanceService: marcación (W11) y reportes (W12)
app/Services/Dni/        Consulta de DNI: interfaz + drivers null/consultasapi
app/Support/Status.php   Etiqueta y color de cada estado (vehículo, recorrido, persona…)
```

### Panel web (Blade)

```
routes/web.php                   Las pantallas, con sesión. Mismas URL que tenía React.
app/Http/Controllers/Web/        Un controlador por pantalla o recurso. Devuelven vistas
                                 o redirigen con un aviso flash; nunca JSON.
resources/views/
  components/layouts/app.blade   Barra lateral + cabecera de cada pantalla (<x-layouts.app>)
  components/layouts/guest.blade Login y terminal de marcación
  components/                    card · stat-card · badge · field · modal · crud-form ·
                                 confirm-delete · row-actions · search-bar · map · flash…
  <módulo>/                      Una carpeta por módulo: vehicles, people, attendances,
                                 zones, schedules, schedulings, home, auth
  pagination/rsu.blade.php       El pie "Mostrando 1–15 de 42"
  errors/                        404, 419, 500… en español
resources/js/
  app.js                         Arranca Alpine y registra los componentes
  bootstrap.js                   Cliente axios del API, con la sesión
  components/crud.js             Alta/edición/baja en modales (ver abajo)
  components/map-picker.js       Mapa Leaflet de W14 y W15
  components/scheduling-form.js  W18: precarga por zona, checklist viva y resumen
  components/bulk-edit.js        W19: simulación y aplicación
  components/start-route.js      W21: verificaciones, reemplazo e inicio
resources/css/app.css            Tailwind 4, tokens de la paleta y clases .btn/.field/.data-table
lang/es/validation.php           Mensajes de validación en español
```

### API para la app móvil

```
routes/api.php                   Todo el API, con nombres de ruta "api.*"
app/Http/Controllers/Api/        Devuelven JSON; usan los mismos FormRequest y modelos
app/Http/Resources/              La forma del JSON
```

**Patrón a seguir al añadir un módulo:**

1. Las reglas en un **FormRequest** (y en el modelo si hay lógica), nunca dentro de un
   controlador: así el API y la vista Blade validan exactamente igual.
2. `deletionBlocker()` en el modelo. El controlador web lo devuelve como aviso
   (`back()->with('error', …)`); el del API, como **409**.
3. Controlador web que devuelve la vista y redirige con `with('success', …)` al guardar.
4. La vista usa `<x-layouts.app>` + `<x-card>` + `table.data-table`, nunca HTML suelto.
5. Si es un catálogo con modales, el componente Alpine `crud` y `<x-crud-form>` ya resuelven
   abrir, editar, reabrir tras un error de validación y confirmar el borrado.

### El patrón de los modales (`crud`)

Los formularios de los modales son **HTML normal que se envía al servidor**; Alpine solo
abre el modal, lo rellena y elige la URL. Si la validación falla, Laravel redirige de vuelta
con lo escrito (`old()`) y los errores. Cada formulario manda un campo oculto `_modal` con su
nombre: si coincide al volver, el modal **se reabre tal y como estaba**, con el error bajo
cada campo. Los campos de esos modales usan `<x-field live>`, que lee el error del estado de
Alpine para no arrastrar errores de otra fila al abrir otro registro.

---

## 4. Decisiones ya cerradas

No hace falta volver a discutirlas.

| Decisión | Por qué |
|----------|---------|
| **Front en Blade**, dentro del mismo proyecto | Una sola carpeta que mantener (4/10/2026) |
| **Sesión por cookie** para el panel, **tokens Bearer** para el API | Blade trabaja con sesión y CSRF; la app móvil sigue con token |
| **Lo vivo va al API con la sesión** (`statefulApi()`) | DNI, checklist de W18, W19 y W21 usan los mismos endpoints que el móvil: sin reglas duplicadas |
| **Alpine.js**, no un framework de SPA | Modales, mapas y checklist; el resto lo pinta el servidor |
| **Tailwind 4** con tokens en `@theme` | Pedido explícito |
| **Verde de reciclaje sobre slate** | Los wireframes son guía de estructura, no de color |
| **La programación usa dos tablas** | Ver abajo |
| **Eloquent** | ORM oficial de Laravel |
| Consulta de DNI **opcional** | RENIEC no tiene API pública; no puede ser dependencia |

### Por qué la programación necesita dos tablas

El ER de referencia del PDF solo trae `vehicleroutes` con un único `date_route`, pero el
informe pide **días de la semana** + **fecha de inicio y término** + edición **individual o
masiva** + **iniciar el recorrido por día y turno**. Eso no cabe en una fila por fecha:

- **`schedulings`** — la programación maestra: zona, ruta, vehículo, turno, conductor,
  ayudante, `weekdays` (JSON con días ISO 1–7) y el rango de fechas. Es lo que edita W18.
- **`vehicleroutes`** — el recorrido de **un día concreto**, generado desde la maestra
  (`scheduling_id` + `date_route`, con índice único). Es lo que W20 pinta en el calendario y
  lo que W21 valida e inicia. Su `status` recorre `programado → iniciado → bloqueado → finalizado`.
- **`vehicleoccupants`** — el personal de esa salida. Cuelga de `vehicleroutes`, **no** de
  `vehicles` como en el ER, porque el personal se asigna por salida; así `status` +
  `replaced_user_id` registran el reemplazo por personal de reserva.

**Cuando el ER del PDF y el texto del informe se contradigan, manda el texto del informe.**
El ER es "de referencia" y está incompleto.

---

## 5. Programación (W17–W21)

El módulo más grande del sistema: la programación maestra, el calendario y el inicio de
recorrido. Dos tablas, cinco pantallas y las cuatro validaciones del informe.

### Las piezas de negocio que lo sostienen

```php
Scheduling::occurrenceDates()   // expande weekdays × rango de fechas → lista de días
User::hasActiveContractOn($fecha)
User::isOnVacationOn($fecha)
User::attendedOn($fecha)
```

### Lo que ya hace la API de programación

`GET|POST /api/schedulings`, `GET|PUT|DELETE /api/schedulings/{id}`,
`GET /api/schedulings/summary`, `GET /api/schedulings/defaults/{zone}` (la precarga),
`POST /api/schedulings/validate` (la checklist viva de W18) y
`POST /api/schedulings/bulk-update` (la edición masiva de W19).

**`GET /api/schedulings?date=YYYY-MM-DD`** añade a cada fila `day_status`: el estado del
recorrido de ESE día, que es lo que pinta la columna ESTADO de W17 — no el activa/inactiva
de la programación. Vale `programado|iniciado|bloqueado|finalizado`, o `no_aplica` si ese
día de la semana no toca, o `sin_recorrido` si toca pero no se generó (fecha pasada).

Guardar una programación **genera los `vehicleroutes` de cada día** con sus ocupantes.
Tres reglas en `Scheduling::syncVehicleRoutes()`, decididas al implementarlo:

- **No se programa hacia atrás**: solo se generan recorridos de hoy en adelante, para no
  inventar recorridos pasados que nunca ocurrieron.
- **Lo que ya arrancó no se toca**: un recorrido iniciado, bloqueado o finalizado es
  historia; reeditar la programación no lo borra ni lo mueve. Verificado.
- **Un reemplazo de reserva no se deshace**: `VehicleRoute::syncOccupants()` solo toca a
  los ocupantes en estado "asignado".

`DELETE` devuelve **409** si algún recorrido ya arrancó: esa programación se desactiva,
no se borra.

### Las cuatro validaciones del informe (W18) — ya implementadas en `SchedulingRequest`

1. **Solo personal contratado.** Se comprueban **todas** las fechas que genera la
   programación, no solo la de inicio, y el aviso dice cuáles fallan.
2. **No personal con vacaciones.** Igual: nombra las fechas exactas.
3. **Precargar según la zona** → `GET /api/schedulings/defaults/{zone}`. El personal sale de
   `users.zone_id` (dato explícito de la ficha); **el vehículo y el turno no tienen zona en
   el esquema**, así que se toman de la última programación de esa zona.
4. **Sin solapes.** El informe dice "que coincidan en Turno, Vehículo, Conductor y
   Ayudantes"; se aplica **por recurso, no a los cuatro a la vez**, porque exigir que
   coincidieran los cuatro dejaría pasar al mismo conductor con dos vehículos a la misma
   hora. Coincidir = solaparse en rango de fechas **y** compartir algún día de la semana.

Las tres primeras y el solape están verificadas contra la base sembrada (personal #4 sin
contrato, #5 de vacaciones).

### Iniciar el recorrido (W21) — implementado en `VehicleRouteController`

Los cuatro requisitos del informe, todos verificados contra la base sembrada:

- **Asistencia del día** → `VehicleRoute::readiness()` devuelve `{ready, blockers, occupants}`,
  no un booleano: W21 tiene que explicar qué falta y a quién reemplazar, no solo
  deshabilitar un botón.
- **Disponibilidad del vehículo**: que esté operativo y que no haya salido ya en otro
  recorrido del mismo día y turno.
- **Reemplazo por reserva** → `PUT /vehicleroutes/{id}/occupants/{occupant}`. El saliente NO
  se borra: queda en "reemplazado" con `replaced_user_id`. Al reemplazo se le exige lo mismo
  que al titular (asistencia, contrato, vacaciones).
  `GET /vehicleroutes/{id}/reserves?usertype_id=` lista solo a quien puede cubrir de verdad.
- **Iniciar en plural** → `POST /vehicleroutes/start-batch` con día + turno: arranca los que
  pueden y devuelve los pendientes **con su motivo**. Es la lectura literal del informe
  ("las programaciones cuyos turnos sí puedan iniciar").

Además `POST .../block` (con motivo) y `POST .../finish` para cerrar el ciclo de estados
`programado → iniciado → finalizado`, con `bloqueado` para el que no sale.

### Qué hace cada pantalla

| Pantalla | Qué resuelve | Endpoint principal |
|----------|--------------|--------------------|
| **W17** Listado | Filtra por zona, turno, estado y **fecha**. La columna ESTADO es la del recorrido de esa fecha, no la de la programación. | `GET /schedulings?date=` |
| **W18** Alta/edición | Seis selectores, precarga por zona y checklist viva de las cuatro validaciones. | `POST /schedulings/validate` |
| **W19** Edición masiva | Marca **qué campos** cambiar y los aplica a varias. Avisa antes de aplicar qué filas se rechazarían. | `POST /schedulings/bulk-update` (+ `dry_run`) |
| **W20** Calendario | Semana de lunes a domingo, con navegación y el badge **Vacaciones** derivado. | `GET /vehicleroutes?from=&to=` |
| **W21** Iniciar | Modal con verificaciones, reemplazo por reserva y "posponer". | `GET /vehicleroutes/{id}` |

Dos detalles que no se ven en la tabla:

- **El personal ausente cuenta personas, no asignaciones**: quien está en dos turnos del
  mismo día y no marca sigue siendo un solo ausente.
- **La edición masiva responde 207** cuando unas pasan y otras no, con el motivo de cada
  rechazo. Es lo que la propia pantalla promete por escrito.

**Dos decisiones de W18 que conviene no deshacer:**

- **Los selectores de personal NO filtran por estado.** Tentador filtrar a "activo", pero
  quien esté de vacaciones hoy puede estar libre en el rango que se programa, y al editar
  una programación vieja su titular tiene que conservar su `<option>` — si no, el navegador
  descarta el valor y el campo sale vacío. De filtrar ya se encarga la validación, que mira
  las fechas reales. Por lo mismo, al editar, el vehículo asignado sigue en la lista aunque
  ya no esté operativo (`SchedulingController::formData()`).
- **Los desplegables traen la lista completa** desde el controlador, no la paginada de los
  listados: un desplegable no puede quedarse a medias.
- **El combo de ruta muestra solo las rutas de la zona elegida** (en React salía vacío).

**Ojo con la zona del vehículo:** `users.zone_id` existe, pero `vehicles` **no tiene zona**.
Cualquier cosa que necesite "el vehículo de la zona" tiene que derivarlo de la última
programación de esa zona, o pedir una migración.

### Lo que aportaron los wireframes (revisados el 13/09/2026)

El informe describe el QUÉ; los wireframes trajeron seis cosas que el texto no dice:

1. **Código `PRG-NNNN`** — W17 lo usa como primera columna y W19 para identificar filas.
   Migración `add_code_to_schedulings_table` + `Scheduling::nextCode()`. Lo pone el modelo
   en `creating`, así que vale igual desde la API, un seeder o tinker.
2. **La columna ESTADO de W17 es del recorrido, no de la programación** → `?date=` y
   `day_status`.
3. **Vacaciones registradas DESPUÉS de programar** → `Scheduling::vacationConflicts()`.
   Era un agujero real: las validaciones de W18 corren al guardar, pero unas vacaciones
   posteriores dejaban la programación en pie como si nada. Es el aviso bajo la tabla de
   W17 y el badge "Vacaciones" del calendario. **Se deriva al vuelo, no se materializa**
   en `status`: las vacaciones se cancelan, y un estado guardado habría que deshacerlo.
4. **W18 valida en vivo** (tres ✓ y un ⚠ a la vez), no al guardar → `/schedulings/validate`
   responde **siempre 200** con el estado de las cuatro. No usa el FormRequest a propósito:
   un FormRequest aborta con 422 y no deja construir la checklist.
5. **W19 edita por campo, no por fila**: marca qué cambiar (turno, vehículo, ayudante,
   fecha de término) y lo aplica a las seleccionadas. Responde **207** cuando unas pasan y
   otras no, con el motivo de cada rechazo.
6. **W21 muestra la hora de marcación** ("presente 06:04 a. m.") → `/reserves` la devuelve.

**Ojo con el campo:** la hora de marcación es `attendances.check_in`, **no** `time_in`.
Un `create` con el nombre equivocado no falla: Eloquent descarta la clave en silencio.

Por esto las reglas viven en `app/Services/Scheduling/SchedulingRules.php` y no en el
FormRequest: hay tres entradas a la misma validación (alta/edición, checklist viva y
edición masiva) y ninguna puede quedarse con una copia propia.

**Discrepancia a resolver con el cliente:** W18 lleva dentro el checkbox "aplicar a todas
las programaciones de la zona" y además existe W19 como pantalla completa. Son dos caminos
a lo mismo; usan el mismo endpoint, pero el informe solo pide "individual o masiva".

El perímetro de zona y las rutas están en la sección 6.

---

## 6. Mapas: perímetro (W14) y rutas (W15)

**Leaflet + OpenStreetMap.** Sin API key, sin cuenta y sin coste.
`resources/js/components/map-picker.js` (componente Alpine `mapPicker`) lo envuelve y lo
comparten las dos pantallas (`mode: 'polygon'` para el perímetro, `mode: 'endpoints'` para
las rutas). El contenedor es `<x-map>`. El perímetro viaja al servidor como JSON en un campo
oculto (`coords_json`); los extremos de la ruta, en cuatro campos ocultos.

```
PUT  /api/zones/{zone}/coords    guarda el perímetro entero
GET|POST|PUT|DELETE /api/routes  CRUD de rutas
```

### Lo que NO es una ruta

Esto es lo que más confusión causa, así que conviene tenerlo claro:

| Tabla | Qué guarda | De qué cuelga |
|-------|-----------|---------------|
| `routes` | La ruta **planificada**: nombre + punto de partida + fin | De la zona (`routezones`) |
| `routepaths` | Los puntos por donde **realmente pasó** el vehículo | De `vehicleroutes` (un día concreto) |

**El GPS del vehículo y el "marcar punto por punto" son los dos la misma tabla**
(`routepaths`), y ninguna de las dos es W15: rellenan la traza de un recorrido ya
ejecutado, no la ruta planificada. Por eso W15 se pudo hacer entera sin tocar nada
del móvil. Cuando exista la app, es añadir `POST /vehicleroutes/{id}/paths` y pintar
la polilínea — el modelo ya está bien separado y no hay que rehacer nada.

Por eso también **la línea entre A y B se dibuja recta**: `routes` no guarda el
camino intermedio. Fingir una curva sería dibujar datos que no existen.

### Rutas duplicadas

Se valida por partida doble en `RouteRequest`, y las dos bloquean:

- **Nombre repetido** en una zona que ya lo usa. Comparación sin distinguir
  mayúsculas, así que "ruta norte" choca con "Ruta Norte".
- **Mismos extremos** (a menos de **50 m**, con Haversine) que otra ruta de esa zona.
  El margen es a propósito: exigir coordenadas idénticas al decimal no serviría de
  nada porque nadie hace clic dos veces en el mismo píxel. El mensaje nombra la ruta
  con la que choca, para reutilizarla en vez de duplicarla.

El mismo nombre **sí** se permite en zonas distintas: "Ruta Norte" de La Victoria y
"Ruta Norte" de José Leonardo Ortiz son rutas diferentes.

### Trampas de Leaflet ya resueltas

- **Los marcadores por defecto salen rotos** con bundlers (son PNG que Vite no
  resuelve). `MapPicker` usa `L.divIcon` con HTML propio: sin peticiones y con el
  color de la paleta.
- **El encuadre automático peleaba con el dibujo.** Reencuadrar al añadir el primer
  vértice daba un salto de zoom que descolocaba los clics siguientes. Ahora solo
  encuadra cuando los puntos llegan **de golpe** (0 → varios = datos cargados);
  de uno en uno es el usuario dibujando y no se toca la vista.
- **En `mode="endpoints"` los puntos van EN PARES.** La vista general pinta varias
  rutas a la vez; tratarlos como lista plana dejaba todo menos el primero como "B"
  y no dibujaba ninguna línea.
- **Leaflet no soporta los proxies de Alpine**: el mapa y sus capas viven en variables del
  cierre de `map-picker.js`, fuera del estado reactivo; solo `points` es reactivo.
- **Un mapa dentro de un modal recién abierto mide 0×0.** Un `ResizeObserver` llama a
  `invalidateSize()` cuando el contenedor ya tiene tamaño (el modal de W15).

### Sin internet no hay mapa de fondo

Los *tiles* de OpenStreetMap se descargan de la red. Sin conexión el fondo sale gris,
aunque **los puntos y polígonos se dibujan igual y se guardan igual**. El componente
lo detecta (`tileerror`) y lo dice en pantalla en vez de dejar un rectángulo mudo.

Es el mismo riesgo que la consulta de DNI: si el día de la sustentación falla el wifi,
la pantalla sigue siendo usable y las coordenadas quedan a la vista en la lista lateral.
---

## 7. Consulta de DNI

Está **activa** con el servicio contratado (el mismo que usa Crinver) en el `.env`.

```
DNI_LOOKUP_DRIVER=consultasapi   # consultasapi | null (apagado)
DNI_LOOKUP_TOKEN=...
DNI_LOOKUP_API_KEY=...
```

- **RENIEC no tiene API pública**: su servicio oficial exige convenio institucional, así que
  todo proveedor es un intermediario. **apis.net.pe ya retiró la consulta de DNI** por
  protección de datos. Se usa el servicio **contratado**, el mismo de Crinver; es el único
  driver real (Decolecta y el simulado se eliminaron).
- **Solo devuelve apellidos y nombres.** Ni fecha de nacimiento, ni dirección, ni correo.
- Es opcional por diseño: con driver `null` el botón ni se renderiza y la ficha se llena a
  mano igual. Añadir otro proveedor = una clase nueva y una línea en el `match` de
  `AppServiceProvider`.
- Caché por DNI: 30 días si se encuentra, 1 día si no (un "no existe" también gasta consulta).
  La clave lleva el driver, así que cambiar de proveedor no sirve respuestas viejas.
- **Si la API cae, el alta sigue completa a mano.** El botón solo rellena dos campos; todo
  fallo del proveedor devuelve un mensaje que dice "Escribe los datos a mano", y para un
  mantenimiento largo se pone `DNI_LOOKUP_DRIVER=null` y el botón ni se renderiza.
- **Las credenciales están en `.env`, que está en `.gitignore`.** Son de un servicio pagado:
---

## 8. Trampas ya descubiertas

Esto es lo que más tiempo ahorra. Todas costaron un rato.

### Zona horaria

`APP_TIMEZONE=America/Lima` en el `.env`. Laravel viene en **UTC** y eso desplaza cinco horas
todas las marcaciones: una de la tarde se registraba con la fecha del día siguiente. Si
alguna vez se ve una hora rara, mirar esto primero.

### MySQL ignora las tildes al comparar

La colación es insensible a acentos, así que `updateOrCreate(['name' => 'Mañana'])`
**encuentra** la fila `'Manana'` y **no la renombra** (el nombre va en los atributos de
búsqueda, no en los de actualización). Hubo que renombrar a mano lo ya sembrado.

El lado bueno: `Rule::unique` sobre `name` trata 'Mañana' y 'Manana' como duplicados, que es
lo deseable.

### Los nombres de ruta del API pisaban los de la web

`apiResource('vehicles')` registra nombres como `vehicles.store`, igual que las rutas web, y
como `api.php` se carga después **gana**: `route('vehicles.store')` apuntaba a
`/api/vehicles` y el formulario de alta acababa en el API. Por eso todo `api.php` va dentro
de `Route::name('api.')`. **Una ruta API nueva queda dentro de ese grupo.**

### `crypto.randomUUID()` no existe en `rsu-back.test`

Solo está disponible en contextos seguros (https o `localhost`). El vhost de Laragon se sirve
por http, así que las claves de las fotos en borrador usan un contador.

### `@php(...)` en línea antes de un bloque `@php … @endphp`

Blade se traga todo lo que hay entre el `@php(` en línea y el siguiente `@endphp` y la vista
revienta con un "syntax error" engañoso. Las vistas usan siempre la forma de bloque.

### Los `<select>` dependientes con opciones de Alpine

Cuando las `<option>` las pinta un `x-for` (modelos de una marca, sectores de un distrito,
rutas de una zona), cada opción lleva `:selected` además del `x-model` del `<select>`: si no,
al abrir una ficha el valor guardado se pierde porque su opción todavía no existía.

### Las fotos elegidas no sobreviven a un error de validación

Ningún navegador repone un `<input type="file">` al volver con errores. El alta de vehículo
lo avisa en pantalla ("vuelve a agregarlas"). El resto de campos sí vuelven, con `old()`.

### `store()` del API debe devolver el recurso envuelto

`response()->json(new XResource(...), 201)` devuelve el objeto **desnudo**, mientras que
devolver el recurso desde `update()` lo envuelve en `data`. Esa inconsistencia hacía que una
persona **se guardara** pero la pantalla dijera "No se pudo guardar la ficha".

Usar siempre:

```php
return (new XResource($modelo))->response()->setStatusCode(201);
```

Y en el cliente, que un fallo de forma nunca se disfrace de fallo de guardado.

### Un solo token por usuario en el API

`Api\AuthController::login` revoca el token `web` anterior: entrar al API desde otro sitio
cierra la sesión del token anterior. No afecta al panel Blade, que usa la sesión por cookie.

### El 401 y el terminal de marcación

`resources/js/bootstrap.js` manda al login cuando el API responde 401, **salvo en
`/marcacion`**, que es pública. Si se añade otra pantalla pública que consulte el API, hay
que excluirla igual.

### Comandos de shell largos se truncan

Escribir varios archivos en una sola llamada con heredocs falla con un
`unexpected EOF` engañoso: el comando se corta por longitud. Partir en llamadas más pequeñas.

---
---

## 9. Antes de dar algo por terminado

```bash
npm run build                 # sin errores
vendor/bin/pint --dirty       # formato del PHP tocado
php artisan route:list        # que todas las rutas resuelvan su controlador
php artisan view:clear        # si una vista cambiada parece no cambiar
```

Y verificar en el navegador, no solo que compile: **los bugs más serios del proyecto (la zona
horaria, el "no se pudo guardar", los `<select>` vacíos, el alta que acababa en el API)
compilaban perfectamente.** Una vista Blade con un error solo falla al abrirla.

Si se toca un controlador del API, probarlo también con token (como la app móvil):
`POST /api/login` y luego las llamadas con `Authorization: Bearer …`.


---

## 10. Mejoras pendientes

No son huecos: las pantallas del informe están implementadas. Es lo que haría mejor al
sistema si hay tiempo, en orden de valor.

### 1. Días hábiles reales en el reporte de asistencias

`AttendanceService::isWorkingDay()` asume **lunes a sábado** para todo el personal. Ahora
que existe la programación, los días hábiles de cada persona se pueden sacar de los días que
realmente tiene programados (`schedulings.weekdays` + su rango de fechas).

Cambia el porcentaje de asistencia de W12: hoy penaliza a quien nunca estuvo programado un
sábado. Es la mejora con más impacto real sobre un número que se muestra.

### 2. Traza real del recorrido (GPS)

`routepaths` ya existe y cuelga de `vehicleroutes`. Falta `POST /vehicleroutes/{id}/paths`
para recibir los puntos y pintar la polilínea sobre el mapa de W20.

**Depende de la app móvil**, que es quien enviará las coordenadas. Mientras tanto no hay
deuda: el modelo ya separa la ruta planificada de la traza real (ver la sección 6).

### 3. Catálogo INEI completo

Hoy solo Lambayeque tiene provincias y distritos en detalle; el resto del país tiene el
departamento pero no el desglose. Solo importa si el sistema sale de Lambayeque.

### 4. Exportar el listado de programaciones

El wireframe W17 tiene un botón "Exportar" que no llegó a especificarse (¿Excel, PDF?). No
está implementado porque no hay requisito detrás; el informe no lo menciona. Si se pide, el
reporte de asistencias (W12) ya exporta CSV con `?export=csv` y sirve de modelo.

### 5. Pruebas automáticas

`tests/` solo tiene los ejemplos de Laravel. Con las reglas ya concentradas en FormRequests y
modelos, las pruebas de mayor valor serían las de `SchedulingRules`, `ContractRequest`,
`VacationRequest` y `VehicleRoute::readiness()`.

### Y una decisión que no es técnica

**W18 lleva dentro el checkbox "aplicar a todas las programaciones de la zona" y además
existe W19 como pantalla completa de edición masiva.** Son dos caminos a lo mismo y los dos
usan el mismo endpoint, así que funcionan. Pero el informe solo pide "individual o masiva":
conviene preguntar si el wireframe los duplicó a propósito antes de defender la pantalla.
