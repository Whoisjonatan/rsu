const TYPE_LABELS = {
    turno: 'Turno',
    vehiculo: 'Vehículo',
    conductor: 'Personal (conductor)',
    ayudante: 'Personal (ayudante)',
};

const OTHER = 'Otro';

const blankForm = () => ({ old_id: '', new_id: '', reason: '', description: '' });

/**
 * Modificar programación: cambios de turno, vehículo y personal.
 *
 * "Agregar cambio" solo lo pone en la tabla como pendiente; nada se guarda
 * hasta "Guardar cambios", que envía la lista al servidor. Ahí se aplican en
 * orden y se valida lo que resulta con las mismas reglas que un alta. Cada
 * sección muestra el valor actual contando lo pendiente, así dos cambios
 * seguidos encadenan bien (el segundo parte de lo que dejó el primero).
 */
export default (config) => ({
    original: config.original,
    catalogs: config.catalogs,
    pending: config.pending ?? [],
    // Lo ya guardado, del más reciente al más antiguo, de 5 en 5.
    history: config.history ?? [],
    historyPage: 1,
    historyPerPage: 5,
    forms: { turno: blankForm(), vehiculo: blankForm(), personal: blankForm() },
    formErrors: { turno: null, vehiculo: null, personal: null },
    submitting: false,

    find(list, id) {
        return this.catalogs[list].find((item) => String(item.id) === String(id)) ?? null;
    },

    /**
     * Repite los cambios pendientes sobre la programación: devuelve cómo
     * queda y, por cada cambio, sus valores anterior y nuevo.
     */
    get replay() {
        const state = { ...this.original, helper_ids: [...this.original.helper_ids] };
        const rows = this.pending.map((change) => {
            if (change.type === 'turno') {
                const row = { type: 'turno', old: this.find('schedules', state.schedule_id)?.label, new: this.find('schedules', change.new_id)?.label };
                state.schedule_id = String(change.new_id);
                return row;
            }

            if (change.type === 'vehiculo') {
                const row = { type: 'vehiculo', old: this.find('vehicles', state.vehicle_id)?.label, new: this.find('vehicles', change.new_id)?.label };
                state.vehicle_id = String(change.new_id);
                return row;
            }

            const isDriver = String(change.old_id) === String(state.driver_id);
            const row = {
                type: isDriver ? 'conductor' : 'ayudante',
                old: this.find(isDriver ? 'drivers' : 'helpers', change.old_id)?.full_name,
                new: this.find(isDriver ? 'drivers' : 'helpers', change.new_id)?.full_name,
            };

            if (isDriver) {
                state.driver_id = String(change.new_id);
            } else {
                state.helper_ids = state.helper_ids.map((id) => (String(id) === String(change.old_id) ? String(change.new_id) : id));
            }

            return row;
        });

        return { state, rows };
    },

    get current() {
        return this.replay.state;
    },

    get pendingRows() {
        return this.replay.rows.map((row, index) => ({
            ...row,
            typeLabel: TYPE_LABELS[row.type],
            reason: this.pending[index].reason,
            description: this.pending[index].description,
        }));
    },

    get pagedHistory() {
        return this.history.slice((this.historyPage - 1) * this.historyPerPage, this.historyPage * this.historyPerPage);
    },

    get currentSchedule() {
        return this.find('schedules', this.current.schedule_id)?.label ?? '—';
    },

    get currentVehicle() {
        return this.find('vehicles', this.current.vehicle_id) ?? null;
    },

    get scheduleOptions() {
        return this.catalogs.schedules.filter((item) => String(item.id) !== String(this.current.schedule_id));
    },

    get vehicleOptions() {
        return this.catalogs.vehicles.filter((item) => item.operational && String(item.id) !== String(this.current.vehicle_id));
    },

    /** La tripulación actual, para elegir a quién se reemplaza. */
    get crew() {
        const { driver_id: driverId, helper_ids: helperIds } = this.current;
        return [
            { id: String(driverId), role: 'conductor', label: `Conductor · ${this.find('drivers', driverId)?.full_name ?? '—'}` },
            ...helperIds.map((id, index) => ({
                id: String(id),
                role: 'ayudante',
                label: `Ayudante ${index + 1} · ${this.find('helpers', id)?.full_name ?? '—'}`,
            })),
        ];
    },

    get selectedMember() {
        return this.crew.find((member) => member.id === String(this.forms.personal.old_id)) ?? null;
    },

    /** Reemplazos de la misma función que no van ya en la programación. */
    get personOptions() {
        if (!this.selectedMember) return [];
        const taken = this.crew.map((member) => member.id);
        const list = this.selectedMember.role === 'conductor' ? this.catalogs.drivers : this.catalogs.helpers;
        return list.filter((person) => !taken.includes(String(person.id)));
    },

    /** Con un vehículo más chico la tripulación puede no caber: el servidor lo rechazará. */
    get seatsWarning() {
        const vehicle = this.find('vehicles', this.forms.vehiculo.new_id);
        const helpers = this.current.helper_ids.length;
        if (!vehicle || helpers <= vehicle.seats) return null;
        return `Este vehículo admite ${vehicle.seats === 1 ? '1 ayudante' : `${vehicle.seats} ayudantes`} y la programación lleva ${helpers}: reemplaza o quita ayudantes antes.`;
    },

    addChange(section) {
        const form = this.forms[section];
        this.formErrors[section] = null;

        if (section === 'personal' && !form.old_id) return (this.formErrors[section] = 'Elige a qué persona se reemplaza.');
        if (!form.new_id) return (this.formErrors[section] = section === 'turno' ? 'Elige el nuevo turno.' : section === 'vehiculo' ? 'Elige el nuevo vehículo.' : 'Elige el nuevo personal.');
        if (!form.reason) return (this.formErrors[section] = 'Elige un motivo.');
        if (form.reason === OTHER && !form.description.trim()) return (this.formErrors[section] = 'Con el motivo «Otro» describe el motivo del cambio.');

        this.pending.push({
            type: section,
            old_id: section === 'personal' ? form.old_id : '',
            new_id: form.new_id,
            reason: form.reason,
            description: form.description.trim(),
        });
        this.forms[section] = blankForm();
    },

    removeChange(index) {
        this.pending.splice(index, 1);
    },

    submit(event) {
        if (this.pending.length === 0 || this.submitting) {
            event.preventDefault();
            return;
        }

        this.submitting = true;
    },
});
