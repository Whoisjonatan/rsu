import { api } from '../bootstrap';

const DAY_SHORT = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const MAX_SEATS = 10;

/** Sin tildes ni mayúsculas: "PÉREZ" se encuentra escribiendo "perez". */
function plain(text) {
    return String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Formulario de grupo de personal. Se envía al servidor de forma normal;
 * este componente añade lo que pasa mientras se rellena:
 *
 * - Cuántos ayudantes caben: un selector por asiento según la capacidad del
 *   vehículo (capacidad − 1 conductor).
 * - Buscador de personal por DNI, nombre o apellido, con la ficha de quien se
 *   elige.
 * - Disponibilidad en vivo (API staff-groups/availability) para el turno y
 *   los días marcados: quién está libre, quién no y por qué, y a quién se
 *   recomienda en su lugar.
 */
export default (config) => ({
    form: config.form,
    vehicles: config.vehicles,
    people: config.people,
    scheduleLabels: config.scheduleLabels,
    availability: null,
    loading: false,
    pickers: {},
    timer: null,

    init() {
        for (const slot of ['driver', ...Array.from({ length: MAX_SEATS }, (_, index) => `h${index}`)]) {
            this.pickers[slot] = { query: '', open: false };
        }

        this.fitSeats();
        this.$watch('form.vehicle_id', () => this.fitSeats());

        // Turno y días deciden quién está ocupado; se espera a que dejen de cambiar.
        this.$watch('form.schedule_id', () => this.refresh());
        this.$watch('form.weekdays', () => this.refresh());
        this.load();
    },

    refresh() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 300);
    },

    async load() {
        this.loading = true;

        try {
            const { data } = await api.post('/staff-groups/availability', {
                schedule_id: this.form.schedule_id || null,
                weekdays: this.form.weekdays,
                ignore_id: config.id,
                keep: this.selectedIds,
            });
            this.availability = data;
        } catch {
            this.availability = null;
        } finally {
            this.loading = false;
        }
    },

    get vehicle() {
        return this.vehicles.find((vehicle) => String(vehicle.id) === String(this.form.vehicle_id)) ?? null;
    },

    get seats() {
        return this.vehicle ? Math.min(this.vehicle.seats, MAX_SEATS) : 0;
    },

    /** Un asiento por ayudante: se rellenan o recortan al cambiar de vehículo. */
    fitSeats() {
        const helpers = this.form.helper_ids.slice(0, this.seats);
        while (helpers.length < this.seats) helpers.push('');
        this.form.helper_ids = helpers;
    },

    toggleWeekday(day) {
        this.form.weekdays = this.form.weekdays.includes(day)
            ? this.form.weekdays.filter((value) => value !== day)
            : [...this.form.weekdays, day].sort((a, b) => a - b);
    },

    get weekdaysLabel() {
        return this.form.weekdays.map((day) => DAY_SHORT[day - 1]).join(', ');
    },

    // --- Asientos: "driver" es el conductor; "h0", "h1"... los ayudantes. ---

    role(slot) {
        return slot === 'driver' ? 'driver' : 'helper';
    },

    value(slot) {
        return slot === 'driver' ? this.form.driver_id : this.form.helper_ids[Number(slot.slice(1))];
    },

    setValue(slot, id) {
        if (slot === 'driver') {
            this.form.driver_id = id;
        } else {
            this.form.helper_ids[Number(slot.slice(1))] = id;
        }
    },

    get selectedIds() {
        return [this.form.driver_id, ...this.form.helper_ids].filter(Boolean).map(Number);
    },

    person(id) {
        return this.people.find((person) => String(person.id) === String(id)) ?? null;
    },

    /** Disponibilidad de una persona; null mientras no se sabe. */
    statusOf(id) {
        return this.availability?.people?.[id] ?? null;
    },

    state(id) {
        const status = this.statusOf(id);
        if (!status) return 'unknown';
        if (!status.available) return 'busy';
        return status.warnings.length ? 'warning' : 'free';
    },

    stateLabel(id) {
        return { unknown: 'Consultando…', busy: 'No disponible', warning: 'Disponible con avisos', free: 'Disponible' }[this.state(id)];
    },

    /** Orden de sugerencia: libres, luego con avisos, luego ocupados; los de la zona del grupo primero. */
    rank(person) {
        const order = { free: 0, warning: 1, unknown: 2, busy: 3 }[this.state(person.id)];
        const zone = String(person.zone_id) === String(this.form.zone_id) ? 0 : 1;
        return order * 2 + zone;
    },

    candidates(slot) {
        const taken = this.selectedIds.filter((id) => String(id) !== String(this.value(slot)));
        return this.people.filter((person) => person.role === this.role(slot) && !taken.includes(person.id));
    },

    matches(slot) {
        const query = plain(this.pickers[slot].query.trim());
        return this.candidates(slot)
            .filter((person) => !query || plain(person.full_name).includes(query) || String(person.dni).includes(query))
            .sort((a, b) => this.rank(a) - this.rank(b))
            .slice(0, 8);
    },

    /** Hasta tres personas libres, para ofrecer en lugar de alguien no disponible. */
    recommendations(slot) {
        return this.candidates(slot)
            .filter((person) => String(person.id) !== String(this.value(slot)) && ['free', 'warning'].includes(this.state(person.id)))
            .sort((a, b) => this.rank(a) - this.rank(b))
            .slice(0, 3);
    },

    open(slot) {
        this.pickers[slot].open = true;
    },

    close(slot) {
        this.pickers[slot].open = false;
    },

    choose(slot, id) {
        this.setValue(slot, String(id));
        this.pickers[slot] = { query: '', open: false };
    },

    clear(slot) {
        this.setValue(slot, '');
        this.$nextTick(() => this.$root.querySelector(`[data-picker="${slot}"]`)?.focus());
    },

    // --- Vehículo ---

    vehicleStatus() {
        return this.vehicle ? (this.availability?.vehicles?.[this.vehicle.id] ?? null) : null;
    },

    vehicleOption(vehicle) {
        const busy = this.availability?.vehicles?.[vehicle.id]?.available === false;
        return `${vehicle.code} · ${vehicle.plate} · ${vehicle.type ?? 'Sin tipo'} (Capacidad: ${vehicle.capacity})${busy ? ' · ocupado' : ''}`;
    },

    get crewLabel() {
        if (!this.vehicle) return '';
        const seats = this.vehicle.seats;
        return `1 conductor + ${seats} ${seats === 1 ? 'ayudante' : 'ayudantes'}`;
    },

    // --- Resumen ---

    get checks() {
        const helpers = this.form.helper_ids.filter(Boolean);
        const vehicle = this.vehicleStatus();

        return [
            ['Vehículo libre en ese turno', this.vehicle ? (vehicle ? vehicle.available : null) : null],
            ['Conductor disponible', this.form.driver_id ? (this.statusOf(this.form.driver_id)?.available ?? null) : null],
            ['Ayudantes disponibles', helpers.length ? (helpers.every((id) => this.statusOf(id)?.available) ? true : (this.availability ? false : null)) : null],
            [`Tripulación completa (${helpers.length} de ${this.seats} ayudantes)`, this.vehicle ? helpers.length === this.seats : null],
        ];
    },
});
