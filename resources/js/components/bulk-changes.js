import { api, errorMessage, validationErrors } from '../bootstrap';

const blankForm = () => ({
    date_from: '',
    date_to: '',
    zone_ids: [],
    type: 'turno',
    old_id: '',
    new_id: '',
    reason: '',
    description: '',
});

/** Cómo se nombra cada tipo en las etiquetas: "Turno a reemplazar", "Nuevo turno". */
const NOUNS = {
    turno: ['Turno', 'turno'],
    conductor: ['Conductor', 'conductor'],
    ayudante: ['Ocupante', 'ocupante'],
    vehiculo: ['Vehículo', 'vehículo'],
};

/**
 * Cambio masivo (Gestión de cambios › Cambios).
 *
 * Dos pasos en el mismo modal: el formulario y, al guardar, el resumen que
 * arma el servidor (BulkRescheduling::preview) con lo que se modifica y lo
 * que se omite. Solo se aplica tras confirmar que es irreversible; la
 * página se recarga con el aviso del resultado.
 */
export default (config) => ({
    catalogs: config.catalogs,
    previewUrl: config.previewUrl,
    storeUrl: config.storeUrl,

    open: false,
    step: 'form',
    form: blankForm(),
    errors: {},
    failure: null,
    busy: false,
    summary: null,
    confirmed: false,
    // Filtro de la tabla del resumen: all, ok o skipped.
    rowFilter: 'all',

    openModal() {
        this.form = blankForm();
        this.errors = {};
        this.failure = null;
        this.summary = null;
        this.confirmed = false;
        this.rowFilter = 'all';
        this.step = 'form';
        this.open = true;
    },

    /** Elegir un tipo desde sus tarjetas. */
    pickType(type) {
        if (this.form.type === type) return;
        this.form.type = type;
        this.changeType();
    },

    /** Días que cubre el rango, contando los dos extremos; null si falta una fecha o está al revés. */
    get rangeDays() {
        if (!this.form.date_from || !this.form.date_to || this.form.date_to < this.form.date_from) return null;
        return Math.round((Date.parse(this.form.date_to) - Date.parse(this.form.date_from)) / 86400000) + 1;
    },

    get oldLabel() {
        return this.oldOptions.find((item) => String(item.id) === String(this.form.old_id))?.label ?? null;
    },

    get newLabel() {
        return this.oldOptions.find((item) => String(item.id) === String(this.form.new_id))?.label ?? null;
    },

    get summaryDays() {
        if (!this.summary) return 0;
        return Math.round((Date.parse(this.summary.date_to) - Date.parse(this.summary.date_from)) / 86400000) + 1;
    },

    get filteredRows() {
        const rows = this.summary?.rows ?? [];
        if (this.rowFilter === 'ok') return rows.filter((row) => !row.problem);
        if (this.rowFilter === 'skipped') return rows.filter((row) => row.problem);
        return rows;
    },

    close() {
        if (!this.busy) this.open = false;
    },

    /** Al cambiar de tipo, lo elegido deja de tener sentido. */
    changeType() {
        this.form.old_id = '';
        this.form.new_id = '';
        this.form.reason = '';
        this.errors = {};
    },

    error(field) {
        return this.errors[field]?.[0] ?? null;
    },

    noun(index = 0) {
        return NOUNS[this.form.type]?.[index] ?? '';
    },

    get oldOptions() {
        return this.catalogs.options[this.form.type] ?? [];
    },

    /** Lo nuevo: solo lo que se puede programar, y distinto de lo que se reemplaza. */
    get newOptions() {
        return this.oldOptions.filter((item) => item.selectable && String(item.id) !== String(this.form.old_id));
    },

    get reasonOptions() {
        return this.catalogs.reasons[this.catalogs.sections[this.form.type]] ?? [];
    },

    get needsDescription() {
        return Boolean(this.reasonOptions.find((reason) => reason.name === this.form.reason)?.requires_description);
    },

    /** Lo que quedará escrito: el motivo y su detalle. */
    get fullDescription() {
        return [this.form.reason, this.form.description.trim()].filter(Boolean).join(' - ');
    },

    toggleZone(id) {
        const index = this.form.zone_ids.indexOf(id);
        index === -1 ? this.form.zone_ids.push(id) : this.form.zone_ids.splice(index, 1);
    },

    payload() {
        return {
            ...this.form,
            old_id: this.form.old_id ? Number(this.form.old_id) : null,
            new_id: this.form.new_id ? Number(this.form.new_id) : null,
            description: this.form.description.trim() || null,
        };
    },

    async review() {
        this.busy = true;
        this.errors = {};
        this.failure = null;

        try {
            const { data } = await api.post(this.previewUrl, this.payload(), { baseURL: '/' });
            this.summary = data;
            this.confirmed = false;
            this.rowFilter = 'all';
            this.step = 'summary';
        } catch (error) {
            this.errors = validationErrors(error);
            if (Object.keys(this.errors).length === 0) this.failure = errorMessage(error, 'No se pudo preparar el resumen');
        } finally {
            this.busy = false;
        }
    },

    back() {
        this.step = 'form';
        this.failure = null;
    },

    async apply() {
        this.busy = true;
        this.failure = null;

        try {
            await api.post(this.storeUrl, { ...this.payload(), confirmed: this.confirmed }, { baseURL: '/' });
            window.location.reload();
        } catch (error) {
            this.failure = errorMessage(error, 'No se pudo aplicar el cambio masivo');
            this.busy = false;
        }
    },

    /** "01/05/2026" a partir de "2026-05-01". */
    shortDate(iso) {
        return iso ? iso.split('-').reverse().join('/') : '—';
    },
});
