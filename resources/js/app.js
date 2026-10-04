import './bootstrap';

import focus from '@alpinejs/focus';
import Alpine from 'alpinejs';

import crud from './components/crud';
import mapPicker from './components/map-picker';
import schedulingForm from './components/scheduling-form';
import startRoute from './components/start-route';
import bulkEdit from './components/bulk-edit';

Alpine.plugin(focus);

Alpine.data('crud', crud);
Alpine.data('mapPicker', mapPicker);
Alpine.data('schedulingForm', schedulingForm);
Alpine.data('startRoute', startRoute);
Alpine.data('bulkEdit', bulkEdit);

window.Alpine = Alpine;

Alpine.start();
