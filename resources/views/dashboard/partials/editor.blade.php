{{--
    Editor de programación del Dashboard: turno, vehículo y personal del día.
    Cada sección que cambia pide su motivo. Del personal solo se cambia a
    quien falta: el que ya marcó asistencia está trabajando.
    Estado en resources/js/components/daily-dashboard.js.
--}}
@php
    $current = 'flex h-9 items-center gap-2 rounded-md border border-stone-200 bg-stone-50 px-3 text-[13px] text-stone-700';
    $sectionTitle = 'flex items-center gap-2 text-[13px] font-semibold text-stone-900';
@endphp

<x-modal show="editing" close="closeEditor()" size="lg" title="Editor de programación"
    description-expr="editing ? editing.code + ' · Zona ' + editing.zone + ' · ' + editing.group : ''">
    <template x-if="editing && editor">
        <div>
            <x-modal.body class="max-h-[70vh] space-y-5 overflow-y-auto">
                <dl class="grid gap-3 rounded-lg bg-stone-50 px-4 py-3 text-[12px] sm:grid-cols-4">
                    <div>
                        <dt class="text-stone-500">Fecha</dt>
                        <dd class="font-medium text-stone-800">{{ $date->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Programación general</dt>
                        <dd class="font-mono font-medium text-stone-800" x-text="editing.general ?? '—'"></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Recorrido</dt>
                        <dd class="font-medium text-stone-800" x-text="editing.route"></dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Asistencia</dt>
                        <dd class="font-medium text-stone-800"
                            x-text="editing.editor.crew.filter((m) => m.present).length + ' de ' + editing.editor.crew.length + ' marcaron'"></dd>
                    </div>
                </dl>

                {{-- Turno --}}
                <section class="space-y-3">
                    <h3 class="{{ $sectionTitle }}">
                        <x-lucide-clock class="size-4 text-stone-500" />
                        Turno
                        <x-badge tone="amber" x-show="changed.turno" x-cloak>Cambia</x-badge>
                    </h3>
                    <div class="grid items-end gap-3 sm:grid-cols-[1fr_auto_1fr]">
                        <x-field label="Actual">
                            <p class="{{ $current }}" x-text="editing.editor.schedule"></p>
                        </x-field>
                        <x-lucide-arrow-right class="mb-2.5 hidden size-4 text-stone-400 sm:block" />
                        <x-field label="Nuevo turno" for="editor_schedule">
                            <select id="editor_schedule" x-model="editor.schedule_id" class="field">
                                @foreach ($schedules as $schedule)
                                    <option value="{{ $schedule->id }}">{{ $schedule->label }}</option>
                                @endforeach
                            </select>
                        </x-field>
                    </div>
                    @include('dashboard.partials.reason', ['section' => 'turno'])
                </section>

                {{-- Vehículo --}}
                <section class="space-y-3 border-t border-stone-200 pt-5">
                    <div>
                        <h3 class="{{ $sectionTitle }}">
                            <x-lucide-truck class="size-4 text-stone-500" />
                            Vehículo
                            <x-badge tone="amber" x-show="changed.vehiculo" x-cloak>Cambia</x-badge>
                        </h3>
                        <p class="mt-0.5 text-[12px] text-stone-500">Se ofrecen los operativos que están libres en el turno elegido.</p>
                    </div>
                    <div class="grid items-end gap-3 sm:grid-cols-[1fr_auto_1fr]">
                        <x-field label="Actual">
                            <p class="{{ $current }} truncate" x-text="editing.editor.vehicle" :title="editing.editor.vehicle"></p>
                        </x-field>
                        <x-lucide-arrow-right class="mb-2.5 hidden size-4 text-stone-400 sm:block" />
                        <x-field label="Nuevo vehículo" for="editor_vehicle">
                            <select id="editor_vehicle" x-model="editor.vehicle_id" class="field">
                                <template x-for="vehicle in vehicleOptions" :key="vehicle.id">
                                    <option :value="String(vehicle.id)" x-text="vehicle.label" :selected="String(vehicle.id) === editor.vehicle_id"></option>
                                </template>
                            </select>
                        </x-field>
                    </div>
                    @include('dashboard.partials.reason', ['section' => 'vehiculo'])
                </section>

                {{-- Personal: solo el que falta --}}
                <section class="space-y-3 border-t border-stone-200 pt-5">
                    <div>
                        <h3 class="{{ $sectionTitle }}">
                            <x-lucide-users class="size-4 text-stone-500" />
                            Personal
                            <x-badge tone="amber" x-show="changed.personal" x-cloak>Cambia</x-badge>
                        </h3>
                        <p class="mt-0.5 text-[12px] text-stone-500">
                            Solo se cambia al personal que no marcó asistencia, por alguien libre en el turno elegido.
                        </p>
                    </div>

                    <ul class="space-y-1.5">
                        <template x-for="member in editing.editor.crew" :key="member.id">
                            <li class="grid items-center gap-3 rounded-lg bg-stone-50 px-3 py-2.5 sm:grid-cols-[1fr_17rem]">
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-white text-[11px] font-semibold text-stone-600 ring-1 ring-stone-200"
                                        x-text="initials(member.full_name)"></span>
                                    <div class="min-w-0">
                                        <p class="truncate text-[13px] font-medium text-stone-900" x-text="member.full_name"></p>
                                        <p class="mt-0.5 flex items-center gap-1.5 text-[11px] text-stone-500">
                                            <span class="capitalize" x-text="member.role"></span>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 font-medium ring-1 ring-inset"
                                                :class="badgeClass(member.mark[1])" x-text="member.mark[0] + (member.check_in ? ' ' + member.check_in : '')"></span>
                                        </p>
                                    </div>
                                </div>

                                <p x-show="member.present" class="flex items-center gap-1.5 text-[12px] text-stone-500">
                                    <x-lucide-lock class="size-3.5" />
                                    Ya marcó asistencia: no se cambia
                                </p>
                                <select x-show="!member.present" x-model="editor.people[member.id]" class="field bg-white"
                                    :aria-label="'Nuevo personal en lugar de ' + member.full_name">
                                    <option value="" x-text="personOptions(member).length ? 'Mantener' : 'Nadie libre en el turno'"></option>
                                    <template x-for="person in personOptions(member)" :key="person.id">
                                        <option :value="String(person.id)" x-text="person.full_name + markLabel(person)"></option>
                                    </template>
                                </select>
                            </li>
                        </template>
                    </ul>
                    <p x-show="!editing.editor.crew.length" class="rounded-lg bg-stone-50 px-3 py-4 text-center text-[12px] text-stone-500">
                        La programación no tiene personal asignado.
                    </p>
                    @include('dashboard.partials.reason', ['section' => 'personal'])
                </section>

                <p x-show="error" x-text="error" class="rounded-md bg-red-50 px-3 py-2 text-[12px] font-medium text-red-700" role="alert"></p>

                <p class="text-[11px] text-stone-400">
                    Los cambios se aplican solo a este día y a su recorrido. La programación pasa a Reprogramada y cada cambio queda en el historial.
                </p>
            </x-modal.body>
            <x-modal.footer>
                <button type="button" class="btn btn-secondary" @click="closeEditor()">Cancelar</button>
                <button type="button" class="btn btn-primary" :disabled="!hasChanges || busy" @click="submitEditor()">
                    <x-lucide-loader-circle class="size-3.5 animate-spin" x-show="busy" x-cloak />
                    <x-lucide-save class="size-3.5" x-show="!busy" />
                    Guardar cambios
                </button>
            </x-modal.footer>
        </div>
    </template>
</x-modal>
