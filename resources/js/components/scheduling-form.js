import { api } from '../bootstrap';

const DAY_NAMES = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
const DAY_SHORT = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

/** "2026-08-24" → "24/08/2026" sin pasar por Date, que desfasaría la zona. */
function formatDate(iso) {
    if (!iso) return '—';
    const [year, month, day] = iso.split('-');
    return `${day}/${month}/${year}`;
}

/**
 * W18 Programación: el formulario se envía al servidor de forma normal; este
 * componente solo añade lo que tiene que pasar mientras se rellena.
 *
 * - Precarga por zona (validación 3 del informe): al elegir la zona, en el
 *   alta, se rellenan vehículo, personal y turno habituales si siguen vacíos.
 * - Checklist viva del panel derecho: valida contra el API sin guardar, con
 *   las mismas reglas que se aplicarán al pulsar "Programar".
 * - Resumen de lo elegido.
 */
export default (config) => ({
    form: config.form,
    isNew: config.isNew,
    catalogs: config.catalogs,
    precargada: null,
    validation: null,
    timer: null,

    init() {
        this.$watch('form.zone_id', (zoneId, previous) => {
            // La ruta pertenece a una zona: al cambiarla deja de aplicar.
            if (previous !== undefined && !this.zoneRoutes.some((route) => String(route.id) === String(this.form.route_id))) {
                this.form.route_id = '';
            }

            if (this.isNew && zoneId) this.loadDefaults(zoneId);
        });

        // Se espera a que el usuario deje de elegir: cada consulta valida el
        // rango completo de fechas.
        this.$watch('form', () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.validate(), 400);
        });

        this.validate();
    },

    get zoneRoutes() {
        return this.catalogs.routes.filter((route) =>
            route.zone_ids.map(String).includes(String(this.form.zone_id)),
        );
    },

    toggleWeekday(day) {
        this.form.weekdays = this.form.weekdays.includes(day)
            ? this.form.weekdays.filter((value) => value !== day)
            : [...this.form.weekdays, day].sort((a, b) => a - b);
    },

    async loadDefaults(zoneId) {
        try {
            const { data } = await api.get(`/schedulings/defaults/${zoneId}`);
            // Solo los campos vacíos: no se pisa lo que el usuario eligió a mano.
            const apply = (field) => {
                if (data[field] && !this.form[field]) this.form[field] = String(data[field]);
            };

            ['vehicle_id', 'schedule_id', 'driver_id', 'helper_id'].forEach(apply);

            // El aviso nombra solo lo que de verdad se rellenó: una zona sin
            // programación previa no tiene vehículo ni turno habituales.
            const loaded = [
                data.vehicle_id ? 'vehículo' : null,
                data.driver_id || data.helper_id ? 'personal' : null,
                data.schedule_id ? 'turno' : null,
            ].filter(Boolean);

            this.precargada = loaded.length ? loaded : null;
        } catch {
            this.precargada = null;
        }
    },

    get precargadaText() {
        if (!this.precargada) return '';
        const list = this.precargada.join(', ').replace(/, ([^,]*)$/, ' y $1');
        return `Al elegir la zona se precargó ${list} ${this.precargada.length > 1 ? 'habituales' : 'habitual'}. Puedes cambiarlo.`;
    },

    /**
     * Solo se consulta cuando el formulario tiene lo mínimo: sin eso el API
     * contestaría "elige una zona" y la checklist parecería rota mientras se
     * rellena.
     */
    async validate() {
        const form = this.form;
        const complete = form.zone_id && form.vehicle_id && form.schedule_id && form.driver_id
            && form.start_date && form.end_date && form.weekdays.length > 0;

        if (!complete) {
            this.validation = null;
            return;
        }

        try {
            const { data } = await api.post('/schedulings/validate', { ...form, id: config.id });
            this.validation = data;
        } catch {
            this.validation = null;
        }
    },

    /** El aviso de vacaciones sale mientras se elige, no al guardar. */
    get vacationWarning() {
        const errors = this.validation?.errors ?? {};
        return (errors.driver_id ?? []).concat(errors.helper_id ?? []).find((text) => text.includes('vacaciones')) ?? null;
    },

    check(key) {
        return this.validation?.checks?.[key] ?? null;
    },

    label(list, id, field = 'name') {
        return this.catalogs[list].find((item) => String(item.id) === String(id))?.[field] ?? null;
    },

    /** Resume los días marcados: "Lunes a viernes" si son consecutivos. */
    get weekdaysSummary() {
        const days = [...this.form.weekdays].sort((a, b) => a - b);
        if (days.length === 0) return null;

        const consecutive = days.every((day, index) => index === 0 || day === days[index - 1] + 1);

        if (consecutive && days.length > 2) {
            const first = DAY_NAMES[days[0] - 1];
            return `${first.charAt(0).toUpperCase()}${first.slice(1)} a ${DAY_NAMES[days[days.length - 1] - 1]}`;
        }

        return days.map((day) => DAY_SHORT[day - 1]).join(', ');
    },

    get summary() {
        return [
            ['Zona', this.label('zones', this.form.zone_id)],
            ['Vehículo', this.label('vehicles', this.form.vehicle_id, 'plate')],
            ['Turno', this.label('schedules', this.form.schedule_id, 'label')],
            ['Conductor', this.label('drivers', this.form.driver_id, 'full_name')],
            ['Ayudante', this.label('helpers', this.form.helper_id, 'full_name')],
            ['Días', this.weekdaysSummary],
            ['Vigencia', this.form.start_date ? `${formatDate(this.form.start_date)} al ${formatDate(this.form.end_date)}` : null],
        ];
    },
});
