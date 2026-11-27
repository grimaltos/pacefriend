<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: true,
});

function submit() {
    form.post('/login');
}
</script>

<template>
    <Head title="Iniciar Sesión" />
    <div class="min-h-screen bg-zinc-950 flex items-center justify-center px-4">
        <div class="w-full max-w-md bg-zinc-900 border border-zinc-800 rounded-2xl p-8 shadow-2xl">
            <div class="flex items-center gap-2.5 mb-6">
                <span class="w-2.5 h-7 rounded-sm bg-emerald-500"></span>
                <span class="font-bold tracking-tight text-xl text-white">PACE<span class="text-emerald-400">FRIEND</span></span>
            </div>

            <h1 class="text-2xl font-bold text-white mb-1">Acceso al Muro de Pista</h1>
            <p class="text-sm text-zinc-400 mb-6">Conecta tu telemetría en tiempo real y revisa tus Informes PRO.</p>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-zinc-300 mb-1">Email</label>
                    <input v-model="form.email" type="email" required autofocus class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    <p v-if="form.errors.email" class="text-xs text-rose-400 mt-1">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-300 mb-1">Contraseña</label>
                    <input v-model="form.password" type="password" required class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                </div>

                <button type="submit" :disabled="form.processing" class="w-full py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold text-sm transition cursor-pointer">
                    Entrar al Panel
                </button>
            </form>

            <p class="text-xs text-zinc-400 mt-6 text-center">
                ¿Nuevo piloto?
                <Link href="/register" class="text-emerald-400 hover:underline font-medium">Crea tu cuenta aquí</Link>
            </p>
        </div>
    </div>
</template>
