import L from 'leaflet';

import { api } from '../bootstrap';

/** Chiclayo: el área que cubre el proyecto. */
const DEFAULT_CENTER = [-6.7714, -79.8409];
/** Cada cuánto se consulta al servidor; la app del conductor manda un punto cada 5 s. */
const POLL_MS = 5000;

const COLORS = {
    live: '#2e6b59', // brand-500
    lost: '#d97706', // amber-600
    done: '#78716c', // stone-500
    path: '#2e6b59',
    zone: '#173f35',
};

/** Camión (Lucide "truck") dentro de un círculo del color del estado. */
function truckIcon(color, selected) {
    const size = selected ? 38 : 30;
    return L.divIcon({
        className: '',
        html: `<span style="
            display:grid;place-items:center;
            width:${size}px;height:${size}px;margin:-${size / 2}px 0 0 -${size / 2}px;
            border-radius:9999px;border:${selected ? 3 : 2}px solid #fff;background:${color};
            box-shadow:0 1px 4px rgba(0,0,0,.4);">
            <svg xmlns="http://www.w3.org/2000/svg" width="${size * 0.5}" height="${size * 0.5}" viewBox="0 0 24 24"
                fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/>
                <path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/>
                <circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>
            </svg>
        </span>`,
        iconSize: [0, 0],
    });
}

function dotIcon(color) {
    return L.divIcon({
        className: '',
        html: `<span style="display:block;width:14px;height:14px;margin:-7px 0 0 -7px;border-radius:9999px;
            background:#fff;border:4px solid ${color};box-shadow:0 1px 2px rgba(0,0,0,.3)"></span>`,
        iconSize: [0, 0],
    });
}

function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`);
}

/**
 * Recorridos en vivo: lista de los recorridos del día y mapa con la última
 * posición de cada camión. Al elegir uno se dibuja su trayecto, que crece
 * con cada consulta (solo se piden los puntos nuevos).
 *
 * Los objetos de Leaflet viven fuera del estado de Alpine: envolverlos en
 * sus proxies rompe Leaflet.
 *
 * @param {{date: string, selected: number|null}} options
 */
export default (options) => {
    let map = null;
    let pathLine = null;
    let startMarker = null;
    let zoneLayer = null;
    let pathRouteId = null;
    let lastPointId = 0;
    let timer = null;
    let clock = null;
    let fittedOnce = false;
    const markers = new Map();

    return {
        date: options.date,
        selectedId: options.selected ?? null,
        isToday: true,
        routes: [],
        counts: { iniciado: 0, finalizado: 0 },
        detail: null,
        loading: true,
        error: null,
        follow: true,
        tilesFailed: false,
        fetchedAt: Date.now(),
        now: Date.now(),

        init() {
            map = L.map(this.$refs.map, { zoomControl: true }).setView(DEFAULT_CENTER, 13);
            const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap',
            });
            tiles.on('tileerror', () => (this.tilesFailed = true));
            tiles.addTo(map);

            pathLine = L.polyline([], { color: COLORS.path, weight: 5, opacity: 0.9, lineJoin: 'round' }).addTo(map);
            // Arrastrar el mapa deja de seguir al camión hasta volver a pedirlo.
            map.on('dragstart', () => (this.follow = false));

            this.refresh();
            timer = setInterval(() => {
                if (!document.hidden) this.refresh();
            }, POLL_MS);
            clock = setInterval(() => (this.now = Date.now()), 1000);
        },

        destroy() {
            clearInterval(timer);
            clearInterval(clock);
            map?.remove();
        },

        // ------------------------------------------------------------ Datos

        async refresh() {
            try {
                const { data } = await api.get('/tracking', { params: { date: this.date } });
                this.routes = data.data;
                this.counts = data.counts;
                this.isToday = data.is_today;
                this.fetchedAt = Date.now();
                this.error = null;
                this.drawMarkers();

                if (this.selectedId) {
                    await this.loadSelected();
                } else if (!fittedOnce) {
                    this.fitAll();
                }
            } catch (error) {
                this.error = error.response?.status === 403
                    ? 'No tienes permiso para ver los recorridos.'
                    : 'No se pudo actualizar. Se reintentará en unos segundos.';
            } finally {
                this.loading = false;
            }
        },

        async loadSelected() {
            const id = this.selectedId;
            const fresh = pathRouteId !== id;
            const { data } = await api.get(`/tracking/${id}`, { params: { after_id: fresh ? 0 : lastPointId } });
            if (this.selectedId !== id) return; // Cambió la selección mientras llegaba.

            if (fresh) {
                pathRouteId = id;
                lastPointId = 0;
                pathLine.setLatLngs([]);
                startMarker?.remove();
                startMarker = null;
                zoneLayer?.remove();
                zoneLayer = null;
                const perimeter = data.data.zone_perimeter;
                if (perimeter && perimeter.length >= 3) {
                    zoneLayer = L.polygon(perimeter, {
                        color: COLORS.zone, weight: 2, dashArray: '6 6', fillColor: COLORS.zone, fillOpacity: 0.06,
                    }).addTo(map);
                    zoneLayer.bringToBack();
                }
            }

            for (const p of data.points) pathLine.addLatLng([p.lat, p.lng]);
            if (data.points.length) lastPointId = data.points[data.points.length - 1].id;

            const latlngs = pathLine.getLatLngs();
            if (latlngs.length && !startMarker) {
                startMarker = L.marker(latlngs[0], { icon: dotIcon(COLORS.zone), interactive: false }).addTo(map);
            }

            // Las calificaciones solo llegan en la primera carga: se conservan entre consultas.
            const ratings = data.data.ratings ?? (fresh ? [] : this.detail?.ratings ?? []);
            this.detail = { ...data.data, ratings, fetchedAt: Date.now() };
            this.drawMarkers();

            if (fresh) {
                const bounds = latlngs.length ? L.latLngBounds(latlngs) : zoneLayer?.getBounds();
                if (bounds?.isValid()) map.fitBounds(bounds.pad(0.15), { maxZoom: 17 });
                else if (data.data.last) map.setView([data.data.last.lat, data.data.last.lng], 16);
            } else if (this.follow && data.data.status === 'iniciado' && data.data.last) {
                map.panTo([data.data.last.lat, data.data.last.lng], { animate: true });
            }
        },

        select(id) {
            if (this.selectedId === id) {
                this.focusSelected();
                return;
            }
            this.selectedId = id;
            this.detail = null;
            this.follow = true;
            this.syncUrl();
            this.loadSelected().catch(() => (this.error = 'No se pudo cargar el recorrido.'));
        },

        clearSelection() {
            this.selectedId = null;
            this.detail = null;
            pathRouteId = null;
            pathLine.setLatLngs([]);
            startMarker?.remove();
            startMarker = null;
            zoneLayer?.remove();
            zoneLayer = null;
            this.syncUrl();
            this.drawMarkers();
            this.fitAll();
        },

        /** Botón "Seguir camión": vuelve a centrar en su última posición. */
        focusSelected() {
            this.follow = true;
            const last = this.detail?.last;
            if (last) map.setView([last.lat, last.lng], Math.max(map.getZoom(), 16));
        },

        syncUrl() {
            const url = new URL(window.location.href);
            if (this.selectedId) url.searchParams.set('recorrido', this.selectedId);
            else url.searchParams.delete('recorrido');
            window.history.replaceState(null, '', url);
        },

        // ------------------------------------------------------------ Mapa

        drawMarkers() {
            const seen = new Set();
            for (const route of this.routes) {
                if (!route.last) continue;
                seen.add(route.id);
                const selected = route.id === this.selectedId;
                const color = route.status === 'iniciado' ? (route.signal_lost ? COLORS.lost : COLORS.live) : COLORS.done;
                const latlng = [route.last.lat, route.last.lng];

                let marker = markers.get(route.id);
                if (!marker) {
                    marker = L.marker(latlng).addTo(map);
                    marker.on('click', () => this.select(route.id));
                    markers.set(route.id, marker);
                }
                marker.setLatLng(latlng);
                marker.setIcon(truckIcon(color, selected));
                marker.setZIndexOffset(selected ? 1000 : route.status === 'iniciado' ? 500 : 0);
                marker.bindTooltip(
                    `<strong>${escapeHtml(route.code ?? 'Recorrido')}</strong><br>${escapeHtml(route.vehicle ?? '')}`,
                    { direction: 'top', offset: [0, -18] },
                );
            }
            for (const [id, marker] of markers) {
                if (!seen.has(id)) {
                    marker.remove();
                    markers.delete(id);
                }
            }
        },

        fitAll() {
            const points = this.routes.filter((r) => r.last).map((r) => [r.last.lat, r.last.lng]);
            if (!points.length) return;
            fittedOnce = true;
            if (points.length === 1) map.setView(points[0], 16);
            else map.fitBounds(L.latLngBounds(points).pad(0.2), { maxZoom: 16 });
        },

        // ------------------------------------------------------------ Formato

        /** Tiempo del recorrido; el de uno en curso sigue corriendo entre consultas. */
        elapsed(route, base = this.fetchedAt) {
            if (!route) return '—';
            let s = route.elapsed_seconds;
            if (route.status === 'iniciado') s += Math.max(0, Math.floor((this.now - base) / 1000));
            const h = String(Math.floor(s / 3600)).padStart(2, '0');
            const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
            const sec = String(s % 60).padStart(2, '0');
            return `${h}:${m}:${sec}`;
        },

        ago(route) {
            if (!route || route.seconds_since_last === null) return 'Sin ubicaciones todavía';
            const s = route.seconds_since_last + Math.max(0, Math.floor((this.now - this.fetchedAt) / 1000));
            if (s < 60) return `Última señal hace ${s} s`;
            if (s < 3600) return `Última señal hace ${Math.floor(s / 60)} min`;
            return `Última señal a las ${route.last.at.slice(0, 5)}`;
        },

        coords(route) {
            return route?.last ? `${route.last.lat.toFixed(5)}, ${route.last.lng.toFixed(5)}` : '—';
        },
    };
};
