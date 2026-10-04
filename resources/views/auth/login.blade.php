{{-- W01 Inicio de sesión. --}}
<x-layouts.guest title="Iniciar sesión">
    <div class="flex min-h-screen items-center justify-center bg-cream-100 px-4 py-10" x-data="{ forgot: false }">
        <div class="w-full max-w-sm">
            <div class="mb-6 text-center">
                <x-garbage-truck class="mx-auto h-24 w-48" />
                <h1 class="mt-3 text-lg font-bold text-stone-900">Gestión de Residuos Sólidos</h1>
                <p class="mt-1 text-[12px] text-stone-500">Área de RSU · USAT</p>
            </div>

            <form method="POST" action="{{ route('login') }}" x-data="{ sending: false }" @submit="sending = true"
                class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                @csrf

                <div class="space-y-4">
                    <x-field label="Usuario" for="username" hint="Tu correo institucional o tu DNI" required>
                        <input id="username" name="username" value="{{ old('username') }}" autocomplete="username"
                            autofocus required @class(['field', 'field-error' => $errors->has('username')])>
                    </x-field>

                    {{-- El error de credenciales va bajo la contraseña, como en la maqueta. --}}
                    <x-field label="Contraseña" name="username" for="password" required>
                        <input id="password" name="password" type="password" autocomplete="current-password" required
                            @class(['field', 'field-error' => $errors->has('username')])>
                    </x-field>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <label class="flex cursor-pointer items-center gap-2 text-[12px] text-stone-600 select-none">
                        <input type="checkbox" name="remember" value="1" class="size-4 shrink-0 accent-brand-600"
                            @checked(old('remember'))>
                        Mantener sesión activa
                    </label>
                    <button type="button" @click="forgot = true"
                        class="cursor-pointer text-[12px] font-medium text-brand-500 hover:text-brand-600 hover:underline">
                        ¿Olvidaste tu contraseña?
                    </button>
                </div>

                <button type="submit" class="btn btn-primary mt-6 w-full" :disabled="sending">
                    <x-lucide-loader-circle class="size-4 animate-spin" x-show="sending" x-cloak />
                    Ingresar
                </button>
            </form>

            <p class="mt-4 text-center text-[11px] text-stone-400">
                Sistema informático del proyecto de reciclaje · Ingeniería de Sistemas y Computación
            </p>
        </div>

        {{-- Las cuentas las gestiona el área desde Personal: no hay autoservicio de recuperación. --}}
        <x-modal show="forgot" title="¿Olvidaste tu contraseña?" size="sm">
            <x-modal.body class="space-y-2 text-[13px] text-stone-600">
                <p>
                    Comunícate con el <strong class="text-stone-800">administrador del Área de RSU</strong>
                    para que restablezca tu contraseña desde el módulo de Personal.
                </p>
                <p>Ten a la mano tu DNI o tu correo institucional para que pueda identificarte.</p>
            </x-modal.body>
            <x-modal.footer>
                <button type="button" class="btn btn-primary" @click="forgot = false">Entendido</button>
            </x-modal.footer>
        </x-modal>
    </div>
</x-layouts.guest>
