import { api, errorMessage } from '../bootstrap';

const BADGE_TONES = {
    green: 'bg-brand-50 text-brand-700 ring-brand-600/20',
    amber: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    red: 'bg-red-50 text-red-700 ring-red-600/20',
    slate: 'bg-stone-100 text-stone-600 ring-stone-500/20',
};

/**
 * Dashboard: el filtro de los cuatro indicadores y los modales de cada card.
 *
 * - Pulsar un indicador deja abajo solo sus programaciones; "Personal
 *   faltante" muestra las que tienen a alguien sin marcar o con falta.
 * - "Ver detalles" enseña la asistencia de la tripulación y deja cambiar a
 *   quien falta por personal disponible.
 * - "Modificar programación" es el editor de turno, vehículo y personal.
 *
 * Los dos mandan una lista de cambios a la reprogramación de siempre
 * (SchedulingController::update), que los valida con las mismas reglas que
 * un alta y deja el historial. Los combos ofrecen solo lo que está libre ese
 * día en el turno elegido (DailyDashboard::options); si se guarda, la página
 * se recarga para ver la card al día.
 */
export default (config) => ({
    options: config.options,
    reasons: config.reasons,
    updateUrl: config.updateUrl,

    // Indicador elegido arriba: total, completa, incompleta o faltantes. Va en
    // la URL (?ver=) para que sobreviva a la recarga tras guardar un cambio.
    counts: config.counts ?? {},
    filter: 'total',

    // El día mostrado es hoy: al cambiar personal solo se ofrece a quien ya
    // marcó entrada en el turno (en un día futuro nadie ha podido marcar).
    isToday: Boolean(config.isToday),

    // Cambiar personal o modificar la programación exige el permiso schedulings.edit.
    canEdit: Boolean(config.canEdit),

    detail: null,

    init() {
        const fromUrl = new URLSearchParams(window.location.search).get('ver');
        if (fromUrl && fromUrl in this.counts) {
            this.filter = fromUrl;
        }
    },

    setFilter(key) {
        // Volver a pulsar el indicador activo quita el filtro.
        this.filter = this.filter === key ? 'total' : key;

        const url = new URL(window.location.href);
        if (this.filter === 'total') {
            url.searchParams.delete('ver');
        } else {
            url.searchParams.set('ver', this.filter);
        }
        window.history.replaceState(null, '', url);
    },
    // Reemplazo de quien falta, dentro de "Ver detalles".
    replacing: null,

    editing: null,
    editor: null,

    busy: false,
    error: null,

    openDetail(card) {
        this.detail = card;
        this.replacing = null;
        this.error = null;
    },

    closeDetail() {
        this.detail = null;
        this.replacing = null;
    },

    openEditor(card) {
        this.detail = null;
        this.error = null;
        this.editing = card;
        this.editor = {
            schedule_id: String(card.editor.schedule_id),
            vehicle_id: String(card.editor.vehicle_id),
            people: Object.fromEntries(card.editor.crew.map((member) => [member.id, ''])),
            reasons: {
                turno: { reason: '', description: '' },
                vehiculo: { reason: '', description: '' },
                personal: { reason: '', description: '' },
            },
        };
    },

    closeEditor() {
        this.editing = null;
        this.editor = null;
    },

    /** ¿Lo tiene otra programación u otro grupo en ese turno? Los de la propia card no cuentan. */
    isBusy(kind, scheduleId, id, card) {
        const owners = this.options[kind]?.[scheduleId]?.[id] ?? [];
        return owners.some((owner) => !card.owners.includes(owner));
    },

    /** Si el motivo pide describir el cambio (Gestión de cambios › Motivos). */
    needsDescription(section, name) {
        return Boolean(this.reasons[section]?.find((reason) => reason.name === name)?.requires_description);
    },

    /** La marcación de la persona en ese turno (la asistencia es por turno). */
    markIn(person, scheduleId) {
        return person.marks?.[scheduleId] ?? null;
    },

    markLabel(person, scheduleId) {
        const mark = this.markIn(person, scheduleId);
        return mark ? ` · ${mark}` : ' · sin marcar en este turno';
    },

    /** Los mismos tonos que x-badge. */
    badgeClass(tone) {
        return BADGE_TONES[tone] ?? BADGE_TONES.slate;
    },

    initials(name) {
        // "Apellidos, Nombres": la inicial del primer apellido y la del primer nombre.
        const [last = '', first = ''] = (name ?? '').split(',').map((part) => part.trim());
        return `${last.charAt(0)}${first.charAt(0)}`.toUpperCase() || '?';
    },

    // --- Reemplazo de personal faltante -------------------------------------

    canReplace(member) {
        return this.canEdit && this.detail?.editable && this.detail.status === 'incompleta' && !member.present && member.replaceable;
    },

    startReplace(member) {
        this.error = null;
        this.replacing = {
            user_id: member.user_id,
            full_name: member.full_name,
            role: member.role.toLowerCase().includes('conductor') ? 'conductor' : 'ayudante',
            new_id: '',
            reason: member.mark[0] === 'Falta' ? 'Falta' : '',
            description: '',
        };
    },

    /** Del mismo rol, libres en el turno y fuera de la tripulación; los que ya marcaron en el turno, primero. */
    get replaceOptions() {
        if (!this.detail || !this.replacing) return [];

        const crew = this.detail.editor.crew.map((member) => member.id);
        const shift = this.detail.editor.schedule_id;

        // Solo quien marcó entrada en este turno: al faltante se le reemplaza con alguien que ya está trabajando.
        return this.options.staff
            .filter((person) => person.role === this.replacing.role
                && this.markIn(person, shift)
                && !crew.includes(person.id)
                && !this.isBusy('busy_staff', shift, person.id, this.detail))
            .sort((a, b) => a.full_name.localeCompare(b.full_name));
    },

    async submitReplace() {
        const row = this.replacing;
        this.error = null;

        if (!row.new_id) return (this.error = 'Elige al nuevo personal.');
        if (!row.reason) return (this.error = 'Elige el motivo del cambio.');
        if (this.needsDescription('personal', row.reason) && !row.description.trim()) return (this.error = `Con el motivo «${row.reason}» describe el motivo del cambio.`);

        await this.send(this.detail.id, [{
            type: 'personal',
            old_id: row.user_id,
            new_id: Number(row.new_id),
            reason: row.reason,
            description: row.description.trim() || null,
            present_only: true,
        }]);
    },

    // --- Editor de programación ---------------------------------------------

    get vehicleOptions() {
        if (!this.editing) return [];

        const current = { id: this.editing.editor.vehicle_id, label: this.editing.editor.vehicle };
        const free = this.options.vehicles.filter((vehicle) => vehicle.id !== current.id
            && !this.isBusy('busy_vehicles', this.editor.schedule_id, vehicle.id, this.editing));

        return [current, ...free];
    },

    /** Para la fila de `member`: su rol, libres en el turno nuevo, ni de la tripulación ni elegidos en otra fila. */
    personOptions(member) {
        const crew = this.editing.editor.crew.map((m) => m.id);
        const chosen = Object.entries(this.editor.people)
            .filter(([id, value]) => Number(id) !== member.id && value !== '')
            .map(([, value]) => Number(value));

        return this.options.staff
            .filter((person) => person.role === member.role
                && (!this.isToday || this.markIn(person, this.editor.schedule_id))
                && !crew.includes(person.id)
                && !chosen.includes(person.id)
                && !this.isBusy('busy_staff', this.editor.schedule_id, person.id, this.editing))
            .sort((a, b) => Number(!this.markIn(a, this.editor.schedule_id)) - Number(!this.markIn(b, this.editor.schedule_id)));
    },

    get changed() {
        if (!this.editor) return { turno: false, vehiculo: false, personal: false };

        return {
            turno: this.editor.schedule_id !== String(this.editing.editor.schedule_id),
            vehiculo: this.editor.vehicle_id !== String(this.editing.editor.vehicle_id),
            personal: Object.values(this.editor.people).some((value) => value !== ''),
        };
    },

    get hasChanges() {
        return Object.values(this.changed).some(Boolean);
    },

    async submitEditor() {
        this.error = null;
        const changes = [];

        for (const section of ['turno', 'vehiculo', 'personal']) {
            if (!this.changed[section]) continue;

            const { reason, description } = this.editor.reasons[section];
            const label = { turno: 'turno', vehiculo: 'vehículo', personal: 'personal' }[section];

            if (!reason) return (this.error = `Elige el motivo del cambio de ${label}.`);
            if (this.needsDescription(section, reason) && !description.trim()) return (this.error = `Con el motivo «${reason}» describe el cambio de ${label}.`);

            const common = { reason, description: description.trim() || null };

            if (section === 'turno') changes.push({ type: 'turno', new_id: Number(this.editor.schedule_id), ...common });
            if (section === 'vehiculo') changes.push({ type: 'vehiculo', new_id: Number(this.editor.vehicle_id), ...common });
            if (section === 'personal') {
                Object.entries(this.editor.people)
                    .filter(([, value]) => value !== '')
                    .forEach(([oldId, newId]) => changes.push({ type: 'personal', old_id: Number(oldId), new_id: Number(newId), present_only: this.isToday, ...common }));
            }
        }

        if (changes.length === 0) return (this.error = 'No hay cambios que guardar.');

        await this.send(this.editing.id, changes);
    },

    async send(schedulingId, changes) {
        this.busy = true;

        try {
            // Es la ruta web de la reprogramación, no la del API.
            await api.put(this.updateUrl.replace('__ID__', schedulingId), { changes }, { baseURL: '/' });
            window.location.reload();
        } catch (error) {
            const list = error?.response?.data?.errors?.changes;
            this.error = Array.isArray(list) && list.length > 1 ? list.join(' ') : errorMessage(error, 'No se pudieron guardar los cambios');
            this.busy = false;
        }
    },
});
