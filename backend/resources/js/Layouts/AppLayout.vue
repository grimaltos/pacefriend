<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const flash = computed(() => page.props.flash || {});

const clientOnline = computed(() => {
    if (!user.value?.last_client_seen_at) return false;
    const diffMin = (Date.now() - new Date(user.value.last_client_seen_at).getTime()) / 60000;
    return diffMin <= 10;
});

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-screen bg-zinc-950 text-zinc-100 flex flex-col">
        <header class="border-b border-zinc-800/80 bg-zinc-900/70 backdrop-blur sticky top-0 z-30">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
                <div class="flex items-center gap-8">
                    <Link href="/dashboard" class="flex items-center gap-2.5">
                        <span class="w-2.5 h-7 rounded-sm bg-emerald-500 inline-block"></span>
                        <span class="font-bold tracking-tight text-lg text-white">PACE<span class="text-emerald-400">FRIEND</span></span>
                    </Link>

                    <nav class="hidden md:flex items-center gap-1 text-sm font-medium">
                        <Link href="/dashboard" class="px-3 py-1.5 rounded-lg text-zinc-300 hover:text-white hover:bg-zinc-800/70 transition">
                            Panel
                        </Link>
                        <Link href="/sessions" class="px-3 py-1.5 rounded-lg text-zinc-300 hover:text-white hover:bg-zinc-800/70 transition">
                            Sesiones &amp; Informe PRO
                        </Link>
                        <Link href="/references" class="px-3 py-1.5 rounded-lg text-zinc-300 hover:text-white hover:bg-zinc-800/70 transition">
                            Vueltas Referencia
                        </Link>
                        <Link href="/tracks" class="px-3 py-1.5 rounded-lg text-zinc-300 hover:text-white hover:bg-zinc-800/70 transition">
                            Circuitos
                        </Link>
                        <Link href="/settings" class="px-3 py-1.5 rounded-lg text-zinc-300 hover:text-white hover:bg-zinc-800/70 transition">
                            Piloto &amp; Cliente Go
                        </Link>
                    </nav>
                </div>

                <div class="flex items-center gap-4 text-sm">
                    <div class="hidden sm:flex items-center gap-2 px-3 py-1 rounded-full border border-zinc-800 bg-zinc-900 text-xs">
                        <span :class="clientOnline ? 'bg-emerald-400 animate-pulse' : 'bg-zinc-600'" class="w-2 h-2 rounded-full"></span>
                        <span class="text-zinc-300">{{ clientOnline ? 'Cliente en Pista' : 'Cliente desconectado' }}</span>
                    </div>

                    <div v-if="user" class="text-right hidden sm:block">
                        <div class="text-xs text-zinc-400">Radio: <span class="text-emerald-400 font-semibold">{{ user.effective_callsign }}</span></div>
                        <div class="text-xs text-zinc-500">{{ user.email }}</div>
                    </div>

                    <button @click="logout" class="px-3 py-1.5 rounded-lg border border-zinc-800 text-zinc-400 hover:text-white hover:border-zinc-700 text-xs cursor-pointer">
                        Salir
                    </button>
                </div>
            </div>
        </header>

        <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1">
            <div v-if="flash.success" class="mb-6 rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="mb-6 rounded-xl border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
                {{ flash.error }}
            </div>

            <slot />
        </main>
    </div>
</template>
