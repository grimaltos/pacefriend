<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDeltaMs, formatLapTime, renderMarkdown } from '../../Utils/formatters';

const props = defineProps({
    session: Object,
    focusTrajectoryMap: Object,
});

const generating = ref(false);

const activeTriggers = computed(() => props.session.active_coaching_plan?.triggers || []);
const breakdown = computed(() => props.session.active_coaching_plan?.telemetry_breakdown || null);

const issueLabelMap = {
    overdriving_entry: 'Causa raíz: Frenada tardía → Vértice perdido',
    narrow_entry: 'Causa raíz: Entrada cerrada (falta abrirse al exterior)',
    early_apex: 'Causa raíz: Giro prematuro (Early Apex → ahoga salida)',
    early_braking: 'Causa raíz: Frenada conservadora temprana',
    throttle_understeer: 'Causa raíz: Gas precipitado → Subviraje de salida',
    understeer_scrub: 'Causa raíz: Exceso de volante (arrastre delantero)',
    missed_apex: 'Causa raíz: Vértice abierto (lejos del piano interior)',
    overslow_apex: 'Causa raíz: Exceso de freno residual en el vértice',
    pinched_exit: 'Causa raíz: Salida ahogada (falta abrir dirección)',
    late_throttle: 'Causa raíz: Apertura tardía de acelerador',
    minimum_speed: 'Causa raíz: Velocidad de paso por curva',
};

function pointsToPolyline(pts) {
    if (!pts?.length) return '';
    return pts.map(([x, y]) => `${x},${y}`).join(' ');
}

function isBestSector(sectorNum, timeMs, isClean) {
    if (!isClean || !props.session.best_sectors_ms) return false;
    const best = props.session.best_sectors_ms[String(sectorNum)];
    return best && Math.abs(timeMs - best) <= 5;
}

function formatSectorSeconds(ms) {
    if (!ms || ms <= 0) return '—';
    const sec = ms / 1000;
    if (sec >= 60) {
        return formatLapTime(ms);
    }
    return sec.toFixed(2) + 's';
}

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
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-6">
            <div>
                <div class="flex items-center gap-2 text-xs text-emerald-400 font-semibold uppercase tracking-wider mb-1">
                    <span>{{ session.track?.name }}</span>
                    <span>·</span>
                    <span>{{ session.ended_at ? 'Sesión Finalizada' : 'Sesión en Vivo' }}</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white">{{ session.car_name }}</h1>
                <p class="text-sm text-zinc-400 mt-1">
                    Asfalto medio: <strong class="text-amber-300">{{ session.avg_track_temp_c }} °C</strong> (Aire {{ session.avg_air_temp_c }} °C)
                    <span v-if="session.active_reference_lap">
                        · Target a <strong class="text-emerald-300">{{ session.active_reference_lap.track_temp_c }} °C</strong> ({{ formatLapTime(session.active_reference_lap.lap_time_ms) }})
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

        <!-- Real-Time Optimal Lap vs Clean Best Summary -->
        <div class="grid sm:grid-cols-3 gap-4 mb-8">
            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-4">
                <div class="text-xs text-zinc-400">Mejor Vuelta Limpia Real</div>
                <div class="text-2xl font-bold font-mono text-emerald-400 mt-1">
                    {{ formatLapTime(session.best_lap_time_ms) }}
                </div>
                <div class="text-[11px] text-zinc-500 mt-0.5">{{ session.clean_laps_count }}/{{ session.laps_count }} vueltas completas limpias</div>
            </div>

            <div class="rounded-2xl bg-zinc-900 border border-violet-500/30 p-4">
                <div class="text-xs text-violet-300 font-medium">⚡ Vuelta Óptima (Mejores Parciales)</div>
                <div class="text-2xl font-bold font-mono text-violet-300 mt-1">
                    {{ formatLapTime(session.optimal_lap_time_ms) }}
                </div>
                <div class="text-[11px] text-zinc-400 mt-0.5">
                    <template v-if="session.best_sectors_ms">
                        <span v-for="(ms, sec) in session.best_sectors_ms" :key="sec" class="mr-2 font-mono">
                            S{{ sec }}: {{ formatSectorSeconds(ms) }}
                        </span>
                    </template>
                    <span v-else>Combinando tus mejores parciales limpios</span>
                </div>
            </div>

            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-4">
                <div class="text-xs text-zinc-400">Parciales Válidos Aprovechados</div>
                <div class="text-2xl font-bold text-white mt-1">
                    {{ session.clean_sectors_count || 0 }}
                </div>
                <div class="text-[11px] text-zinc-500 mt-0.5">Incluye sectores limpios de vueltas con 1x y out-laps</div>
            </div>
        </div>

        <!-- Active Real-Time Radio Coaching Plan + Causal Breakdown & 2D Trajectory Map -->
        <div v-if="session.active_coaching_plan?.focus_turn" class="mb-8 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
                <div>
                    <span class="text-xs uppercase tracking-wider text-emerald-400 font-semibold">Diagnóstico Causal Activo por Radio</span>
                    <h2 class="text-lg font-bold text-white">Foco en {{ session.active_coaching_plan.focus_turn_name }} (Curva {{ session.active_coaching_plan.focus_turn }})</h2>
                    <p v-if="session.active_coaching_plan.primary_issue" class="text-xs text-amber-300 mt-0.5">
                        {{ issueLabelMap[session.active_coaching_plan.primary_issue] || session.active_coaching_plan.primary_issue }}
                    </p>
                </div>
                <span class="text-xs px-3 py-1 rounded-full bg-rose-500/15 text-rose-300 border border-rose-500/30 shrink-0">
                    Pérdida media detectada: +{{ (session.active_coaching_plan.avg_time_loss_ms / 1000).toFixed(2) }}s
                </span>
            </div>

            <!-- Causal Telemetry Metrics Bar -->
            <div v-if="breakdown" class="grid grid-cols-2 sm:grid-cols-5 gap-2.5 mb-5 text-xs">
                <div class="rounded-xl bg-zinc-900/90 border border-zinc-800 px-3 py-2">
                    <div class="text-zinc-500">Punto Frenada</div>
                    <div class="font-mono font-semibold text-zinc-100">
                        {{ breakdown.early_brake_m >= 0 ? `${breakdown.early_brake_m}m antes` : `${Math.abs(breakdown.early_brake_m)}m tarde` }}
                    </div>
                </div>
                <div v-if="breakdown.has_trajectory" class="rounded-xl bg-zinc-900/90 border border-zinc-800 px-3 py-2">
                    <div class="text-zinc-500">Línea Entrada</div>
                    <div class="font-mono font-semibold" :class="breakdown.entry_inside_m >= 1.1 ? 'text-amber-300' : 'text-emerald-300'">
                        {{ breakdown.entry_inside_m >= 0 ? `${breakdown.entry_inside_m}m cerrado` : `${Math.abs(breakdown.entry_inside_m)}m abierto` }}
                    </div>
                </div>
                <div v-if="breakdown.has_trajectory" class="rounded-xl bg-zinc-900/90 border border-zinc-800 px-3 py-2">
                    <div class="text-zinc-500">Paso Vértice</div>
                    <div class="font-mono font-semibold" :class="breakdown.apex_inside_m <= -1.0 ? 'text-rose-300' : 'text-emerald-300'">
                        {{ breakdown.apex_inside_m < 0 ? `${Math.abs(breakdown.apex_inside_m)}m fuera` : `Clavado (${breakdown.apex_inside_m}m)` }}
                    </div>
                </div>
                <div v-if="breakdown.has_trajectory" class="rounded-xl bg-zinc-900/90 border border-zinc-800 px-3 py-2">
                    <div class="text-zinc-500">Ancho Salida</div>
                    <div class="font-mono font-semibold" :class="breakdown.exit_inside_m >= 1.25 ? 'text-amber-300' : 'text-emerald-300'">
                        {{ breakdown.exit_inside_m >= 0 ? `${breakdown.exit_inside_m}m sin usar` : `${Math.abs(breakdown.exit_inside_m)}m exterior` }}
                    </div>
                </div>
                <div class="rounded-xl bg-zinc-900/90 border border-zinc-800 px-3 py-2">
                    <div class="text-zinc-500">Subviraje / Gas</div>
                    <div class="font-mono font-semibold" :class="breakdown.understeer_deg >= 18 ? 'text-amber-300' : 'text-zinc-100'">
                        {{ breakdown.understeer_deg > 0 ? `+${breakdown.understeer_deg}° vol.` : `Gas +${breakdown.late_throttle_m}m` }}
                    </div>
                </div>
            </div>

            <div class="grid lg:grid-cols-3 gap-4 items-stretch">
                <div class="lg:col-span-2 grid md:grid-cols-2 gap-4">
                    <div v-for="trg in activeTriggers" :key="trg.id" class="rounded-xl bg-zinc-900/90 border border-zinc-800 p-4 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between text-xs text-zinc-400 mb-2">
                                <span class="uppercase font-semibold text-emerald-400">{{ trg.phase === 'pre_brake' ? 'Anticipación Pre-Frenada' : 'Feedback Salida de Curva' }}</span>
                                <span class="font-mono">Trigger @ {{ (trg.trigger_pct * 100).toFixed(1) }}%</span>
                            </div>
                            <p class="text-sm text-zinc-100 italic">“{{ trg.text }}”</p>
                        </div>
                    </div>
                </div>

                <!-- 2D Top-Down Trajectory Comparison Mini-Map -->
                <div v-if="focusTrajectoryMap" class="rounded-xl bg-zinc-950 border border-zinc-800 p-4 flex flex-col">
                    <div class="flex items-center justify-between text-[11px] text-zinc-400 mb-2">
                        <span class="font-semibold text-zinc-200">Mapa 2D Trazada ({{ focusTrajectoryMap.name }})</span>
                        <div class="flex items-center gap-2.5">
                            <span class="inline-flex items-center gap-1 text-emerald-400"><span class="w-2.5 h-0.5 bg-emerald-400 inline-block"></span>Target</span>
                            <span class="inline-flex items-center gap-1 text-amber-400"><span class="w-2.5 h-0.5 bg-amber-400 inline-block"></span>Tú</span>
                        </div>
                    </div>
                    <svg :viewBox="focusTrajectoryMap.view_box" class="w-full h-44 rounded-lg bg-zinc-900/60 p-1">
                        <!-- Asphalt corridor -->
                        <polyline
                            :points="pointsToPolyline(focusTrajectoryMap.ref_points)"
                            fill="none"
                            stroke="#27272a"
                            stroke-width="10"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                        <!-- Target reference line -->
                        <polyline
                            :points="pointsToPolyline(focusTrajectoryMap.ref_points)"
                            fill="none"
                            stroke="#34d399"
                            stroke-width="1.6"
                            stroke-dasharray="3,2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                        <!-- Driver line -->
                        <polyline
                            :points="pointsToPolyline(focusTrajectoryMap.driver_points)"
                            fill="none"
                            stroke="#fbbf24"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                        <!-- Apex marker -->
                        <circle
                            v-if="focusTrajectoryMap.markers?.apex"
                            :cx="focusTrajectoryMap.markers.apex[0]"
                            :cy="focusTrajectoryMap.markers.apex[1]"
                            r="2.4"
                            fill="#10b981"
                        />
                    </svg>
                    <p class="text-[10px] text-zinc-500 mt-1.5 text-center">Entrada desde abajo hacia arriba · Punto verde = Vértice referencia</p>
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

        <!-- Laps & Partials History Table -->
        <div class="rounded-2xl bg-zinc-900 border border-zinc-800 overflow-hidden">
            <div class="px-6 py-4 border-b border-zinc-800 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <h2 class="font-semibold text-white">Registro de Vueltas y Parciales ({{ session.laps?.length || 0 }})</h2>
                <span class="text-xs text-zinc-400">Los parciales limpios (verdes/morados) entrenan al coach aunque otro sector tenga off-track</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase text-zinc-400 bg-zinc-950/60 border-b border-zinc-800">
                        <tr>
                            <th class="px-6 py-3">Vuelta</th>
                            <th class="px-6 py-3">Tiempo</th>
                            <th class="px-6 py-3">Parciales (S1 · S2 · S3...)</th>
                            <th class="px-6 py-3">Estado</th>
                            <th class="px-6 py-3">Comb. / Asfalto</th>
                            <th class="px-6 py-3 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800/70">
                        <tr v-for="lap in session.laps" :key="lap.id" class="hover:bg-zinc-800/30">
                            <td class="px-6 py-3.5 font-mono text-zinc-300">
                                {{ lap.lap_number === 0 ? 'Out' : `#${lap.lap_number}` }}
                            </td>
                            <td class="px-6 py-3.5 font-mono font-semibold" :class="lap.lap_time_ms === session.best_lap_time_ms && lap.is_clean && lap.is_complete ? 'text-emerald-400' : 'text-white'">
                                {{ lap.is_complete ? formatLapTime(lap.lap_time_ms) : 'En curso / Parcial' }}
                            </td>
                            <td class="px-6 py-3.5">
                                <div v-if="lap.sector_metrics?.length" class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="sm in lap.sector_metrics"
                                        :key="sm.sector"
                                        class="px-2 py-0.5 rounded text-[11px] font-mono border"
                                        :class="!sm.is_clean
                                            ? 'bg-amber-500/10 text-amber-300 border-amber-500/30 line-through'
                                            : isBestSector(sm.sector, sm.time_ms, sm.is_clean)
                                                ? 'bg-violet-500/20 text-violet-200 border-violet-500/40 font-bold'
                                                : 'bg-zinc-950 text-emerald-300 border-zinc-800'"
                                    >
                                        S{{ sm.sector }} {{ formatSectorSeconds(sm.time_ms) }}
                                    </span>
                                </div>
                                <span v-else class="text-xs text-zinc-500">—</span>
                            </td>
                            <td class="px-6 py-3.5">
                                <span v-if="!lap.is_complete" class="px-2 py-0.5 rounded-full bg-sky-500/15 text-sky-300 text-xs">Parciales</span>
                                <span v-else-if="lap.is_clean" class="px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300 text-xs">Limpia</span>
                                <span v-else class="px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-300 text-xs">Con 1x (Parciales útiles)</span>
                            </td>
                            <td class="px-6 py-3.5 text-xs text-zinc-400">
                                <span>{{ lap.track_temp_c }} °C</span>
                                <span v-if="lap.fuel_level_l" class="text-zinc-500"> · {{ lap.fuel_level_l }}L</span>
                            </td>
                            <td class="px-6 py-3.5 text-right">
                                <button v-if="lap.is_clean && lap.is_complete" @click="promoteLap(lap.id)" class="text-xs text-emerald-400 hover:text-emerald-300 font-medium cursor-pointer">
                                    + Guardar Referencia
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
