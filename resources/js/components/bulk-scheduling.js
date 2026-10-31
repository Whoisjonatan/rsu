import { api, validationErrors } from '../bootstrap';

const DAY_SHORT = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

/** "2026-08-24" → "24/08/2026" sin pasar por Date, que desfasaría la zona. */
function formatDate(iso) {
    if (!iso) return '—';
    const [year, month, day] = iso.split('-');
    return `${day}/${month}/${year}`;
}

/**
 * W19 Programación masiva: los grupos de personal activos en un rango de fechas.
 *
 * - Los feriados del rango salen marcados: no se programan. Desmarcar uno lo
 *   trabaja aunque sea feriado.
 * - Cada grupo trae su tripulación; conductor y ayudantes se pueden cambiar.
 *   El filtro de turno y la casilla de cada fila deciden qué grupos van.
 * - "Validar disponibilidad" revisa el lote en el API sin guardar. Solo se
 *   guarda con una validación correcta de lo que hay en pantalla: cambiar algo
 *   obliga a validar otra vez.
 */
export default (config) => ({
    catalogs: config.catalogs,
    form: { start_date: '', end_date: '', schedule_id: '' },
    // Feriados desmarcados: se trabajan. Guardar los desmarcados y no los
    // marcados hace que un feriado que entra al ampliar el rango llegue marcado.
    worked: [],
    rows: config.catalogs.groups.map((group) => ({
        id: group.id,
        include: true,
        driver_id: group.driver_id,
        helper_ids: Array.from({ length: group.seats }, (_, index) => group.helper_ids[index] ?? ''),
    })),
    report: null,
    validatedDraft: null,
    outdated: false,
    checking: false,
    saving: false,
    error: null,
    collapsed: [],
    // La búsqueda y la página solo cambian lo que se ve: los grupos marcados
    // que quedan fuera siguen entrando al validar y guardar.
    search: '',
    page: 1,
    perPage: 9,

    init() {
        // Lo validado deja de valer en cuanto cambia algo de lo que se enviará.
        this.$watch('draft', () => {
            if (this.report && JSON.stringify(this.draft) !== this.validatedDraft) {
                this.report = null;
                this.outdated = true;
            }
        });
    },

    group(id) {
        return this.catalogs.groups.find((group) => group.id === id);
    },

    /** Feriados activos dentro del rango elegido. */
    get holidays() {
        const { start_date: start, end_date: end } = this.form;
        if (!start || !end || start > end) return [];
        return this.catalogs.holidays.filter((holiday) => holiday.date >= start && holiday.date <= end);
    },

    isSkipped(date) {
        return !this.worked.includes(date);
    },

    toggleHoliday(date) {
        this.worked = this.worked.includes(date) ? this.worked.filter((item) => item !== date) : [...this.worked, date];
    },

    /** Filas del turno elegido (o todas). */
    get visibleRows() {
        return this.rows.filter((row) => !this.form.schedule_id || String(this.group(row.id).schedule_id) === String(this.form.schedule_id));
    },

    /** Las del turno que coinciden con la búsqueda (nombre del grupo o zona). */
    get shownRows() {
        const term = this.search.trim().toLocaleLowerCase('es');
        if (!term) return this.visibleRows;
        return this.visibleRows.filter((row) => {
            const group = this.group(row.id);
            return `${group.name} ${group.zone ?? ''}`.toLocaleLowerCase('es').includes(term);
        });
    },

    get pagedRows() {
        return this.shownRows.slice((this.page - 1) * this.perPage, this.page * this.perPage);
    },

    get selectedRows() {
        return this.visibleRows.filter((row) => row.include);
    },

    get allSelected() {
        return this.visibleRows.length > 0 && this.visibleRows.every((row) => row.include);
    },

    toggleAll() {
        const value = !this.allSelected;
        this.visibleRows.forEach((row) => (row.include = value));
    },

    /** Ayudantes que se pueden elegir en ese asiento: ni el conductor ni los de otros asientos de la fila. */
    helperOptions(row, index) {
        const taken = [row.driver_id, ...row.helper_ids.filter((_, other) => other !== index)].filter(Boolean).map(String);
        return this.catalogs.helpers.filter((person) => !taken.includes(String(person.id)));
    },

    /** Lo que se envía al API. */
    get draft() {
        return {
            start_date: this.form.start_date,
            end_date: this.form.end_date,
            skip_holidays: this.holidays.map((holiday) => holiday.date).filter((date) => this.isSkipped(date)),
            rows: this.selectedRows.map((row) => ({
                staffgroup_id: row.id,
                driver_id: row.driver_id,
                helper_ids: row.helper_ids.filter(Boolean),
            })),
        };
    },

    get complete() {
        const { start_date: start, end_date: end } = this.form;
        return Boolean(start && end && start <= end && this.selectedRows.length > 0
            && this.selectedRows.every((row) => row.driver_id));
    },

    get canSave() {
        return Boolean(this.report?.valid) && !this.saving;
    },

    get hint() {
        const { start_date: start, end_date: end } = this.form;
        if (!start || !end) return 'Elige la fecha de inicio y de término.';
        if (start > end) return 'La fecha de término no puede ser anterior a la de inicio.';
        if (this.selectedRows.length === 0) return 'Marca al menos un grupo.';
        if (!this.selectedRows.every((row) => row.driver_id)) return 'Cada grupo marcado necesita un conductor.';
        if (!this.report) return this.outdated ? 'Cambiaste datos: vuelve a validar.' : 'Valida la disponibilidad para poder guardar.';
        return this.report.valid ? '' : 'Corrige las inconsistencias y vuelve a validar.';
    },

    /** Resultado de la validación de ese grupo, si ya se validó. */
    result(id) {
        return this.report?.groups.find((group) => group.staffgroup_id === id) ?? null;
    },

    /** Borde de la card: apagada si no va; con el color de su validación si ya se validó. */
    cardClass(row) {
        if (!row.include) return 'border-stone-200 opacity-60';
        const tones = {
            error: 'border-red-300 ring-1 ring-red-200',
            warning: 'border-amber-300 ring-1 ring-amber-200',
            ok: 'border-brand-300 ring-1 ring-brand-200',
        };
        return tones[this.result(row.id)?.status] ?? 'border-brand-200';
    },

    /** "2 errores · ver detalle" en el pie de la card. */
    resultSummary(id) {
        const result = this.result(id);
        if (!result) return '';
        const count = (n, one, many) => `${n} ${n === 1 ? one : many}`;
        if (result.errors.length) return `${count(result.errors.length, 'error', 'errores')} · ver detalle`;
        if (result.warnings.length) return `${count(result.warnings.length, 'advertencia', 'advertencias')} · ver detalle`;
        return `Generará ${count(result.occurrences, 'programación individual', 'programaciones individuales')}`;
    },

    /** Lleva a la página donde está la card del grupo (quitando la búsqueda si lo oculta). */
    goToCard(id) {
        if (!this.shownRows.some((row) => row.id === id)) this.search = '';
        const index = this.shownRows.findIndex((row) => row.id === id);
        if (index >= 0) this.page = Math.floor(index / this.perPage) + 1;
        this.$nextTick(() => document.getElementById(`card-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    },

    /** Abre el panel de ese grupo en el resultado y baja hasta él. */
    showResult(id) {
        this.collapsed = this.collapsed.filter((item) => item !== id);
        this.$nextTick(() => document.getElementById(`result-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    },

    toggleResult(id) {
        this.collapsed = this.collapsed.includes(id) ? this.collapsed.filter((item) => item !== id) : [...this.collapsed, id];
    },

    async validate() {
        if (!this.complete || this.checking) return;

        const draft = this.draft;
        this.checking = true;
        this.error = null;

        try {
            const { data } = await api.post('/schedulings/bulk/validate', draft);
            this.report = data;
            this.validatedDraft = JSON.stringify(draft);
            this.outdated = false;
            // Abiertos solo los que tienen algo que corregir.
            this.collapsed = data.groups.filter((group) => group.status === 'ok').map((group) => group.staffgroup_id);
            this.$nextTick(() => this.$refs.report?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        } catch (error) {
            this.report = null;
            this.error = this.errorMessage(error);
        } finally {
            this.checking = false;
        }
    },

    async save() {
        if (!this.canSave) return;

        this.saving = true;
        this.error = null;

        try {
            const { data } = await api.post('/schedulings/bulk', this.draft);
            window.location.assign(data.redirect);
        } catch (error) {
            // Algo cambió entre validar y guardar (otro usuario programó): se
            // enseña el resultado nuevo para corregirlo.
            if (error.response?.status === 422 && error.response.data?.groups) {
                this.report = error.response.data;
            }
            this.error = this.errorMessage(error);
            this.saving = false;
        }
    },

    errorMessage(error) {
        const first = Object.values(validationErrors(error)).flat()[0];
        return first ?? error.response?.data?.message ?? 'No se pudo completar. Inténtalo de nuevo.';
    },

    /** "Lun 01/05/2026". Fecha local armada a mano para no desfasar el día. */
    holidayDay(date) {
        const [year, month, day] = date.split('-').map(Number);
        return `${DAY_SHORT[(new Date(year, month - 1, day).getDay() + 6) % 7]} ${formatDate(date)}`;
    },
});
