<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDeltaMs, formatLapTime, renderMarkdown } from '../../Utils/formatters';

const props = defineProps({
    session: Object,
    trajectorySuite: Object,
});

const generating = ref(false);
const selectedMapKey = ref(
    props.session.active_coaching_plan?.focus_turn
        ? `turn_${props.session.active_coaching_plan.focus_turn}`
        : 'circuit'
);

const activeTriggers = computed(() => props.session.active_coaching_plan?.triggers || []);
const breakdown = computed(() => props.session.active_coaching_plan?.telemetry_breakdown || null);
const isSelfRefMode = computed(() => !props.session.active_reference_lap || props.session.active_coaching_plan?.reference_mode === 'session_self');

const activeMapData = computed(() => {
    if (!props.trajectorySuite) return null;
    if (selectedMapKey.value === 'circuit') {
        return {
            isCircuit: true,
            title: `${props.session.track?.name} — Circuito Completo`,
            ...props.trajectorySuite.full_circuit,
        };
    }
    const turnNum = Number(selectedMapKey.value.replace('turn_', ''));
    const found = props.trajectorySuite.corners?.find((c) => c.turn === turnNum);
    if (found) {
        return {
            isCircuit: false,
            title: `Curva ${found.turn} · ${found.name}`,
            ...found,
        };
    }
    return {
        isCircuit: true,
        title: `${props.session.track?.name} — Circuito Completo`,
        ...props.trajectorySuite.full_circuit,
    };
});

const issueLabelMap = {
    overdriving_entry: 'Causa raíz: Frenada tardía → Vértice perdido',
    abs_abuse: 'Causa raíz: Exceso de ABS en frenada (sobrecalienta goma delantera)',
    narrow_entry: 'Causa raíz: Entrada cerrada (falta abrirse al exterior)',
    abrupt_turn_in: 'Causa raíz: Volantazo brusco al iniciar el giro',
    early_apex: 'Causa raíz: Giro prematuro (Early Apex → ahoga salida)',
    early_braking: 'Causa raíz: Frenada conservadora temprana',
    abrupt_brake_release: 'Causa raíz: Suelta brusca de freno (falta trail-braking)',
    wrong_gear_low: 'Causa raíz: Marcha demasiado corta en el vértice',
    wrong_gear_high: 'Causa raíz: Marcha demasiado larga en el vértice',
    throttle_understeer: 'Causa raíz: Gas precipitado → Subviraje de salida',
    understeer_scrub: 'Causa raíz: Exceso de volante (arrastre delantero)',
    under_driving_grip: 'Causa raíz: Margen de agarre libre (Círculo de Fricción G)',
    missed_curb: 'Causa raíz: Esquivando piano interior aprovechable',
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
                <div class="flex flex-wrap items-center gap-2 text-xs text-emerald-400 font-semibold uppercase tracking-wider mb-1">
                    <span>{{ session.track?.name }}</span>
                    <span>·</span>
                    <span>{{ session.ended_at ? 'Sesión Finalizada' : 'Sesión en Vivo' }}</span>
                    <span
                        class="px-2 py-0.5 rounded-full text-[10px] border normal-case"
                        :class="isSelfRefMode ? 'bg-sky-500/15 text-sky-300 border-sky-500/30' : 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'"
                    >
                        {{ isSelfRefMode ? 'Modo Auto-Referencia (Parciales propios)' : 'Target Externo Comparable' }}
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-bold text-white">{{ session.car_name }}</h1>
                <p class="text-sm text-zinc-400 mt-1">
                    Asfalto medio: <strong class="text-amber-300">{{ session.avg_track_temp_c }} °C</strong> (Aire {{ session.avg_air_temp_c }} °C · {{ session.is_wet ? 'Mojado' : 'Seco' }})
                    <span v-if="session.active_reference_lap">
                        · Target a <strong class="text-emerald-300">{{ session.active_reference_lap.track_temp_c }} °C</strong> ({{ formatLapTime(session.active_reference_lap.lap_time_ms) }})
                    </span>
                    <span v-else class="text-sky-300">
                        · Entrenando sobre tus mejores curvas y física en vivo (sin referencia externa comparable)
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

        <!-- Active Real-Time Radio Coaching Plan -->
        <div v-if="session.active_coaching_plan?.focus_turn" class="mb-8 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
                <div>
                    <span class="text-xs uppercase tracking-wider text-emerald-400 font-semibold">
                        {{ session.active_coaching_plan.reference_mode === 'session_self' ? 'Auto-Diagnóstico sobre tu Mejor Pasada' : 'Diagnóstico Causal Activo por Radio' }}
                    </span>
                    <h2 class="text-lg font-bold text-white">Foco en {{ session.active_coaching_plan.focus_turn_name }} (Curva {{ session.active_coaching_plan.focus_turn }})</h2>
                    <p v-if="session.active_coaching_plan.primary_issue" class="text-xs text-amber-300 mt-0.5">
                        {{ issueLabelMap[session.active_coaching_plan.primary_issue] || session.active_coaching_plan.primary_issue }}
                    </p>
                </div>
                <span class="text-xs px-3 py-1 rounded-full bg-rose-500/15 text-rose-300 border border-rose-500/30 shrink-0">
                    Potencial estimado: +{{ (session.active_coaching_plan.avg_time_loss_ms / 1000).toFixed(2) }}s
                </span>
            </div>

            <!-- Causal Telemetry Metrics Bar -->
            <div v-if="breakdown" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 mb-5 text-xs">
                <div class="rounded-xl bg-zinc-900/90 border border-zinc-800 px-3 py-2">
                    <div class="text-zinc-500">Frenada / ABS</div>
                    <div class="font-mono font-semibold text-zinc-100">
                        {{ breakdown.early_brake_m >= 0 ? `${breakdown.early_brake_m}m antes` : `${Math.abs(breakdown.early_brake_m)}m tarde` }}
                        <span v-if="breakdown.abs_active_pct > 0" :class="breakdown.abs_active_pct >= 45 ? 'text-rose-400' : 'text-zinc-400'"> · ABS {{ breakdown.abs_active_pct }}%</span>
                    </div>
                </div>
                <div v-if="breakdown.target_gear" class="rounded-xl bg-zinc-900/90 border border-zinc-800 px-3 py-2">
                    <div class="text-zinc-500">Marcha Vértice</div>
                    <div class="font-mono font-semibold" :class="breakdown.gear_delta !== 0 ? 'text-amber-300' : 'text-emerald-300'">
                        Target {{ breakdown.target_gear }}ª {{ breakdown.gear_delta !== 0 ? `(${breakdown.gear_delta > 0 ? '+' : ''}${breakdown.gear_delta})` : '✓' }}
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

            <div class="grid md:grid-cols-2 gap-4">
                <div v-for="trg in activeTriggers" :key="trg.id" class="rounded-xl bg-zinc-900/90 border border-zinc-800 p-4">
                    <div class="flex items-center justify-between text-xs text-zinc-400 mb-1">
                        <span class="uppercase font-semibold text-emerald-400">{{ trg.phase === 'pre_brake' ? 'Orden Pre-Frenada' : 'Orden Salida' }}</span>
                        <span class="font-mono">Trigger @ {{ (trg.trigger_pct * 100).toFixed(1) }}%</span>
                    </div>
                    <p class="text-sm text-zinc-100 font-medium">“{{ trg.text }}”</p>
                </div>
            </div>
        </div>

        <!-- Always-Available Interactive 2D Trajectory Viewer (Full Circuit + Corner Selector) -->
        <div v-if="trajectorySuite && activeMapData" class="mb-8 rounded-2xl bg-zinc-900 border border-zinc-800 p-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-4">
                <div>
                    <span class="text-xs uppercase tracking-wider text-emerald-400 font-semibold">Telemetría Espacial GPS (64-bit)</span>
                    <h2 class="text-lg font-bold text-white">Visor Interactivo de Trazadas 2D</h2>
                </div>
                <div class="flex items-center gap-4 text-xs">
                    <span class="inline-flex items-center gap-1.5 text-emerald-300 font-medium">
                        <span class="w-3 h-0.5 bg-emerald-400 inline-block"></span>
                        {{ trajectorySuite.target_label }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-amber-300 font-medium">
                        <span class="w-3 h-0.5 bg-amber-400 inline-block"></span>
                        Tu última trazada
                    </span>
                </div>
            </div>

            <!-- Circuit & Corner Selector Pills -->
            <div class="flex flex-wrap gap-2 mb-4">
                <button
                    type="button"
                    @click="selectedMapKey = 'circuit'"
                    class="px-3 py-1.5 rounded-xl text-xs font-semibold border transition cursor-pointer"
                    :class="selectedMapKey === 'circuit' ? 'bg-emerald-500 text-zinc-950 border-emerald-400' : 'bg-zinc-950 text-zinc-300 border-zinc-800 hover:border-zinc-600'"
                >
                    🏁 Circuito Completo
                </button>
                <button
                    v-for="c in trajectorySuite.corners"
                    :key="c.turn"
                    type="button"
                    @click="selectedMapKey = `turn_${c.turn}`"
                    class="px-3 py-1.5 rounded-xl text-xs font-medium border transition cursor-pointer"
                    :class="selectedMapKey === `turn_${c.turn}` ? 'bg-emerald-500 text-zinc-950 border-emerald-400 font-bold' : 'bg-zinc-950 text-zinc-300 border-zinc-800 hover:border-zinc-600'"
                >
                    C{{ c.turn }} · {{ c.name }}
                </button>
            </div>

            <div class="rounded-xl bg-zinc-950 border border-zinc-800 p-4">
                <div class="flex items-center justify-between text-xs text-zinc-400 mb-2">
                    <span class="font-semibold text-zinc-200">{{ activeMapData.title }}</span>
                    <span v-if="!activeMapData.isCircuit">Entrada desde abajo hacia arriba · Punto verde = Vértice</span>
                    <span v-else>Vista cenital completa del trazado</span>
                </div>

                <svg :viewBox="activeMapData.view_box" class="w-full h-72 sm:h-80 rounded-lg bg-zinc-900/50 p-2">
                    <!-- Asphalt ribbon -->
                    <polyline
                        :points="pointsToPolyline(activeMapData.ref_points)"
                        fill="none"
                        stroke="#27272a"
                        :stroke-width="activeMapData.isCircuit ? 14 : 10"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                    <!-- Target / Self-Best reference line -->
                    <polyline
                        :points="pointsToPolyline(activeMapData.ref_points)"
                        fill="none"
                        stroke="#34d399"
                        :stroke-width="activeMapData.isCircuit ? 3.2 : 1.7"
                        stroke-dasharray="4,2.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                    <!-- Driver line -->
                    <polyline
                        :points="pointsToPolyline(activeMapData.driver_points)"
                        fill="none"
                        stroke="#fbbf24"
                        :stroke-width="activeMapData.isCircuit ? 3.2 : 1.9"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                    <!-- Corner markers when zoomed into a turn -->
                    <template v-if="!activeMapData.isCircuit && activeMapData.markers">
                        <circle v-if="activeMapData.markers.brake" :cx="activeMapData.markers.brake[0]" :cy="activeMapData.markers.brake[1]" r="2.2" fill="#f43f5e" />
                        <circle v-if="activeMapData.markers.apex" :cx="activeMapData.markers.apex[0]" :cy="activeMapData.markers.apex[1]" r="2.6" fill="#10b981" />
                        <circle v-if="activeMapData.markers.exit" :cx="activeMapData.markers.exit[0]" :cy="activeMapData.markers.exit[1]" r="2.2" fill="#38bdf8" />
                    </template>
                </svg>
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
