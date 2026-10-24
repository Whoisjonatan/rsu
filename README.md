# RSU Reciclaje

Sistema informático para el proyecto de reciclaje del área de RSU — Escuela de Ingeniería de
Sistemas y Computación, USAT.

**Todo vive en esta carpeta:** el panel web (vistas Blade) y el API REST que consumirá la app
móvil. No hay un front aparte que levantar.

```
C:\laragon\www\rsu-back     Laravel 12 + Eloquent + Blade + Alpine.js + Tailwind 4
  ├── resources/views/       El panel web
  ├── routes/web.php         Pantallas, con sesión por cookie
  └── routes/api.php         API REST para la app móvil, con token Bearer de Sanctum
```

## Requisitos

- PHP 8.2+ y Composer (vienen con Laragon)
- Node.js 20+ (solo para compilar los estilos y el JavaScript)
- MySQL 8 (servicio de Laragon)

## Cómo levantar el proyecto

1. **Iniciar MySQL** desde el panel de Laragon (botón *Iniciar todo*).

2. **La primera vez**:

   ```bash
   composer install
   npm install
   php artisan migrate --seed   # crea las tablas y carga los datos iniciales
   php artisan storage:link     # para servir las imágenes de vehículos y fotos
   ```

3. **Compilar el front** (estilos y JavaScript):

   ```bash
   npm run build     # una vez; o `npm run dev` mientras se editan vistas, para recargar al vuelo
   ```

4. **Abrir** `http://rsu-back.test` (el vhost de Laragon). Sin Laragon, `php artisan serve`
   y `http://localhost:8000`. `composer dev` levanta servidor, Vite y logs a la vez.

**Acceso de prueba:** `admin@rsu.usat.edu.pe` / `password` (también entra con el DNI
`10000001`). Todo el personal sembrado usa la misma clave.

**Terminal de marcación:** `/marcacion` — pantalla pública, sin sesión iniciada, pensada para
un equipo compartido en el punto de fichaje. El trabajador se identifica con su DNI y su clave.

## Estado

Las pantallas del informe están implementadas en Blade (W01, W03–W21). El panel principal (W02) se retiró el 4/10/2026 por decisión del equipo: repetía lo que ya muestra Inicio.

| Módulo | Pantallas |
|--------|-----------|
| Acceso | W01 · Inicio |
| Vehículos | W03 catálogos · W04 listado · W05 ficha con imágenes |
| Personal | W06 funciones · W07 listado · W08 ficha · W09 contratos · W10 vacaciones |
| Asistencias | W11 terminal de marcación · W12 reporte (con exportación CSV) |
| Zonas | W13 zonas · W14 perímetro · W15 rutas (mapas con Leaflet) |
| Programación | W16 turnos · W17 listado · W18 ficha · W19 edición masiva · W20 calendario · W21 inicio de recorrido |

## Decisiones técnicas

| Capa | Herramienta | Motivo |
|------|-------------|--------|
| ORM | **Eloquent** | ORM oficial de Laravel |
| Vistas | **Blade** + componentes (`resources/views/components`) | Un solo proyecto; el servidor pinta las pantallas |
| Interactividad | **Alpine.js** | Modales, pestañas, mapas y la checklist en vivo, sin un framework de SPA |
| Estilos | **Tailwind CSS 4** | Tokens de la paleta en `@theme` (`resources/css/app.css`) |
| Iconos | **Lucide** (`mallardduck/blade-lucide-icons`) | Los mismos del diseño, como componentes `<x-lucide-…>` |
| Mapas | **Leaflet** + OpenStreetMap | Sin API key ni coste |
| Autenticación web | **Sesión por cookie** | Lo natural en Blade, con CSRF |
| Autenticación API | **Sanctum con tokens Bearer** | La app móvil (React Native) |

### Un API, dos formas de entrar

- Las vistas Blade usan la **sesión**. Las pocas pantallas que trabajan en vivo (consulta de
  DNI, checklist de W18, simulación de W19, inicio de recorrido W21) llaman al mismo `/api`
  con esa cookie: `statefulApi()` en `bootstrap/app.php` hace que Sanctum la acepte.
- La **app móvil** entra con `POST /api/login` y manda `Authorization: Bearer …`, como antes.

Las reglas de negocio no se duplican: viven en los modelos, en los FormRequest y en
`app/Services`, y las usan tanto los controladores web (`app/Http/Controllers/Web`) como los
del API (`app/Http/Controllers/Api`).

## Configuración relevante

- `.env` — conexión MySQL (`rsu_reciclaje`), `APP_URL`, `APP_LOCALE=es`.
- `SANCTUM_STATEFUL_DOMAINS` — dominios cuyo JavaScript entra al API con la sesión
  (`rsu-back.test`, `localhost:8000`…). Si se sirve desde otro dominio, añadirlo aquí.
- `SESSION_DOMAIN=null` — así la cookie vale tanto en `rsu-back.test` como en `localhost`.

```
DNI_LOOKUP_DRIVER=consultasapi   # consultasapi | null (desactivado)
DNI_LOOKUP_TOKEN=
DNI_LOOKUP_API_KEY=
DNI_LOOKUP_TIMEOUT=8             # segundos antes de rendirse
```

Ojo: **solo devuelve apellidos y nombres** — ni correo, ni teléfono, ni licencia.

**Feriados del Perú.** "Cargar feriados Perú" los toma del calendario público de feriados de
Google (iCalendar), que se actualiza solo cada año; la respuesta se guarda 12 horas. Si no
responde, usa una lista interna con los feriados de ley. Se puede cambiar la fuente:

```
HOLIDAYS_FEED_URL=               # .ics de feriados; vacío = el de Google
HOLIDAYS_FEED_TIMEOUT=8          # segundos antes de usar la lista interna
```

## Documentación

- **[`docs/GUIA-TECNICA.md`](docs/GUIA-TECNICA.md)** — cómo arrancar, el mapa del código,
  las decisiones cerradas, las trampas descubiertas y cómo funciona cada módulo.
- [`docs/BITACORA.md`](docs/BITACORA.md) — el detalle cronológico de cada sesión.
