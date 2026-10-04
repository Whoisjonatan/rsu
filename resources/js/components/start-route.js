import { api, errorMessage } from '../bootstrap';

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const DAYS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

/** "Martes 25 de agosto", sin pasar por toLocaleDateString ni su zona horaria. */
function longDate(iso) {
    const [year, month, day] = iso.split('-').map(Number);
    const name = DAYS[new Date(year, month - 1, day).getDay()];
    return `${name.charAt(0).toUpperCase()}${name.slice(1)} ${day} de ${MONTHS[month - 1]}`;
}

/**
 * W21 Validar e iniciar recorrido: el modal que abre el calendario (W20).
 *
 * Trabaja en vivo contra el API, que es quien sabe si el recorrido puede
 * salir (readiness): asistencia del personal, vehículo disponible y que siga
 * programado. Si alguien no marcó, ofrece el personal de reserva o posponer.
 */
export default () => ({
    routeId: null,
    route: null,
    readiness: null,
    reserves: [],
    choice: null,
    busy: false,
    error: null,

    async open(routeId) {
        this.routeId = routeId;
        this.route = null;
        this.readiness = null;
        this.reserves = [];
        this.choice = null;
        this.error = null;
        await this.load();
    },

    close() {
        this.routeId = null;
    },

    async load() {
        const { data } = await api.get(`/vehicleroutes/${this.routeId}`);
        this.route = data.data;
        this.readiness = data.readiness;

        // Quien no marcó es a quien hay que reemplazar; se pide su función
        // para no ofrecer un ayudante donde falta un conductor.
        const occupant = this.missingOccupant;
        this.reserves = [];

        if (occupant && this.route.status === 'programado') {
            const response = await api.get(`/vehicleroutes/${this.routeId}/reserves`, {
                params: { usertype_id: occupant.usertype_id },
            });
            this.reserves = response.data.data;
        }
    },

    get missing() {
        return this.readiness?.occupants.find((occupant) => !occupant.attended) ?? null;
    },

    get missingOccupant() {
        return this.route?.occupants?.find((occupant) => occupant.id === this.missing?.occupant_id) ?? null;
    },

    get description() {
        if (!this.route) return '';
        return `${longDate(this.route.date_route)} · turno ${this.route.schedule?.name ?? ''}${this.route.zone ? ` · zona ${this.route.zone.name}` : ''}`;
    },

    get canStart() {
        return this.readiness?.ready === true;
    },

    get actionLabel() {
        if (this.choice === 'postpone') return 'Posponer recorrido';
        if (typeof this.choice === 'number') return 'Reemplazar';
        return 'Iniciar recorrido';
    },

    shortTime(time) {
        return time ? time.slice(0, 5) : '—';
    },

    async submit() {
        this.busy = true;
        this.error = null;

        try {
            // "No reemplazar y posponer": el recorrido no sale, y queda dicho por qué.
            if (this.choice === 'postpone') {
                await api.post(`/vehicleroutes/${this.routeId}/block`, {
                    block_reason: 'Pospuesto: el personal asignado no marcó asistencia',
                });
                window.location.reload();
                return;
            }

            if (typeof this.choice === 'number' && this.missing) {
                await api.put(`/vehicleroutes/${this.routeId}/occupants/${this.missing.occupant_id}`, {
                    user_id: this.choice,
                });
                // No se inicia en la misma pulsación: tras el reemplazo la
                // checklist se recarga y el usuario confirma ya con todo en verde.
                this.choice = null;
                await this.load();
                return;
            }

            await api.post(`/vehicleroutes/${this.routeId}/start`);
            window.location.reload();
        } catch (error) {
            this.error = errorMessage(error, 'No se pudo iniciar el recorrido');
        } finally {
            this.busy = false;
        }
    },
});
