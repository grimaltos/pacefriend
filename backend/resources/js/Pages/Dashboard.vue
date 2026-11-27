<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { formatLapTime } from '../Utils/formatters';

defineProps({
    stats: Object,
    recentSessions: Array,
});

const user = computed(() => usePage().props.auth?.user);
</script>

<template>
    <Head title="Panel de Piloto" />
    <AppLayout>
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-8">
            <div>
                <p class="text-xs uppercase tracking-widest text-emerald-400 font-semibold mb-1">Centro de Telemetría</p>
                <h1 class="text-2xl sm:text-3xl font-bold text-white">Hola, {{ user?.effective_callsign }}</h1>
                <p class="text-sm text-zinc-400 mt-1">Tu ingeniero selecciona automáticamente la referencia con la temperatura de asfalto más cercana.</p>
            </div>
            <div class="flex gap-3">
                <Link href="/references" class="px-4 py-2 rounded-xl bg-zinc-900 border border-zinc-800 hover:border-zinc-700 text-sm font-medium text-zinc-200 transition">
                    + Subir Referencia (.ibt)
                </Link>
                <Link href="/settings" class="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 text-sm font-semibold transition">
                    Vincular Cliente Go
                </Link>
            </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-5">
                <div class="text-xs text-zinc-400">Sesiones Registradas</div>
                <div class="text-3xl font-bold text-white mt-1">{{ stats.total_sessions }}</div>
            </div>
            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-5">
                <div class="text-xs text-zinc-400">Vueltas Limpias Analizadas</div>
                <div class="text-3xl font-bold text-emerald-400 mt-1">{{ stats.clean_laps }}</div>
            </div>
            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-5">
                <div class="text-xs text-zinc-400">Referencias (Propias / Base)</div>
                <div class="text-3xl font-bold text-white mt-1">{{ stats.my_references }} <span class="text-base font-normal text-zinc-500">/ {{ stats.global_references }}</span></div>
            </div>
            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-5">
                <div class="text-xs text-zinc-400">Tokens Cliente Activos</div>
                <div class="text-3xl font-bold text-white mt-1">{{ stats.tokens_count }}</div>
            </div>
        </div>

        <div class="rounded-2xl bg-zinc-900 border border-zinc-800 overflow-hidden">
            <div class="px-6 py-4 border-b border-zinc-800 flex items-center justify-between">
                <h2 class="font-semibold text-white">Últimas Sesiones en Pista</h2>
                <Link href="/sessions" class="text-xs text-emerald-400 hover:underline">Ver todas →</Link>
            </div>

            <div v-if="!recentSessions.length" class="p-10 text-center text-sm text-zinc-400">
                Aún no hay sesiones registradas. Genera tu token en <Link href="/settings" class="text-emerald-400 underline">Piloto &amp; Cliente Go</Link> y lanza <code class="text-zinc-200">pacefriend-client</code>.
            </div>

            <div v-else class="divide-y divide-zinc-800/80">
                <Link v-for="s in recentSessions" :key="s.id" :href="`/sessions/${s.id}`" class="px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-zinc-800/40 transition block">
                    <div>
                        <div class="font-semibold text-white">{{ s.track?.name }} <span class="text-zinc-500 font-normal">· {{ s.car_name }}</span></div>
                        <div class="text-xs text-zinc-400 mt-0.5">
                            {{ s.clean_laps_count }}/{{ s.laps_count }} vueltas limpias · Asfalto {{ s.avg_track_temp_c }} °C
                            <span v-if="s.active_reference_lap" class="text-zinc-500"> · Ref: {{ s.active_reference_lap.track_temp_c }} °C</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <div class="text-xs text-zinc-500">Mejor limpia</div>
                            <div class="font-mono text-sm font-semibold text-emerald-400">{{ formatLapTime(s.best_lap_time_ms) }}</div>
                            <div v-if="s.optimal_lap_time_ms" class="font-mono text-[11px] text-violet-300">
                                Óptima: {{ formatLapTime(s.optimal_lap_time_ms) }}
                            </div>
                        </div>
                        <span v-if="s.report" class="px-2.5 py-1 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-xs font-medium">
                            Informe PRO Listo
                        </span>
                        <span v-else class="px-2.5 py-1 rounded-full bg-zinc-800 text-zinc-400 text-xs">
                            Ver sesión →
                        </span>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
