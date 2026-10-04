import './bootstrap';

import focus from '@alpinejs/focus';
import Alpine from 'alpinejs';

import crud from './components/crud';
import mapPicker from './components/map-picker';

Alpine.plugin(focus);

// Componentes compartidos. Lo propio de una sola pantalla se registra en su
// vista con document.addEventListener('alpine:init', ...).
Alpine.data('crud', crud);
Alpine.data('mapPicker', mapPicker);

window.Alpine = Alpine;

Alpine.start();
