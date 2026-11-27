<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatLapTime } from '../../Utils/formatters';

defineProps({
    sessions: Array,
});
</script>

<template>
    <Head title="Sesiones de Pista" />
    <AppLayout>
        <div class="mb-8">
            <p class="text-xs uppercase tracking-widest text-emerald-400 font-semibold mb-1">Historial &amp; Debriefing</p>
            <h1 class="text-2xl sm:text-3xl font-bold text-white">Tus Sesiones e Informes PRO</h1>
            <p class="text-sm text-zinc-400 mt-1">Selecciona una tanda para revisar tus vueltas, guardar referencias o solicitar tu Informe PRO post-sesión.</p>
        </div>

        <div class="rounded-2xl bg-zinc-900 border border-zinc-800 overflow-hidden">
            <div v-if="!sessions.length" class="p-12 text-center text-sm text-zinc-400">
                Todavía no hay tandas registradas. Cuando cruces meta con <code class="text-zinc-200">pacefriend-client</code> aparecerán aquí en tiempo real.
            </div>

            <div v-else class="divide-y divide-zinc-800">
                <Link v-for="s in sessions" :key="s.id" :href="`/sessions/${s.id}`" class="p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 hover:bg-zinc-800/40 transition block">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <span class="font-bold text-white text-base">{{ s.track?.name }}</span>
                            <span :class="s.ended_at ? 'bg-zinc-800 text-zinc-300' : 'bg-emerald-500/20 text-emerald-300'" class="text-[11px] px-2 py-0.5 rounded-full">
                                {{ s.ended_at ? 'Finalizada' : 'En curso' }}
                            </span>
                        </div>
                        <div class="text-sm text-zinc-400 mt-1">{{ s.car_name }}</div>
                        <div class="text-xs text-zinc-500 mt-1">
                            Vueltas limpias: <strong class="text-zinc-300">{{ s.clean_laps_count }}/{{ s.laps_count }}</strong>
                            · Asfalto medio: <strong class="text-amber-300">{{ s.avg_track_temp_c }} °C</strong>
                            <span v-if="s.active_reference_lap"> · Ref activa: {{ s.active_reference_lap.label }} ({{ formatLapTime(s.active_reference_lap.lap_time_ms) }})</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-5">
                        <div class="text-right">
                            <div class="text-xs text-zinc-500">Mejor Vuelta</div>
                            <div class="font-mono text-lg font-bold text-emerald-400">{{ formatLapTime(s.best_lap_time_ms) }}</div>
                        </div>

                        <span v-if="s.report" class="px-3 py-1.5 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-xs font-semibold">
                            Informe PRO Disponible
                        </span>
                        <span v-else class="px-3 py-1.5 rounded-xl bg-zinc-800 text-zinc-300 text-xs font-medium">
                            Abrir / Generar PRO →
                        </span>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
