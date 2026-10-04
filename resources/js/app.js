import './bootstrap';

import focus from '@alpinejs/focus';
import Alpine from 'alpinejs';

import crud from './components/crud';

Alpine.plugin(focus);

// Componentes compartidos. Lo propio de una sola pantalla se registra en su
// vista con document.addEventListener('alpine:init', ...).
Alpine.data('crud', crud);

window.Alpine = Alpine;

Alpine.start();
