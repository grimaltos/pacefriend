<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    driver_callsign: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/register');
}
</script>

<template>
    <Head title="Registro de Piloto" />
    <div class="min-h-screen bg-zinc-950 flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-md bg-zinc-900 border border-zinc-800 rounded-2xl p-8 shadow-2xl">
            <div class="flex items-center gap-2.5 mb-6">
                <span class="w-2.5 h-7 rounded-sm bg-emerald-500"></span>
                <span class="font-bold tracking-tight text-xl text-white">PACE<span class="text-emerald-400">FRIEND</span></span>
            </div>

            <h1 class="text-2xl font-bold text-white mb-1">Alta de Piloto</h1>
            <p class="text-sm text-zinc-400 mb-6">Configura tu cuenta y cómo te llamará tu ingeniero de pista por radio.</p>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-zinc-300 mb-1">Nombre completo</label>
                    <input v-model="form.name" type="text" required placeholder="Ej. Carlos Grimaltos" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    <p v-if="form.errors.name" class="text-xs text-rose-400 mt-1">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-300 mb-1">Nombre por radio (Callsign del Coach)</label>
                    <input v-model="form.driver_callsign" type="text" placeholder="Ej. Carlos (opcional)" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    <p class="text-[11px] text-zinc-500 mt-1">Tu ingeniero usará este nombre al anticiparte las frenadas clave.</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-300 mb-1">Email</label>
                    <input v-model="form.email" type="email" required class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    <p v-if="form.errors.email" class="text-xs text-rose-400 mt-1">{{ form.errors.email }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Contraseña</label>
                        <input v-model="form.password" type="password" required class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Confirmar</label>
                        <input v-model="form.password_confirmation" type="password" required class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    </div>
                </div>
                <p v-if="form.errors.password" class="text-xs text-rose-400">{{ form.errors.password }}</p>

                <button type="submit" :disabled="form.processing" class="w-full py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold text-sm transition cursor-pointer">
                    Crear Cuenta de Piloto
                </button>
            </form>

            <p class="text-xs text-zinc-400 mt-6 text-center">
                ¿Ya tienes cuenta?
                <Link href="/login" class="text-emerald-400 hover:underline font-medium">Inicia sesión</Link>
            </p>
        </div>
    </div>
</template>
