/**
 * Alta, edición y baja en modales: el patrón de los catálogos (W03, W06,
 * W13, W16...). El formulario es HTML normal que se envía al servidor; Alpine
 * solo abre el modal, lo rellena y elige la URL.
 *
 * Si el servidor rechaza la validación, Laravel redirige de vuelta con los
 * datos escritos (old) y los errores. Para que el usuario no los pierda, cada
 * formulario manda un campo oculto `_modal` con su nombre: si coincide al
 * volver, el modal se reabre tal y como estaba.
 *
 * @param {object} config
 * @param {string} config.name        Nombre del modal (el valor de `_modal`).
 * @param {string} config.storeUrl    POST de alta.
 * @param {string} config.updateUrl   PUT de edición, con __ID__ en lugar del id.
 * @param {string} [config.destroyUrl] DELETE, con __ID__.
 * @param {object} config.defaults    Valores del formulario vacío.
 * @param {object} [config.old]       Lo que el usuario había escrito (old()).
 * @param {object} [config.errors]    Errores de validación del servidor.
 */
export default (config) => ({
    open: false,
    editingId: null,
    form: { ...config.defaults },
    errors: {},
    toDelete: null,

    init() {
        const old = config.old ?? {};

        if (old._modal === config.name) {
            this.editingId = old._id ? Number(old._id) : null;
            this.form = { ...config.defaults, ...old };
            this.errors = config.errors ?? {};
            this.open = true;
        }
    },

    create(values = {}) {
        this.editingId = null;
        this.form = { ...config.defaults, ...values };
        this.errors = {};
        this.open = true;
    },

    edit(row) {
        this.editingId = row.id;
        // Los null de la base se vuelven cadena vacía: un input no sabe
        // mostrar null y x-model escribiría "null".
        const values = Object.fromEntries(
            Object.entries(row).map(([key, value]) => [key, value ?? '']),
        );
        this.form = { ...config.defaults, ...values };
        this.errors = {};
        this.open = true;
    },

    close() {
        this.open = false;
    },

    get action() {
        return this.editingId
            ? config.updateUrl.replace('__ID__', this.editingId)
            : config.storeUrl;
    },

    get method() {
        return this.editingId ? 'PUT' : 'POST';
    },

    error(field) {
        return this.errors[field]?.[0] ?? null;
    },

    askDelete(row) {
        this.toDelete = row;
    },

    get deleteAction() {
        return this.toDelete ? config.destroyUrl.replace('__ID__', this.toDelete.id) : '';
    },
});
