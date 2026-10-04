import L from 'leaflet';

/** Chiclayo: el área que cubre el proyecto. */
const DEFAULT_CENTER = [-6.7714, -79.8409];

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

/**
 * Mapa para marcar puntos, compartido por W14 (perímetro) y W15 (rutas).
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
 */
export default (options) => {
    let map = null;
    let layer = null;
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

        undo() {
            this.points = this.points.slice(0, -1);
        },

        clear() {
            this.points = [];
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
                    this.points = this.points.filter((_, position) => position !== index);
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
