import { api, errorMessage } from '../bootstrap';

/**
 * W19 Edición masiva: los mismos cambios a varias programaciones.
 *
 * Cada campo se activa con su casilla; sin marcar ni siquiera viaja, que es
 * lo que distingue "poner este valor" de "dejarlo como está". Mientras se
 * eligen se pide al API una simulación (dry_run) para avisar ANTES de aplicar
 * qué filas quedarían rechazadas.
 */
export default (config) => ({
    selected: [],
    active: [],
    values: {},
    simulation: null,
    result: null,
    busy: false,
    applying: false,
    error: null,
    timer: null,

    init() {
        this.$watch('selected', () => this.changed());
        this.$watch('active', () => this.changed());
        this.$watch('values', () => this.changed());
    },

    changed() {
        if (this.applying) return;
        this.result = null;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.simulate(), 400);
    },

    /** Solo los campos marcados y con valor forman los cambios a aplicar. */
    get changes() {
        const changes = {};

        for (const key of this.active) {
            const value = this.values[key];
            if (value === undefined || value === '') continue;
            changes[key] = key === 'end_date' ? value : Number(value);
        }

        return changes;
    },

    get ready() {
        return this.selected.length > 0 && Object.keys(this.changes).length > 0;
    },

    selectAll() {
        this.selected = config.ids.slice();
    },

    async simulate() {
        if (!this.ready) {
            this.simulation = null;
            return;
        }

        try {
            const { data } = await api.post('/schedulings/bulk-update', {
                ids: this.selected,
                changes: this.changes,
                dry_run: true,
            });
            this.simulation = data;
        } catch {
            this.simulation = null;
        }
    },

    async apply() {
        this.busy = true;
        this.error = null;

        try {
            const { data } = await api.post('/schedulings/bulk-update', {
                ids: this.selected,
                changes: this.changes,
            });
            // Al vaciar la selección saltan los watchers, que limpiarían el
            // resultado recién llegado: se silencian mientras tanto.
            this.applying = true;
            this.selected = [];
            this.simulation = null;
            this.result = data;
            setTimeout(() => (this.applying = false), 50);
        } catch (error) {
            this.error = errorMessage(error, 'No se pudieron aplicar los cambios');
        } finally {
            this.busy = false;
        }
    },

    outcome(id) {
        if (!this.result) return null;
        if (this.result.aplicadas.some((row) => row.id === id)) return { ok: true };
        const rejected = this.result.rechazadas.find((row) => row.id === id);
        return rejected ? { ok: false, errors: rejected.errors } : null;
    },
});
