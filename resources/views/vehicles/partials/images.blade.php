{{--
    Imágenes del vehículo (W05): subir, marcar la de perfil y eliminar.

    - Con el vehículo ya registrado, cada acción es un formulario propio que
      va directo al servidor.
    - Durante el alta las fotos se eligen en el navegador y viajan junto con
      la ficha: el <input type="file"> pertenece al formulario principal
      (atributo form="vehicle-form") aunque esté fuera de él.
--}}
@php
    $tileClass = 'group relative aspect-4/3 overflow-hidden rounded-lg border bg-stone-50';
    $actionClass = 'cursor-pointer rounded bg-white/90 p-1.5 transition hover:bg-white';
    $overlayClass = 'absolute inset-x-0 bottom-0 flex justify-end gap-1 bg-linear-to-t from-stone-900/70 to-transparent p-1.5 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100';
    $addClass = 'grid aspect-4/3 cursor-pointer place-items-center rounded-lg border-2 border-dashed border-stone-300 text-stone-400 transition hover:border-brand-400 hover:text-brand-600 disabled:opacity-50';
@endphp

<x-card>
    <x-card-header title="Imágenes" :description="$vehicle
        ? 'La imagen marcada con la estrella es la de perfil.'
        : 'Se suben al registrar el vehículo. La de la estrella será la de perfil.'" />

    <div class="p-5">
        @if ($vehicle)
            <form method="POST" action="{{ route('vehicles.images.store', $vehicle) }}" enctype="multipart/form-data"
                x-data="{ sending: false }" x-ref="upload">
                @csrf
                <div class="grid grid-cols-2 gap-3">
                    @foreach ($vehicle->images as $image)
                        <figure @class([$tileClass, 'border-brand-500 ring-2 ring-brand-500/30' => $image->profile, 'border-stone-200' => ! $image->profile])>
                            <img src="{{ $image->url }}" alt="" class="size-full object-cover" loading="lazy">

                            <div class="{{ $overlayClass }}">
                                <button type="submit" form="image-profile-{{ $image->id }}" class="{{ $actionClass }} text-stone-700"
                                    title="Usar como imagen de perfil" aria-label="Usar como imagen de perfil">
                                    <x-lucide-star @class(['size-3.5', 'fill-amber-400 text-amber-500' => $image->profile]) />
                                </button>
                                <button type="submit" form="image-delete-{{ $image->id }}" class="{{ $actionClass }} text-red-600"
                                    title="Eliminar imagen" aria-label="Eliminar imagen">
                                    <x-lucide-trash-2 class="size-3.5" />
                                </button>
                            </div>

                            @if ($image->profile)
                                <span class="absolute top-1.5 left-1.5 rounded bg-brand-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">Perfil</span>
                            @endif
                        </figure>
                    @endforeach

                    <button type="button" class="{{ $addClass }}" :disabled="sending" @click="$refs.files.click()">
                        <span class="text-center">
                            <x-lucide-image-plus class="mx-auto size-5" />
                            <span class="mt-1 block text-[11px] font-medium" x-text="sending ? 'Subiendo…' : 'Agregar'">Agregar</span>
                        </span>
                    </button>
                </div>

                {{-- Elegir las fotos ya las sube: no hace falta un segundo clic. --}}
                <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" class="hidden"
                    x-ref="files" @change="if ($event.target.files.length) { sending = true; $refs.upload.submit() }">
            </form>

            {{-- Fuera del formulario de subida: un <form> no puede ir dentro de otro. --}}
            @foreach ($vehicle->images as $image)
                <form id="image-profile-{{ $image->id }}" method="POST" class="hidden"
                    action="{{ route('vehicles.images.profile', [$vehicle, $image]) }}">
                    @csrf
                    @method('PUT')
                </form>
                <form id="image-delete-{{ $image->id }}" method="POST" class="hidden"
                    action="{{ route('vehicles.images.destroy', [$vehicle, $image]) }}">
                    @csrf
                    @method('DELETE')
                </form>
            @endforeach

            @php
                $imageError = collect($errors->getMessages())
                    ->first(fn ($messages, $key) => str_starts_with($key, 'images'));
            @endphp
            @if ($imageError)
                <p class="mt-3 text-[11px] font-medium text-red-600">{{ $imageError[0] }}</p>
            @endif
        @else
            <div x-data="{
                drafts: [],
                // Contador para las claves: crypto.randomUUID() no existe fuera de
                // https/localhost, y rsu-back.test se sirve por http.
                nextKey: 1,
                error: null,
                // Mismos límites que valida el servidor.
                types: ['image/jpeg', 'image/png', 'image/webp'],
                maxBytes: 4 * 1024 * 1024,
                maxDrafts: 10,
                add(files) {
                    const problems = []
                    let valid = []
                    for (const file of files) {
                        if (!this.types.includes(file.type)) problems.push(file.name + ': solo JPG, PNG o WEBP.')
                        else if (file.size > this.maxBytes) problems.push(file.name + ': pesa más de 4 MB.')
                        else valid.push(file)
                    }
                    const room = this.maxDrafts - this.drafts.length
                    if (valid.length > room) {
                        problems.push('Puedes agregar como máximo ' + this.maxDrafts + ' imágenes.')
                        valid = valid.slice(0, room)
                    }
                    this.error = problems[0] ?? null
                    const hasProfile = this.drafts.some((draft) => draft.profile)
                    valid.forEach((file, index) => this.drafts.push({
                        key: this.nextKey++,
                        file,
                        url: URL.createObjectURL(file),
                        profile: !hasProfile && index === 0,
                    }))
                    this.sync()
                },
                remove(key) {
                    const target = this.drafts.find((draft) => draft.key === key)
                    URL.revokeObjectURL(target.url)
                    this.drafts = this.drafts.filter((draft) => draft.key !== key)
                    // Igual que en el servidor: si se va la de perfil, la siguiente toma su lugar.
                    if (target.profile && this.drafts.length) this.drafts[0].profile = true
                    this.sync()
                },
                setProfile(key) {
                    this.drafts.forEach((draft) => draft.profile = draft.key === key)
                },
                get profileIndex() {
                    return Math.max(this.drafts.findIndex((draft) => draft.profile), 0)
                },
                // El input es lo que se envía: se reconstruye con la lista que se ve.
                sync() {
                    const transfer = new DataTransfer()
                    this.drafts.forEach((draft) => transfer.items.add(draft.file))
                    this.$refs.files.files = transfer.files
                },
            }">
                <div class="grid grid-cols-2 gap-3">
                    <template x-for="draft in drafts" :key="draft.key">
                        <figure class="{{ $tileClass }}"
                            :class="draft.profile ? 'border-brand-500 ring-2 ring-brand-500/30' : 'border-stone-200'">
                            <img :src="draft.url" alt="" class="size-full object-cover">
                            <div class="{{ $overlayClass }}">
                                <button type="button" class="{{ $actionClass }} text-stone-700" title="Usar como imagen de perfil"
                                    aria-label="Usar como imagen de perfil" @click="setProfile(draft.key)">
                                    <x-lucide-star class="size-3.5" ::class="draft.profile && 'fill-amber-400 text-amber-500'" />
                                </button>
                                <button type="button" class="{{ $actionClass }} text-red-600" title="Eliminar imagen"
                                    aria-label="Eliminar imagen" @click="remove(draft.key)">
                                    <x-lucide-trash-2 class="size-3.5" />
                                </button>
                            </div>
                            <span x-show="draft.profile"
                                class="absolute top-1.5 left-1.5 rounded bg-brand-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">Perfil</span>
                        </figure>
                    </template>

                    <button type="button" class="{{ $addClass }}" @click="$refs.picker.click()">
                        <span class="text-center">
                            <x-lucide-image-plus class="mx-auto size-5" />
                            <span class="mt-1 block text-[11px] font-medium">Agregar</span>
                        </span>
                    </button>
                </div>

                {{-- El selector solo elige; lo que se envía es el input de abajo, ya sincronizado. --}}
                <input type="file" multiple accept="image/jpeg,image/png,image/webp" class="hidden" x-ref="picker"
                    @change="add(Array.from($event.target.files)); $event.target.value = ''">
                <input type="file" name="images[]" multiple class="hidden" x-ref="files" form="vehicle-form">
                <input type="hidden" name="profile_image" :value="profileIndex" form="vehicle-form">

                <p x-show="error" x-text="error" x-cloak class="mt-3 text-[11px] font-medium text-red-600"></p>
                @if ($errors->any())
                    {{-- El navegador no conserva archivos elegidos al volver con errores. --}}
                    <p class="mt-3 text-[11px] font-medium text-amber-700">
                        Si habías elegido fotos, vuelve a agregarlas antes de registrar.
                    </p>
                @endif
            </div>
        @endif

        <p class="mt-3 text-[11px] text-stone-500">JPG, PNG o WEBP · máximo 4 MB cada una.</p>
    </div>
</x-card>
