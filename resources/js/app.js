import './bootstrap';

import focus from '@alpinejs/focus';
import Alpine from 'alpinejs';

import crud from './components/crud';
import mapPicker from './components/map-picker';
import schedulingForm from './components/scheduling-form';
import startRoute from './components/start-route';
import bulkScheduling from './components/bulk-scheduling';
import schedulingChanges from './components/scheduling-changes';
import staffGroupForm from './components/staff-group-form';

Alpine.plugin(focus);

Alpine.data('crud', crud);
Alpine.data('mapPicker', mapPicker);
Alpine.data('schedulingForm', schedulingForm);
Alpine.data('startRoute', startRoute);
Alpine.data('bulkScheduling', bulkScheduling);
Alpine.data('schedulingChanges', schedulingChanges);
Alpine.data('staffGroupForm', staffGroupForm);

window.Alpine = Alpine;

Alpine.start();
