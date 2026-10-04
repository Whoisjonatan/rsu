import axios from 'axios';

/**
 * Cliente del API para las pocas pantallas que consultan en vivo (DNI,
 * checklist de W18, edición masiva W19, inicio de recorrido W21).
 *
 * Va con la cookie de sesión de las vistas Blade, no con token: el front y el
 * API comparten dominio. Axios lee la cookie XSRF-TOKEN que deja Laravel y la
 * devuelve como cabecera X-XSRF-TOKEN, que es lo que pide la protección CSRF.
 */
export const api = axios.create({
    baseURL: '/api',
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        // Sesión vencida mientras la página seguía abierta: de vuelta al login.
        if (error.response?.status === 401 && window.location.pathname !== '/marcacion') {
            window.location.assign('/login');
        }

        return Promise.reject(error);
    },
);

/** Errores de validación de Laravel, por campo. */
export function validationErrors(error) {
    if (error?.response?.status === 422) {
        return error.response.data?.errors ?? {};
    }

    return {};
}

/** Mensaje legible para mostrar al usuario. */
export function errorMessage(error, fallback = 'Ocurrió un error inesperado') {
    return error?.response?.data?.message ?? fallback;
}

window.api = api;
