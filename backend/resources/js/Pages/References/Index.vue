<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatLapTime } from '../../Utils/formatters';

const props = defineProps({
    references: Array,
    tracks: Array,
    garage61Connected: Boolean,
    g61Tracks: Array,
    g61Cars: Array,
    g61Analyses: Array,
});

// Import Drawer state
const showImportDrawer = ref(false);
const importTab = ref('g61'); // 'g61' | 'file'

// Library Filters & View Tabs
const libraryTab = ref('curated'); // 'curated' | 'autopilot' | 'all'
const filterTrackId = ref('');
const filterQuery = ref('');
const filterSurface = ref('all'); // 'all' | 'dry' | 'wet'
const openGroups = ref({});

// File Upload Form (.ibt / .csv / .json)
const uploadForm = useForm({
    telemetry_file: null,
    track_id: '',
    car_name: '',
    lap_time_override: '',
    label: '',
    track_temp_override: '',
    air_temp_override: '',
    is_wet: false,
});
const isCsvSelected = ref(false);

// Garage 61 Import & Search State
const g61UrlForm = useForm({ g61_input: '' });
const selectedG61TrackId = ref(props.g61Tracks?.[0]?.id || '');
const selectedG61CarId = ref('');
const targetLocalTrackId = ref(props.tracks?.[0]?.id || '');
const searchTemp = ref('');
const searchOnlyTeam = ref(false);
const searchingG61 = ref(false);
const searchExecuted = ref(false);
const g61Results = ref([]);
const g61SearchError = ref('');

const curatedCount = computed(() => (props.references || []).filter((r) => r.source !== 'garage61_autopilot').length);
const autopilotCount = computed(() => (props.references || []).filter((r) => r.source === 'garage61_autopilot').length);

const filteredReferences = computed(() => {
    return (props.references || []).filter((r) => {
        if (libraryTab.value === 'curated' && r.source === 'garage61_autopilot' && !r.is_pinned) return false;
        if (libraryTab.value === 'autopilot' && r.source !== 'garage61_autopilot') return false;
        if (filterTrackId.value && Number(r.track?.id) !== Number(filterTrackId.value)) return false;
        if (filterSurface.value === 'dry' && r.is_wet) return false;
        if (filterSurface.value === 'wet' && !r.is_wet) return false;
        if (filterQuery.value.trim() !== '') {
            const q = filterQuery.value.toLowerCase();
            const hay = `${r.car_name} ${r.label} ${r.driver_name || ''} ${r.track?.name || ''}`.toLowerCase();
            if (!hay.includes(q)) return false;
        }
        return true;
    });
});

// Group filtered references by (Track + Car) and compute Thermal Coverage Traffic Light
const groupedByCombo = computed(() => {
    const map = new Map();

    for (const refLap of filteredReferences.value) {
        const key = `${refLap.track?.id || 0}::${refLap.car_name}`;
        if (!map.has(key)) {
            map.set(key, {
                key,
                trackName: refLap.track?.name || 'Circuito',
                carName: refLap.car_name,
                laps: [],
                hasPinned: false,
                bands: {
                    cool: null, // < 23 °C dry
                    warm: null, // 23..30 °C dry
                    hot: null,  // > 30 °C dry
                    wet: null,  // is_wet
                },
            });
        }
        const g = map.get(key);
        g.laps.push(refLap);
        if (refLap.is_pinned) g.hasPinned = true;

        if (refLap.is_wet) {
            if (!g.bands.wet || refLap.lap_time_ms < g.bands.wet.lap_time_ms) g.bands.wet = refLap;
        } else if (refLap.track_temp_c < 23) {
            if (!g.bands.cool || refLap.lap_time_ms < g.bands.cool.lap_time_ms) g.bands.cool = refLap;
        } else if (refLap.track_temp_c <= 30) {
            if (!g.bands.warm || refLap.lap_time_ms < g.bands.warm.lap_time_ms) g.bands.warm = refLap;
        } else {
            if (!g.bands.hot || refLap.lap_time_ms < g.bands.hot.lap_time_ms) g.bands.hot = refLap;
        }
    }

    return Array.from(map.values());
});

function isGroupOpen(key, idx) {
    if (openGroups.value[key] === undefined) {
        return idx < 2; // Expand first 2 combos by default
    }
    return openGroups.value[key];
}

function toggleGroup(key, idx) {
    openGroups.value[key] = !isGroupOpen(key, idx);
}

function onFileChange(e) {
    const f = e.target.files[0] || null;
    uploadForm.telemetry_file = f;
    isCsvSelected.value = !!f && f.name.toLowerCase().endsWith('.csv');
    if (isCsvSelected.value && !uploadForm.track_id && props.tracks?.length) {
        uploadForm.track_id = props.tracks[0].id;
    }
}

function submitUpload() {
    uploadForm.post('/references', {
        forceFormData: true,
        onSuccess: () => {
            uploadForm.reset();
            isCsvSelected.value = false;
            showImportDrawer.value = false;
        },
    });
}

function submitG61Url() {
    g61UrlForm.post('/references/garage61/import-url', {
        onSuccess: () => {
            g61UrlForm.reset();
            showImportDrawer.value = false;
        },
    });
}

function importSavedAnalysis(analysisId) {
    g61UrlForm.g61_input = `https://garage61.net/app/analyses/${analysisId}`;
    submitG61Url();
}

async function runG61Search() {
    searchingG61.value = true;
    searchExecuted.value = false;
    g61SearchError.value = '';
    try {
        const params = new URLSearchParams({
            only_team: searchOnlyTeam.value ? '1' : '0',
        });
        if (selectedG61TrackId.value) params.set('g61_track_id', selectedG61TrackId.value);
        if (selectedG61CarId.value) params.set('g61_car_id', selectedG61CarId.value);
        if (targetLocalTrackId.value) params.set('track_id', targetLocalTrackId.value);
        if (searchTemp.value !== '') params.set('track_temp_c', searchTemp.value);

        const resp = await fetch(`/references/garage61/search?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        const data = await resp.json();
        searchExecuted.value = true;
        if (!resp.ok) {
            g61SearchError.value = data.message || 'No se pudo consultar Garage 61.';
            g61Results.value = [];
        } else {
            g61Results.value = data.items || [];
        }
    } catch {
        g61SearchError.value = 'Error de red al consultar Garage 61.';
    } finally {
        searchingG61.value = false;
    }
}

function importFoundLap(item) {
    router.post('/references/garage61/import-lap', {
        lap_id: item.id,
        track_id: targetLocalTrackId.value || null,
        car_name: item.car_name || null,
    }, { preserveScroll: true });
}

function togglePin(id) {
    router.post(`/references/${id}/pin`, {}, { preserveScroll: true });
}

function removeRef(id) {
    if (confirm('¿Eliminar esta vuelta de referencia?')) {
        router.delete(`/references/${id}`, { preserveScroll: true });
    }
}

function pruneReferences(mode) {
    const msg = mode === 'older_90d'
        ? '¿Borrar referencias antiguas (> 90 días) no fijadas para limpiar temporadas pasadas?'
        : '¿Vaciar las referencias auto-descargadas por Autopilot que no estén fijadas?';
    if (confirm(msg)) {
        router.delete(`/references/prune?mode=${mode}`, { preserveScroll: true });
    }
}

function sourceLabel(src, isOwned) {
    switch (src) {
        case 'garage61_autopilot': return 'Autopilot G61';
        case 'garage61_manual': return 'G61 API';
        case 'g61_csv_upload': return 'G61 CSV';
        case 'ibt_upload': return '.ibt Local';
        case 'promoted_lap': return 'Vuelta Propia';
        default: return isOwned ? 'Propia' : 'Base Sistema';
    }
}
</script>

<template>
    <Head title="Biblioteca de Referencias" />
    <AppLayout>
        <!-- Top Header & Action Buttons -->
        <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4 mb-6">
            <div>
                <p class="text-xs uppercase tracking-widest text-emerald-400 font-semibold mb-1">Cobertura Térmica por Circuito &amp; Coche</p>
                <h1 class="text-2xl sm:text-3xl font-bold text-white">Biblioteca de Vueltas de Referencia</h1>
                <p class="text-sm text-zinc-400 mt-1">
                    Organizadas por combinación y franja de asfalto. Fija con <strong>📌</strong> tu vuelta maestra de equipo para darle prioridad absoluta.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <button
                    v-if="autopilotCount > 0"
                    type="button"
                    @click="pruneReferences('autopilot')"
                    class="px-3 py-2 rounded-xl bg-zinc-900 border border-zinc-800 hover:border-zinc-700 text-xs text-zinc-300 cursor-pointer"
                >
                    Limpiar Caché Autopilot ({{ autopilotCount }})
                </button>
                <button
                    type="button"
                    @click="pruneReferences('older_90d')"
                    class="px-3 py-2 rounded-xl bg-zinc-900 border border-zinc-800 hover:border-zinc-700 text-xs text-zinc-400 hover:text-zinc-200 cursor-pointer"
                >
                    Limpiar &gt; 90 días
                </button>
                <button
                    type="button"
                    @click="showImportDrawer = !showImportDrawer"
                    class="px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold text-sm transition cursor-pointer shadow-lg shadow-emerald-500/10"
                >
                    {{ showImportDrawer ? '✕ Cerrar Importador' : '+ Añadir Referencia' }}
                </button>
            </div>
        </div>

        <!-- Collapsible Tabbed Importer Drawer -->
        <div v-if="showImportDrawer" class="mb-8 rounded-2xl bg-zinc-900 border border-emerald-500/40 overflow-hidden shadow-2xl">
            <div class="flex border-b border-zinc-800 bg-zinc-950/60 text-xs font-semibold">
                <button
                    type="button"
                    @click="importTab = 'g61'"
                    class="px-5 py-3.5 transition cursor-pointer"
                    :class="importTab === 'g61' ? 'text-emerald-400 border-b-2 border-emerald-400 bg-zinc-900/50' : 'text-zinc-400 hover:text-white'"
                >
                    1. Importar desde API Garage 61 (Equipo / Análisis)
                </button>
                <button
                    type="button"
                    @click="importTab = 'file'"
                    class="px-5 py-3.5 transition cursor-pointer"
                    :class="importTab === 'file' ? 'text-emerald-400 border-b-2 border-emerald-400 bg-zinc-900/50' : 'text-zinc-400 hover:text-white'"
                >
                    2. Subir Archivo (.csv de G61 / .ibt / .json)
                </button>
            </div>

            <!-- Tab 1: Garage 61 API -->
            <div v-if="importTab === 'g61'" class="p-6">
                <div v-if="!garage61Connected" class="mb-4 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-xs text-amber-200 flex items-center justify-between">
                    <span>Conecta primero tu cuenta de Garage 61 en Ajustes para usar el buscador e importador directo.</span>
                    <Link href="/settings" class="font-bold underline ml-3">Ir a Ajustes →</Link>
                </div>

                <form @submit.prevent="submitG61Url" class="flex flex-col sm:flex-row gap-3 mb-4">
                    <input
                        v-model="g61UrlForm.g61_input"
                        type="text"
                        required
                        placeholder="Pega enlace de vuelta propia/equipo, enlace de análisis (/app/analyses/...) o ID"
                        class="flex-1 rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500"
                    />
                    <button type="submit" :disabled="g61UrlForm.processing || !garage61Connected" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 disabled:opacity-50 text-zinc-950 font-semibold text-sm cursor-pointer">
                        Importar Enlace / Análisis
                    </button>
                </form>
                <p v-if="g61UrlForm.errors.g61_input" class="text-xs text-rose-400 mb-4 leading-relaxed">{{ g61UrlForm.errors.g61_input }}</p>

                <div v-if="g61Analyses?.length" class="mb-5 flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-zinc-400">Tus análisis recientes en G61:</span>
                    <button
                        v-for="an in g61Analyses.slice(0, 6)"
                        :key="an.id"
                        type="button"
                        @click="importSavedAnalysis(an.id)"
                        class="px-2.5 py-1 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-emerald-300 font-mono cursor-pointer"
                    >
                        + Análisis {{ an.id.slice(0, 8) }}…
                    </button>
                </div>

                <div class="border-t border-zinc-800 pt-5">
                    <div class="grid sm:grid-cols-5 gap-3 items-end">
                        <div class="sm:col-span-2">
                            <label class="block text-xs text-zinc-400 mb-1">Circuito en Garage 61</label>
                            <select v-if="g61Tracks?.length" v-model="selectedG61TrackId" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white">
                                <option v-for="gt in g61Tracks" :key="gt.id" :value="gt.id">{{ gt.label }}</option>
                            </select>
                            <select v-else v-model="targetLocalTrackId" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white">
                                <option v-for="t in tracks" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-400 mb-1">Coche en G61 (opc.)</label>
                            <select v-model="selectedG61CarId" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white">
                                <option value="">Todos los coches</option>
                                <option v-for="gc in g61Cars" :key="gc.id" :value="gc.id">{{ gc.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-400 mb-1">Asfalto °C (opc., ±4 °C)</label>
                            <input v-model="searchTemp" type="number" step="0.5" placeholder="Cualquiera" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white" />
                        </div>
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-1.5 text-xs text-zinc-300 cursor-pointer">
                                <input v-model="searchOnlyTeam" type="checkbox" class="rounded bg-zinc-950 border-zinc-700" />
                                Solo Equipo
                            </label>
                            <button type="button" @click="runG61Search" :disabled="searchingG61 || !garage61Connected" class="flex-1 py-2 px-3 rounded-xl bg-zinc-800 hover:bg-zinc-700 disabled:opacity-50 text-xs font-semibold text-white cursor-pointer">
                                {{ searchingG61 ? 'Buscando...' : 'Buscar' }}
                            </button>
                        </div>
                    </div>

                    <p v-if="g61SearchError" class="text-xs text-rose-400 mt-3 leading-relaxed">{{ g61SearchError }}</p>
                    <div v-else-if="searchExecuted && !g61Results.length" class="mt-3 rounded-xl border border-zinc-800 bg-zinc-950/60 px-4 py-3 text-xs text-zinc-400">
                        No se encontraron vueltas limpias para ese filtro. Prueba dejando temperatura vacía o desmarcando "Solo Equipo".
                    </div>

                    <div v-if="g61Results.length" class="mt-4 divide-y divide-zinc-800 rounded-xl border border-zinc-800 bg-zinc-950/60 max-h-72 overflow-y-auto">
                        <div v-for="item in g61Results" :key="item.id" class="px-4 py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                            <div>
                                <span class="font-semibold text-white">{{ item.driver_name || item.id }}</span>
                                <span class="text-emerald-400 ml-2">{{ item.car_name }}</span>
                                <span class="text-zinc-400 ml-2">· Asfalto {{ item.track_temp_c }} °C · Aire {{ item.air_temp_c }} °C</span>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="font-mono font-bold text-emerald-400">{{ formatLapTime(item.lap_time_ms) }}</span>
                                <button @click="importFoundLap(item)" class="px-3 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30 font-medium cursor-pointer">
                                    + Importar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Local File Upload (.csv / .ibt / .json) -->
            <div v-else class="p-6">
                <form @submit.prevent="submitUpload" class="grid md:grid-cols-2 gap-5">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-zinc-300 mb-1">Archivo (.ibt, .csv exportado de G61 o .json)</label>
                            <input type="file" accept=".ibt,.csv,.json" required @change="onFileChange" class="w-full text-xs text-zinc-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-zinc-800 file:text-zinc-200 hover:file:bg-zinc-700" />
                            <p v-if="uploadForm.errors.telemetry_file" class="text-xs text-rose-400 mt-1">{{ uploadForm.errors.telemetry_file }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-zinc-300 mb-1">Etiqueta identificativa (opcional)</label>
                            <input v-model="uploadForm.label" type="text" placeholder="Ej. Setup 24h Spa - Compañero" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500" />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Temp. Pista °C (opc.)</label>
                                <input v-model="uploadForm.track_temp_override" type="number" step="0.1" placeholder="Auto" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white" />
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Temp. Aire °C (opc.)</label>
                                <input v-model="uploadForm.air_temp_override" type="number" step="0.1" placeholder="Auto" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white" />
                            </div>
                        </div>

                        <label class="flex items-center gap-2 text-xs text-zinc-300 cursor-pointer">
                            <input v-model="uploadForm.is_wet" type="checkbox" class="rounded border-zinc-700 bg-zinc-950 text-emerald-500" />
                            Pista en mojado / lluvia
                        </label>
                    </div>

                    <div class="space-y-3 rounded-xl border border-zinc-800 bg-zinc-950/60 p-4 flex flex-col justify-between">
                        <div class="space-y-3">
                            <div class="text-xs font-semibold" :class="isCsvSelected ? 'text-emerald-400' : 'text-zinc-400'">
                                {{ isCsvSelected ? 'Datos requeridos para CSV de Garage 61:' : 'Sobrescribir Circuito / Coche (opcional en .ibt):' }}
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Circuito</label>
                                <select v-model="uploadForm.track_id" :required="isCsvSelected" class="w-full rounded-xl bg-zinc-900 border border-zinc-800 px-3 py-2 text-sm text-white">
                                    <option value="">Autodetectar del .ibt</option>
                                    <option v-for="t in tracks" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Nombre exacto del Coche en iRacing</label>
                                <input v-model="uploadForm.car_name" :required="isCsvSelected" type="text" placeholder="Ej. Porsche 911 GT3 R (992)" class="w-full rounded-xl bg-zinc-900 border border-zinc-800 px-3 py-2 text-sm text-white" />
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Tiempo oficial (opc., ej. 2:17.450)</label>
                                <input v-model="uploadForm.lap_time_override" type="text" placeholder="Calculado automáticamente si vacío" class="w-full rounded-xl bg-zinc-900 border border-zinc-800 px-3 py-2 text-sm font-mono text-white" />
                            </div>
                        </div>

                        <button type="submit" :disabled="uploadForm.processing" class="mt-4 w-full py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold text-sm transition cursor-pointer">
                            Guardar Referencia
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Filter & Tabs Toolbar -->
        <div class="mb-6 rounded-2xl bg-zinc-900 border border-zinc-800 p-4 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="flex items-center gap-1.5 bg-zinc-950 p-1 rounded-xl border border-zinc-800 w-fit text-xs font-semibold">
                <button
                    type="button"
                    @click="libraryTab = 'curated'"
                    class="px-3.5 py-1.5 rounded-lg transition cursor-pointer"
                    :class="libraryTab === 'curated' ? 'bg-emerald-500 text-zinc-950' : 'text-zinc-400 hover:text-white'"
                >
                    Curadas &amp; Equipo ({{ curatedCount }})
                </button>
                <button
                    type="button"
                    @click="libraryTab = 'autopilot'"
                    class="px-3.5 py-1.5 rounded-lg transition cursor-pointer"
                    :class="libraryTab === 'autopilot' ? 'bg-emerald-500 text-zinc-950' : 'text-zinc-400 hover:text-white'"
                >
                    Autopilot en Caché ({{ autopilotCount }})
                </button>
                <button
                    type="button"
                    @click="libraryTab = 'all'"
                    class="px-3.5 py-1.5 rounded-lg transition cursor-pointer"
                    :class="libraryTab === 'all' ? 'bg-emerald-500 text-zinc-950' : 'text-zinc-400 hover:text-white'"
                >
                    Todas ({{ references.length }})
                </button>
            </div>

            <div class="grid sm:grid-cols-3 gap-2.5 flex-1 max-w-2xl">
                <select v-model="filterTrackId" class="rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-xs text-white">
                    <option value="">Todos los circuitos</option>
                    <option v-for="t in tracks" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>

                <select v-model="filterSurface" class="rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-xs text-white">
                    <option value="all">Seco y Mojado</option>
                    <option value="dry">Solo Seco</option>
                    <option value="wet">Solo Mojado</option>
                </select>

                <input
                    v-model="filterQuery"
                    type="text"
                    placeholder="Filtrar coche, piloto, etiqueta..."
                    class="rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-emerald-500"
                />
            </div>
        </div>

        <!-- Empty State -->
        <div v-if="!groupedByCombo.length" class="rounded-2xl bg-zinc-900 border border-zinc-800 p-12 text-center">
            <p class="text-sm text-zinc-400 mb-3">No hay vueltas de referencia en esta vista con los filtros seleccionados.</p>
            <button type="button" @click="showImportDrawer = true" class="px-4 py-2 rounded-xl bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-xs font-semibold cursor-pointer">
                + Añadir primera referencia
            </button>
        </div>

        <!-- Grouped Circuit + Car Accordion Cards with Thermal Coverage Traffic Light -->
        <div v-else class="space-y-4">
            <div
                v-for="(group, idx) in groupedByCombo"
                :key="group.key"
                class="rounded-2xl bg-zinc-900 border overflow-hidden transition"
                :class="group.hasPinned ? 'border-emerald-500/40' : 'border-zinc-800'"
            >
                <button
                    type="button"
                    @click="toggleGroup(group.key, idx)"
                    class="w-full text-left px-6 py-4 bg-zinc-900 hover:bg-zinc-800/40 transition flex flex-col xl:flex-row xl:items-center justify-between gap-4 cursor-pointer"
                >
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-base text-white">{{ group.trackName }}</span>
                            <span class="text-zinc-500">·</span>
                            <span class="text-sm font-medium text-emerald-400">{{ group.carName }}</span>
                            <span class="text-[11px] px-2 py-0.5 rounded-full bg-zinc-800 text-zinc-300">
                                {{ group.laps.length }} {{ group.laps.length === 1 ? 'vuelta' : 'vueltas' }}
                            </span>
                            <span v-if="group.hasPinned" class="text-[11px] px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                                📌 Fijada activa
                            </span>
                        </div>
                    </div>

                    <!-- Thermal Coverage Traffic Light -->
                    <div class="flex flex-wrap items-center gap-2 text-[11px]">
                        <div class="px-2.5 py-1 rounded-lg border" :class="group.bands.cool ? 'bg-cyan-500/10 border-cyan-500/30 text-cyan-200' : 'bg-zinc-950 border-zinc-800 text-zinc-500'">
                            Frío &lt;23°: <strong class="font-mono">{{ group.bands.cool ? formatLapTime(group.bands.cool.lap_time_ms) : '—' }}</strong>
                        </div>
                        <div class="px-2.5 py-1 rounded-lg border" :class="group.bands.warm ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-200' : 'bg-zinc-950 border-zinc-800 text-zinc-500'">
                            Templado 23–30°: <strong class="font-mono">{{ group.bands.warm ? formatLapTime(group.bands.warm.lap_time_ms) : '—' }}</strong>
                        </div>
                        <div class="px-2.5 py-1 rounded-lg border" :class="group.bands.hot ? 'bg-amber-500/10 border-amber-500/30 text-amber-200' : 'bg-zinc-950 border-zinc-800 text-zinc-500'">
                            Calor &gt;30°: <strong class="font-mono">{{ group.bands.hot ? formatLapTime(group.bands.hot.lap_time_ms) : '—' }}</strong>
                        </div>
                        <div v-if="group.bands.wet" class="px-2.5 py-1 rounded-lg border bg-sky-500/15 border-sky-500/30 text-sky-200">
                            Mojado: <strong class="font-mono">{{ formatLapTime(group.bands.wet.lap_time_ms) }}</strong>
                        </div>
                        <span class="text-zinc-500 ml-1 text-xs">{{ isGroupOpen(group.key, idx) ? '▲' : '▼' }}</span>
                    </div>
                </button>

                <!-- Expanded Laps Table for this Combo -->
                <div v-if="isGroupOpen(group.key, idx)" class="border-t border-zinc-800 divide-y divide-zinc-800/60 bg-zinc-950/40">
                    <div
                        v-for="refLap in group.laps"
                        :key="refLap.id"
                        class="px-6 py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs hover:bg-zinc-900/60"
                    >
                        <div class="flex flex-wrap items-center gap-2.5">
                            <button
                                v-if="refLap.is_owned"
                                type="button"
                                @click="togglePin(refLap.id)"
                                class="px-2 py-1 rounded-lg border transition cursor-pointer"
                                :class="refLap.is_pinned ? 'bg-emerald-500/20 border-emerald-500/40 text-emerald-300 font-semibold' : 'bg-zinc-900 border-zinc-800 text-zinc-500 hover:text-zinc-200'"
                                title="Fijar como referencia prioritaria"
                            >
                                {{ refLap.is_pinned ? '📌 Fijada' : '📌 Fijar' }}
                            </button>

                            <span class="px-2 py-0.5 rounded-full border border-zinc-700 bg-zinc-900 text-zinc-300 text-[11px]">
                                {{ sourceLabel(refLap.source, refLap.is_owned) }}
                            </span>

                            <span v-if="refLap.is_wet" class="px-2 py-0.5 rounded-full bg-sky-500/20 text-sky-300 border border-sky-500/30 text-[11px]">
                                Mojado
                            </span>

                            <span class="text-zinc-200 font-medium">{{ refLap.label }}</span>
                        </div>

                        <div class="flex items-center justify-between sm:justify-end gap-5">
                            <div class="text-zinc-400">
                                Asfalto <strong class="text-amber-300">{{ refLap.track_temp_c }} °C</strong>
                                <span class="text-zinc-600 mx-1">·</span>
                                Aire {{ refLap.air_temp_c }} °C
                            </div>

                            <div class="font-mono text-sm font-bold text-emerald-400 w-24 text-right">
                                {{ formatLapTime(refLap.lap_time_ms) }}
                            </div>

                            <button
                                v-if="refLap.is_owned"
                                type="button"
                                @click="removeRef(refLap.id)"
                                class="text-rose-400 hover:text-rose-300 px-2 py-1 rounded border border-rose-500/25 cursor-pointer"
                            >
                                Eliminar
                            </button>
                            <span v-else class="w-14"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
