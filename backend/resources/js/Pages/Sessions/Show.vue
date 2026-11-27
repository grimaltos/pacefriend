<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDeltaMs, formatLapTime, renderMarkdown } from '../../Utils/formatters';

const props = defineProps({
    session: Object,
});

const generating = ref(false);

function generateProReport() {
    generating.value = true;
    router.post(`/sessions/${props.session.id}/report`, {}, {
        onFinish: () => { generating.value = false; },
    });
}

function finishSession() {
    router.post(`/sessions/${props.session.id}/finish`);
}

function promoteLap(lapId) {
    router.post(`/laps/${lapId}/promote`);
}
</script>

<template>
    <Head :title="`Sesión · ${session.track?.name}`" />
    <AppLayout>
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-8">
            <div>
                <div class="flex items-center gap-2 text-xs text-emerald-400 font-semibold uppercase tracking-wider mb-1">
                    <span>{{ session.track?.name }}</span>
                    <span>·</span>
                    <span>{{ session.ended_at ? 'Sesión Finalizada' : 'Sesión Abierta' }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white">{{ session.car_name }}</h1>
                <p class="text-sm text-zinc-400 mt-1">
                    Asfalto medio: <strong class="text-amber-300">{{ session.avg_track_temp_c }} °C</strong> (Aire {{ session.avg_air_temp_c }} °C)
                    <span v-if="session.active_reference_lap">
                        · Comparada contra referencia óptima a <strong class="text-emerald-300">{{ session.active_reference_lap.track_temp_c }} °C</strong> ({{ formatLapTime(session.active_reference_lap.lap_time_ms) }})
                    </span>
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <button v-if="!session.ended_at" @click="finishSession" class="px-4 py-2.5 rounded-xl bg-zinc-900 border border-zinc-700 hover:border-zinc-500 text-sm text-zinc-200 cursor-pointer">
                    Finalizar Tanda
                </button>
                <button @click="generateProReport" :disabled="generating" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold text-sm transition cursor-pointer shadow-lg shadow-emerald-500/10">
                    {{ generating ? 'Analizando telemetría...' : (session.report ? 'Actualizar Informe PRO' : 'Generar Informe PRO de Sesión') }}
                </button>
            </div>
        </div>

        <!-- Active Real-Time Radio Coaching Plan -->
        <div v-if="session.active_coaching_plan" class="mb-8 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
                <div>
                    <span class="text-xs uppercase tracking-wider text-emerald-400 font-semibold">Última Orden Activa por Radio</span>
                    <h2 class="text-lg font-bold text-white">Foco en {{ session.active_coaching_plan.focus_turn_name }} (Curva {{ session.active_coaching_plan.focus_turn }})</h2>
                </div>
                <span class="text-xs px-3 py-1 rounded-full bg-rose-500/15 text-rose-300 border border-rose-500/30">
                    Pérdida media detectada: +{{ (session.active_coaching_plan.avg_time_loss_ms / 1000).toFixed(2) }}s / vuelta
                </span>
            </div>

            <div class="grid md:grid-cols-2 gap-4">
                <div v-for="trg in session.active_coaching_plan.triggers" :key="trg.id" class="rounded-xl bg-zinc-900/90 border border-zinc-800 p-4">
                    <div class="flex items-center justify-between text-xs text-zinc-400 mb-1">
                        <span class="uppercase font-semibold text-emerald-400">{{ trg.phase === 'pre_brake' ? 'Anticipación Pre-Frenada' : 'Feedback Salida de Curva' }}</span>
                        <span class="font-mono">Trigger @ {{ (trg.trigger_pct * 100).toFixed(1) }}% pista</span>
                    </div>
                    <p class="text-sm text-zinc-100 italic">“{{ trg.text }}”</p>
                </div>
            </div>
        </div>

        <!-- PRO Post-Session Report Section -->
        <div v-if="session.report" class="mb-8 rounded-2xl bg-zinc-900 border border-emerald-500/40 overflow-hidden shadow-2xl">
            <div class="px-6 py-4 bg-zinc-950/70 border-b border-zinc-800 flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="px-2.5 py-0.5 rounded bg-emerald-500 text-zinc-950 text-xs font-extrabold">PRO</span>
                    <h2 class="font-bold text-white">Informe Detallado de Ingeniería Post-Sesión</h2>
                </div>
                <span class="text-xs text-zinc-400">Motor: {{ session.report.llm_driver }}</span>
            </div>

            <div class="p-6 grid sm:grid-cols-3 gap-4 border-b border-zinc-800 bg-zinc-950/30">
                <div class="rounded-xl bg-zinc-900 border border-zinc-800 p-4">
                    <div class="text-xs text-zinc-400">Evolución de Ritmo en Tanda</div>
                    <div class="text-xl font-bold mt-1" :class="(session.report.quantitative_summary?.pace_gain_ms || 0) >= 0 ? 'text-emerald-400' : 'text-amber-400'">
                        {{ formatDeltaMs(-(session.report.quantitative_summary?.pace_gain_ms || 0)) }}
                    </div>
                    <div class="text-[11px] text-zinc-500 mt-0.5">Comparativa inicio vs final de sesión</div>
                </div>
                <div class="rounded-xl bg-zinc-900 border border-zinc-800 p-4">
                    <div class="text-xs text-zinc-400">Consistencia Final (σ)</div>
                    <div class="text-xl font-bold text-white mt-1">
                        ±{{ ((session.report.quantitative_summary?.consistency_late_ms || 0) / 1000).toFixed(2) }}s
                    </div>
                    <div class="text-[11px] text-zinc-500 mt-0.5">Inicial: ±{{ ((session.report.quantitative_summary?.consistency_early_ms || 0) / 1000).toFixed(2) }}s</div>
                </div>
                <div class="rounded-xl bg-zinc-900 border border-zinc-800 p-4">
                    <div class="text-xs text-zinc-400">Mejor Limpia vs Referencia ({{ session.report.quantitative_summary?.reference_track_temp_c || session.avg_track_temp_c }} °C)</div>
                    <div class="text-xl font-bold text-emerald-400 font-mono mt-1">
                        {{ formatLapTime(session.report.quantitative_summary?.best_lap_ms) }}
                    </div>
                    <div class="text-[11px] text-zinc-500 mt-0.5">Ref: {{ formatLapTime(session.report.quantitative_summary?.reference_lap_ms) }}</div>
                </div>
            </div>

            <div class="p-6 sm:p-8 text-sm leading-relaxed text-zinc-300" v-html="renderMarkdown(session.report.narrative_markdown)"></div>
        </div>

        <!-- Laps History Table -->
        <div class="rounded-2xl bg-zinc-900 border border-zinc-800 overflow-hidden">
            <div class="px-6 py-4 border-b border-zinc-800 flex items-center justify-between">
                <h2 class="font-semibold text-white">Registro de Vueltas ({{ session.laps?.length || 0 }})</h2>
                <span class="text-xs text-zinc-400">Puedes convertir cualquier vuelta limpia en nueva referencia para estas condiciones</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase text-zinc-400 bg-zinc-950/60 border-b border-zinc-800">
                        <tr>
                            <th class="px-6 py-3">Vuelta</th>
                            <th class="px-6 py-3">Tiempo</th>
                            <th class="px-6 py-3">Estado</th>
                            <th class="px-6 py-3">Asfalto</th>
                            <th class="px-6 py-3 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/70">
                        <tr v-for="lap in session.laps" :key="lap.id" class="hover:bg-zinc-800/30">
                            <td class="px-6 py-3.5 font-mono text-zinc-300">#{{ lap.lap_number }}</td>
                            <td class="px-6 py-3.5 font-mono font-semibold" :class="lap.lap_time_ms === session.best_lap_time_ms && lap.is_clean ? 'text-emerald-400' : 'text-white'">
                                {{ formatLapTime(lap.lap_time_ms) }}
                            </td>
                            <td class="px-6 py-3.5">
                                <span v-if="lap.is_clean" class="px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300 text-xs">Limpia</span>
                                <span v-else class="px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-300 text-xs">Off-track / Sucia</span>
                            </td>
                            <td class="px-6 py-3.5 text-zinc-400">{{ lap.track_temp_c }} °C</td>
                            <td class="px-6 py-3.5 text-right">
                                <button v-if="lap.is_clean" @click="promoteLap(lap.id)" class="text-xs text-emerald-400 hover:text-emerald-300 font-medium cursor-pointer">
                                    + Guardar como Referencia
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
