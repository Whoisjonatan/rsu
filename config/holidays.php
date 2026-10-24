<?php

return [

    /**
     * Calendario público de feriados del Perú (Google Calendar, en iCalendar).
     * Lo mantiene Google: cada año trae los feriados nuevos sin tocar el
     * código. Se puede cambiar por cualquier otro .ics con el mismo formato.
     */
    'feed_url' => env('HOLIDAYS_FEED_URL')
        ?: 'https://calendar.google.com/calendar/ical/es.pe%23holiday%40group.v.calendar.google.com/public/basic.ics',

    /** Segundos antes de rendirse y usar la lista interna. Mínimo 1: con 0, Http::timeout() no tiene límite. */
    'timeout' => max(1, (int) env('HOLIDAYS_FEED_TIMEOUT', 8)),

    /** Horas que se guarda la respuesta: el calendario casi no cambia. */
    'cache_hours' => (int) env('HOLIDAYS_FEED_CACHE_HOURS', 12),

];
