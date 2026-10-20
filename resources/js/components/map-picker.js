import L from 'leaflet';

/** Chiclayo: el área que cubre el proyecto. */
const DEFAULT_CENTER = [-6.7714, -79.8409];

/** Radio terrestre (WGS84) del cálculo de área; el mismo que usa Zone en el servidor. */
const EARTH_RADIUS_M = 6378137;

/**
 * Los marcadores por defecto de Leaflet son PNG que el bundler no resuelve y
 * acaban como iconos rotos. Con divIcon el marcador es HTML nuestro: sin
 * peticiones, sin imágenes que falten y con el color de la paleta.
 */
function pin(label, color) {
    return L.divIcon({
        className: '',
        html: `<span style="
            display:grid;place-items:center;
            width:22px;height:22px;margin:-11px 0 0 -11px;
            border-radius:9999px;border:2px solid #fff;
            background:${color};color:#fff;
            font:600 10px/1 ui-sans-serif,system-ui;
            box-shadow:0 1px 3px rgba(0,0,0,.35);
        ">${label}</span>`,
        iconSize: [22, 22],
        iconAnchor: [0, 0],
    });
}

/** Escapa texto del usuario antes de meterlo en el HTML de un popup. */
function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`);
}

/** Ficha de una zona en el mapa general: título, líneas de detalle y un enlace. */
function referencePopup({ title, lines = [], link }) {
    return `
        <div style="font:12px/1.45 ui-sans-serif,system-ui;min-width:170px">
            <p style="margin:0 0 4px;font-weight:600;font-size:13px;color:#1c1917">${escapeHtml(title)}</p>
            ${lines.map((line) => `<p style="margin:0;color:#57534e">${escapeHtml(line)}</p>`).join('')}
            ${link ? `<a href="${escapeHtml(link.url)}" style="display:inline-block;margin-top:6px;font-weight:500;color:#2f7d63">${escapeHtml(link.label)}</a>` : ''}
        </div>`;
}

/**
 * Mapa para marcar puntos, compartido por W14 (perímetro) y W15 (rutas), y
 * de solo lectura en el mapa general de zonas.
 *
 * - mode "polygon": cada clic añade un vértice del perímetro.
 * - mode "endpoints": los puntos van en pares (partida y fin de cada ruta).
 *
 * `points` es reactivo y lo lee la vista (lista de vértices, campos ocultos
 * del formulario). Los objetos de Leaflet viven fuera del estado de Alpine:
 * envolverlos en sus proxies rompe Leaflet.
 *
 * @param {object} options
 * @param {'polygon'|'endpoints'} options.mode
 * @param {Array<{latitude:number, longitude:number}>} [options.points]
 * @param {boolean} [options.readOnly]
 * @param {string[]} [options.pairLabels] Nombre de cada par, para los tooltips de la vista general.
 * @param {Array<{id?:number, name:string, status?:string, points:Array<{latitude:number, longitude:number}>,
 *        popup?:{title:string, lines?:string[], link?:{url:string, label:string}}}>} [options.references]
 *        Perímetros de zonas que se ven pero no se tocan (ver drawReferences).
 */
export default (options) => {
    let map = null;
    let layer = null;
    let foundMarker = null;
    const referenceShapes = new Map();
    // Cargar datos lleva de 0 a varios puntos de una vez; el usuario los añade
    // de uno en uno. Solo lo primero debe encuadrar el mapa.
    let previousCount = 0;

    return {
        mode: options.mode,
        readOnly: Boolean(options.readOnly),
        pairLabels: options.pairLabels ?? [],
        points: (options.points ?? []).map((point) => ({
            latitude: Number(point.latitude),
            longitude: Number(point.longitude),
        })),
        tilesFailed: false,
        search: { query: '', results: [], loading: false, error: null, done: false },

        init() {
            map = L.map(this.$refs.map, { zoomControl: true }).setView(DEFAULT_CENTER, 13);

            const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap',
            });

            // Sin internet no hay fondo, pero los puntos sí se dibujan: conviene
            // decirlo en vez de dejar un rectángulo gris sin explicación.
            tiles.on('tileerror', () => (this.tilesFailed = true));
            tiles.addTo(map);

            map.on('click', (event) => {
                if (this.readOnly) return;

                const next = { latitude: event.latlng.lat, longitude: event.latlng.lng };

                if (this.mode === 'endpoints') {
                    // Primer clic pone la partida, segundo el fin, tercero vuelve a empezar.
                    this.points = this.points.length >= 2 ? [next] : [...this.points, next];
                    return;
                }

                this.points = [...this.points, next];
            });

            this.drawReferences(options.references ?? []);

            layer = L.layerGroup().addTo(map);

            this.$watch('points', () => this.draw());
            this.draw();

            // Un mapa creado dentro de un modal recién abierto mide 0×0 hasta
            // que el navegador lo pinta: hay que avisarle cuando ya tiene tamaño.
            new ResizeObserver(() => map.invalidateSize()).observe(this.$refs.map);
        },

        destroy() {
            map?.remove();
            map = null;
        },

        /** Reemplaza los puntos desde fuera (p. ej. al abrir otra ruta para editar). */
        setPoints(points) {
            previousCount = 0;
            this.points = points.map((point) => ({
                latitude: Number(point.latitude),
                longitude: Number(point.longitude),
            }));
        },

        /** Quita un vértice cualquiera, no solo el último (marcador del mapa o lista de W14). */
        removePoint(index) {
            this.points = this.points.filter((_, position) => position !== index);
        },

        undo() {
            this.points = this.points.slice(0, -1);
        },

        /**
         * Perímetros de zonas, con su nombre.
         *
         * - Al dibujar (W14) son las otras zonas, en gris y no interactivas: un
         *   clic encima sigue añadiendo vértices, para poder trazar pegado a ellas.
         * - En el mapa general (readOnly) son el contenido: activas en verde,
         *   inactivas en gris, y un clic abre la ficha (`zone.popup`).
         *
         * Si no hay puntos propios, el mapa abre encuadrándolas.
         */
        drawReferences(references) {
            const shapes = references.filter((zone) => zone.points.length >= 3);
            if (shapes.length === 0) return;

            const group = L.layerGroup().addTo(map);
            const bounds = L.latLngBounds([]);

            shapes.forEach((zone) => {
                const latlngs = zone.points.map((point) => [Number(point.latitude), Number(point.longitude)]);
                bounds.extend(latlngs);

                const highlighted = this.readOnly && zone.status !== 'inactiva';
                const polygon = L.polygon(latlngs, {
                    color: highlighted ? '#173f35' : '#78716c',
                    weight: highlighted ? 2 : 1.5,
                    dashArray: highlighted ? null : '5 4',
                    fillColor: highlighted ? '#2f7d63' : '#a8a29e',
                    fillOpacity: highlighted ? 0.25 : 0.2,
                    interactive: this.readOnly,
                })
                    .bindTooltip(zone.name, { permanent: true, direction: 'center', className: 'zone-reference-label' })
                    .addTo(group);

                if (this.readOnly && zone.popup) polygon.bindPopup(referencePopup(zone.popup));

                referenceShapes.set(zone.id ?? zone.name, polygon);
            });

            if (this.points.length === 0) {
                map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
            }
        },

        /** Lleva el mapa a una zona y abre su ficha (lista lateral del mapa general). */
        focusReference(key) {
            const polygon = referenceShapes.get(key);
            if (!polygon) return;

            map.fitBounds(polygon.getBounds(), { padding: [40, 40], maxZoom: 17 });
            polygon.openPopup();
        },

        /** Busca una dirección (W14) para ir hasta ella antes de dibujar. */
        async searchAddress() {
            const query = this.search.query.trim();
            if (query.length < 3) return;

            this.search.loading = true;
            this.search.error = null;

            try {
                const { data } = await window.api.get('/address-search', { params: { q: query } });
                this.search.results = data;
                this.search.done = true;

                // Un solo resultado: ir directo, sin hacer elegir.
                if (data.length === 1) this.goTo(data[0]);
            } catch (error) {
                this.search.results = [];
                this.search.error = error.response?.data?.message ?? 'No se pudo buscar la dirección.';
            } finally {
                this.search.loading = false;
            }
        },

        goTo(place) {
            const [south, north, west, east] = place.bounds ?? [];

            if (south !== undefined) {
                map.fitBounds([[south, west], [north, east]], { maxZoom: 17 });
            } else {
                map.setView([place.latitude, place.longitude], 17);
            }

            // Marca el lugar encontrado sin que cuente como vértice ni estorbe los clics.
            foundMarker?.remove();
            foundMarker = L.circleMarker([place.latitude, place.longitude], {
                radius: 7,
                color: '#0284c7',
                weight: 2,
                fillOpacity: 0.35,
                interactive: false,
            }).addTo(map);

            this.search.results = [];
            this.search.done = false;
        },

        clear() {
            this.points = [];
        },

        /**
         * Área del perímetro en km², o null sin figura. Misma fórmula esférica
         * que Zone::areaFromCoords() en el servidor, que es quien la guarda.
         */
        get areaKm2() {
            const count = this.points.length;
            if (this.mode !== 'polygon' || count < 3) return null;

            const rad = (degrees) => (degrees * Math.PI) / 180;
            let total = 0;

            for (let i = 0; i < count; i++) {
                const previous = this.points[(i - 1 + count) % count];
                const current = this.points[i];
                const next = this.points[(i + 1) % count];
                total += (rad(next.longitude) - rad(previous.longitude)) * Math.sin(rad(current.latitude));
            }

            return (Math.abs(total) * EARTH_RADIUS_M ** 2) / 2 / 1_000_000;
        },

        draw() {
            if (!map || !layer) return;

            layer.clearLayers();
            const latlngs = this.points.map((point) => [point.latitude, point.longitude]);

            if (this.mode === 'polygon' && latlngs.length >= 3) {
                L.polygon(latlngs, { color: '#173f35', weight: 2, fillOpacity: 0.15 }).addTo(layer);
            }

            if (this.mode === 'polygon' && latlngs.length === 2) {
                L.polyline(latlngs, { color: '#173f35', weight: 2, dashArray: '4 4' }).addTo(layer);
            }

            if (this.mode === 'endpoints') {
                // Los puntos van EN PARES: partida y fin de cada ruta. La vista
                // general pinta varias rutas de golpe, así que se recorren de dos
                // en dos. Línea recta a propósito: `routes` guarda extremos, no el
                // camino real.
                for (let index = 0; index + 1 < latlngs.length; index += 2) {
                    L.polyline([latlngs[index], latlngs[index + 1]], {
                        color: '#0284c7',
                        weight: 3,
                        dashArray: '6 6',
                    }).addTo(layer);
                }
            }

            this.points.forEach((point, index) => {
                const isStart = index % 2 === 0;
                const endpoints = this.mode === 'endpoints';
                const label = endpoints ? (isStart ? 'A' : 'B') : String(index + 1);
                const color = endpoints && !isStart ? '#dc2626' : '#173f35';
                const pairName = endpoints ? this.pairLabels[Math.floor(index / 2)] : undefined;
                const hint = this.readOnly ? '' : ' · clic para quitarlo';

                const marker = L.marker([point.latitude, point.longitude], {
                    icon: pin(label, color),
                    draggable: !this.readOnly,
                }).addTo(layer);

                // El tooltip va antes del corte por readOnly: en la vista general
                // es lo único que dice a qué ruta pertenece cada marcador.
                marker.bindTooltip(
                    endpoints
                        ? `${pairName ? `${pairName}: ` : ''}${isStart ? 'Punto de partida' : 'Punto de fin'}${hint}`
                        : `Vértice ${index + 1}${hint}`,
                );

                if (this.readOnly) return;

                marker.on('dragend', () => {
                    const position = marker.getLatLng();
                    const next = [...this.points];
                    next[index] = { latitude: position.lat, longitude: position.lng };
                    this.points = next;
                });

                // Quitar un punto sin botones extra: se pulsa el propio marcador.
                marker.on('click', (event) => {
                    L.DomEvent.stopPropagation(event);
                    this.removePoint(index);
                });
            });

            const jumpedFromEmpty = previousCount === 0 && this.points.length > 1;
            previousCount = this.points.length;

            if (jumpedFromEmpty) {
                map.fitBounds(L.latLngBounds(latlngs), { padding: [40, 40], maxZoom: 16 });
            }
        },
    };
};
