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
 * - El grupo de personal carga zona, turno, vehículo, días y tripulación; el
 *   conductor y los ayudantes se pueden cambiar, el resto es del grupo.
 * - "Validar disponibilidad" consulta el API sin guardar, con las mismas
 *   reglas que se aplicarán al pulsar "Programar". No se puede programar sin
 *   haber validado lo que hay en pantalla: cambiar algo obliga a validar otra vez.
 * - Resumen de lo elegido.
 */
export default (config) => ({
    form: config.form,
    isNew: config.isNew,
    catalogs: config.catalogs,
    original: null,
    groupLoaded: null,
    validation: null,
    validatedDraft: null,
    outdated: false,
    checking: false,

    init() {
        this.original = JSON.parse(JSON.stringify(this.form));
        this.fitSeats();
        this.$watch('form.vehicle_id', () => this.fitSeats());

        // Llegó desde Grupos de personal con ?grupo=ID.
        if (this.isNew && this.form.staffgroup_id && !this.form.driver_id) this.applyGroup(this.form.staffgroup_id);

        // Lo validado deja de valer en cuanto cambia lo que decide la disponibilidad.
        this.$watch('form', () => {
            if (this.validation && JSON.stringify(this.draft) !== this.validatedDraft) {
                this.validation = null;
                this.outdated = true;
            }
        });
    },

    get group() {
        return this.catalogs.groups.find((item) => String(item.id) === String(this.form.staffgroup_id)) ?? null;
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

    /** Copia el grupo en el formulario: todo salvo las fechas, el estado y las observaciones. */
    applyGroup(id) {
        const group = this.catalogs.groups.find((item) => String(item.id) === String(id));

        if (!group) {
            // Sin grupo no queda nada que programar en el alta; al editar se
            // vuelve a lo que tenía la programación.
            const { zone_id, schedule_id, vehicle_id, driver_id, helper_ids, weekdays } = this.isNew
                ? { zone_id: '', schedule_id: '', vehicle_id: '', driver_id: '', helper_ids: [], weekdays: this.form.weekdays }
                : this.original;
            Object.assign(this.form, { zone_id, schedule_id, vehicle_id, driver_id, helper_ids: [...helper_ids], weekdays: [...weekdays] });
            this.fitSeats();
            this.groupLoaded = null;
            return;
        }

        this.form.staffgroup_id = String(group.id);
        this.form.zone_id = group.zone_id;
        this.form.schedule_id = group.schedule_id;
        this.form.vehicle_id = group.vehicle_id;
        this.form.driver_id = group.driver_id;
        this.form.weekdays = [...group.weekdays];
        this.form.helper_ids = [...group.helper_ids];
        this.fitSeats();
        this.groupLoaded = `Se cargó el grupo "${group.name}": indica las fechas y valida la disponibilidad.`;
    },

    toggleWeekday(day) {
        this.form.weekdays = this.form.weekdays.includes(day)
            ? this.form.weekdays.filter((value) => value !== day)
            : [...this.form.weekdays, day].sort((a, b) => a - b);
    },

    /** Lo que decide la disponibilidad; las observaciones no cuentan. */
    get draft() {
        const { notes, ...form } = this.form;
        return { ...form, helper_ids: form.helper_ids.filter(Boolean), id: config.id };
    },

    /** Sin esto el API contestaría "elige una zona" en vez de validar la disponibilidad. */
    get complete() {
        const form = this.form;
        return Boolean(form.zone_id && form.vehicle_id && form.schedule_id && form.driver_id
            && form.start_date && form.end_date && form.weekdays.length > 0);
    },

    get canSave() {
        return Boolean(this.validation?.valid);
    },

    get saveHint() {
        if (!this.complete) return this.isNew ? 'Elige un grupo y las fechas.' : 'Completa el formulario.';
        if (!this.validation) return 'Valida la disponibilidad para poder guardar.';
        return this.validation.valid ? '' : 'Corrige lo marcado y vuelve a validar.';
    },

    async validate() {
        if (!this.complete || this.checking) return;

        const draft = this.draft;
        this.checking = true;

        try {
            const { data } = await api.post('/schedulings/validate', draft);
            this.validation = data;
            this.validatedDraft = JSON.stringify(draft);
            this.outdated = false;
        } catch {
            this.validation = null;
        } finally {
            this.checking = false;
        }
    },

    /** Enter en un campo también envía: sin validación vigente no sale. */
    submit(event) {
        if (!this.canSave) {
            event.preventDefault();
            return;
        }

        event.target.querySelector('[type=submit]').disabled = true;
    },

    /** Los avisos de la validación, sin repetir. */
    get messages() {
        return [...new Set(Object.values(this.validation?.errors ?? {}).flat())];
    },

    /** "Lun 25/01/2027 · Feriado X". Fecha local armada a mano para no desfasar el día. */
    holidayLabel(holiday) {
        const [year, month, day] = holiday.date.split('-').map(Number);
        const weekday = DAY_SHORT[(new Date(year, month - 1, day).getDay() + 6) % 7];
        return `${weekday} ${formatDate(holiday.date)}${holiday.description ? ` · ${holiday.description}` : ''}`;
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
            ['Grupo', this.group?.name ?? null],
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
