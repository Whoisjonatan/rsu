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
    groupLoaded: null,
    validation: null,
    timer: null,

    init() {
        this.fitSeats();
        this.$watch('form.vehicle_id', () => this.fitSeats());

        this.$watch('form.zone_id', (zoneId, previous) => {
            // La ruta pertenece a una zona: al cambiarla deja de aplicar.
            if (previous !== undefined && !this.zoneRoutes.some((route) => String(route.id) === String(this.form.route_id))) {
                this.form.route_id = '';
            }

            // Con un grupo elegido ya está todo cargado: la precarga por zona sobra.
            if (this.isNew && zoneId && !this.form.staffgroup_id) this.loadDefaults(zoneId);
        });

        // Llegó desde Grupos de personal con ?grupo=ID.
        if (this.isNew && this.form.staffgroup_id && !this.form.driver_id) this.applyGroup(this.form.staffgroup_id);

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

    get vehicle() {
        return this.catalogs.vehicles.find((vehicle) => String(vehicle.id) === String(this.form.vehicle_id)) ?? null;
    },

    /** Asientos de ayudante: la capacidad del vehículo menos el conductor. */
    get seats() {
        return this.vehicle ? this.vehicle.seats : 0;
    },

    /** Un selector por asiento: se rellenan o recortan al cambiar de vehículo. */
    fitSeats() {
        const helpers = this.form.helper_ids.slice(0, this.seats);
        while (helpers.length < this.seats) helpers.push('');
        this.form.helper_ids = helpers;
    },

    /** Ayudantes que se pueden elegir en ese asiento: ni el conductor ni los de otros asientos. */
    helperOptions(index) {
        const taken = [this.form.driver_id, ...this.form.helper_ids.filter((_, other) => other !== index)]
            .filter(Boolean)
            .map(String);
        return this.catalogs.helpers.filter((person) => !taken.includes(String(person.id)));
    },

    /** Copia el grupo en el formulario: todo salvo las fechas y el estado. */
    applyGroup(id) {
        const group = this.catalogs.groups.find((item) => String(item.id) === String(id));

        if (!group) {
            this.groupLoaded = null;
            return;
        }

        this.precargada = null;
        this.form.staffgroup_id = String(group.id);
        this.form.zone_id = group.zone_id;
        this.form.schedule_id = group.schedule_id;
        this.form.vehicle_id = group.vehicle_id;
        this.form.driver_id = group.driver_id;
        this.form.weekdays = [...group.weekdays];
        this.form.helper_ids = [...group.helper_ids];
        this.fitSeats();
        this.groupLoaded = `Se cargó el grupo "${group.name}": indica la fecha de inicio y la de término.`;
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

            ['vehicle_id', 'schedule_id', 'driver_id'].forEach(apply);

            // Los ayudantes se acomodan en los asientos que da el vehículo.
            const helpers = (data.helper_ids ?? []).map(String);
            if (helpers.length && !this.form.helper_ids.some(Boolean)) {
                this.$nextTick(() => {
                    this.form.helper_ids = this.form.helper_ids.map((current, index) => helpers[index] ?? current);
                });
            }

            // El aviso nombra solo lo que de verdad se rellenó: una zona sin
            // programación previa no tiene vehículo ni turno habituales.
            const loaded = [
                data.vehicle_id ? 'vehículo' : null,
                data.driver_id || helpers.length ? 'personal' : null,
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
            const { data } = await api.post('/schedulings/validate', {
                ...form,
                helper_ids: form.helper_ids.filter(Boolean),
                id: config.id,
            });
            this.validation = data;
        } catch {
            this.validation = null;
        }
    },

    /** El aviso de vacaciones sale mientras se elige, no al guardar. */
    get vacationWarning() {
        const errors = this.validation?.errors ?? {};
        return (errors.driver_id ?? []).concat(errors.helper_ids ?? []).find((text) => text.includes('vacaciones')) ?? null;
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
            ['Ayudantes', this.form.helper_ids.filter(Boolean).map((id) => this.label('helpers', id, 'full_name')).join(', ')],
            ['Días', this.weekdaysSummary],
            ['Vigencia', this.form.start_date ? `${formatDate(this.form.start_date)} al ${formatDate(this.form.end_date)}` : null],
        ];
    },
});
