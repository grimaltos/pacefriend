<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatLapTime } from '../../Utils/formatters';

const props = defineProps({
    references: Array,
    tracks: Array,
    garage61Connected: Boolean,
});

const uploadForm = useForm({
    telemetry_file: null,
    label: '',
    track_temp_override: '',
    air_temp_override: '',
    is_wet: false,
});

const g61UrlForm = useForm({
    g61_input: '',
});

const searchTrackId = ref(props.tracks?.[0]?.id || '');
const searchCarName = ref('Porsche 911 GT3 R (992)');
const searchTemp = ref('28');
const searchOnlyTeam = ref(false);
const searchingG61 = ref(false);
const g61Results = ref([]);
const g61SearchError = ref('');

function onFileChange(e) {
    uploadForm.telemetry_file = e.target.files[0] || null;
}

function submitUpload() {
    uploadForm.post('/references', {
        forceFormData: true,
        onSuccess: () => uploadForm.reset(),
    });
}

function submitG61Url() {
    g61UrlForm.post('/references/garage61/import-url', {
        onSuccess: () => g61UrlForm.reset(),
    });
}

async function runG61Search() {
    searchingG61.value = true;
    g61SearchError.value = '';
    try {
        const params = new URLSearchParams({
            track_id: searchTrackId.value,
            car_name: searchCarName.value,
            only_team: searchOnlyTeam.value ? '1' : '0',
        });
        if (searchTemp.value !== '') {
            params.set('track_temp_c', searchTemp.value);
        }
        const resp = await fetch(`/references/garage61/search?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        const data = await resp.json();
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
        track_id: searchTrackId.value,
        car_name: searchCarName.value,
    });
}

function removeRef(id) {
    if (confirm('¿Eliminar esta vuelta de referencia?')) {
        router.delete(`/references/${id}`);
    }
}
</script>

<template>
    <Head title="Vueltas de Referencia & Garage 61" />
    <AppLayout>
        <div class="mb-8">
            <p class="text-xs uppercase tracking-widest text-emerald-400 font-semibold mb-1">Emparejamiento Térmico + Garage 61</p>
            <h1 class="text-2xl sm:text-3xl font-bold text-white">Biblioteca de Vueltas de Referencia</h1>
            <p class="text-sm text-zinc-400 mt-1">
                Importa vueltas o análisis desde <strong>Garage 61</strong>, sube archivos <code class="text-zinc-200">.ibt</code>/<code class="text-zinc-200">.json</code> o deja que el <strong>Autopilot Progresivo</strong> las descargue mientras conduces.
            </p>
        </div>

        <!-- Garage 61 Import & Search Bar (Option A) -->
        <div class="mb-8 rounded-2xl bg-zinc-900 border border-zinc-800 p-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
                <div>
                    <h2 class="text-lg font-semibold text-white">Importador Directo de Garage 61</h2>
                    <p class="text-xs text-zinc-400">Pega el enlace de una vuelta o análisis compartido de Garage 61, o busca vueltas con telemetría abierta por temperatura.</p>
                </div>
                <Link v-if="!garage61Connected" href="/settings" class="text-xs px-3 py-1.5 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-300 font-medium">
                    Conectar Token de Garage 61 →
                </Link>
            </div>

            <form @submit.prevent="submitG61Url" class="flex flex-col sm:flex-row gap-3 mb-6">
                <input
                    v-model="g61UrlForm.g61_input"
                    type="text"
                    required
                    placeholder="Pega enlace https://garage61.net/app/laps/... o URL de análisis (?laps=id1,id2) o ID directo"
                    class="flex-1 rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500"
                />
                <button type="submit" :disabled="g61UrlForm.processing || !garage61Connected" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 disabled:opacity-50 text-zinc-950 font-semibold text-sm cursor-pointer">
                    Importar Enlace / Análisis
                </button>
            </form>
            <p v-if="g61UrlForm.errors.g61_input" class="text-xs text-rose-400 -mt-4 mb-4">{{ g61UrlForm.errors.g61_input }}</p>

            <!-- Interactive Search -->
            <div class="border-t border-zinc-800 pt-5">
                <div class="grid sm:grid-cols-4 gap-3 items-end">
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1">Circuito Curado</label>
                        <select v-model="searchTrackId" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white">
                            <option v-for="t in tracks" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1">Coche iRacing</label>
                        <input v-model="searchCarName" type="text" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white" />
                    </div>
                    <div>
                        <label class="block text-xs text-zinc-400 mb-1">Asfalto objetivo °C (±3 °C)</label>
                        <input v-model="searchTemp" type="number" step="0.5" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white" />
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-1.5 text-xs text-zinc-300 cursor-pointer">
                            <input v-model="searchOnlyTeam" type="checkbox" class="rounded bg-zinc-950 border-zinc-700" />
                            Equipo
                        </label>
                        <button type="button" @click="runG61Search" :disabled="searchingG61 || !garage61Connected" class="flex-1 py-2 px-3 rounded-xl bg-zinc-800 hover:bg-zinc-700 disabled:opacity-50 text-xs font-semibold text-white cursor-pointer">
                            {{ searchingG61 ? 'Buscando...' : 'Buscar en G61' }}
                        </button>
                    </div>
                </div>

                <p v-if="g61SearchError" class="text-xs text-rose-400 mt-3">{{ g61SearchError }}</p>

                <div v-if="g61Results.length" class="mt-4 divide-y divide-zinc-800 rounded-xl border border-zinc-800 bg-zinc-950/60">
                    <div v-for="item in g61Results" :key="item.id" class="px-4 py-3 flex items-center justify-between text-xs">
                        <div>
                            <span class="font-semibold text-white">{{ item.driver_name || item.id }}</span>
                            <span class="text-zinc-400 ml-2">· Asfalto {{ item.track_temp_c }} °C · Aire {{ item.air_temp_c }} °C</span>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="font-mono font-bold text-emerald-400">{{ formatLapTime(item.lap_time_ms) }}</span>
                            <button @click="importFoundLap(item)" class="px-3 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30 font-medium cursor-pointer">
                                + Importar CSV
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid lg:grid-cols-3 gap-6">
            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-6 h-fit">
                <h2 class="font-semibold text-white mb-1">Subir Archivo Local (.ibt / .json)</h2>
                <p class="text-xs text-zinc-400 mb-4">Extraemos coche, circuito, mejor vuelta limpia y temperatura directamente del archivo.</p>

                <form @submit.prevent="submitUpload" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Archivo de telemetría (.ibt o .json)</label>
                        <input type="file" accept=".ibt,.json" required @change="onFileChange" class="w-full text-xs text-zinc-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-zinc-800 file:text-zinc-200 hover:file:bg-zinc-700" />
                        <p v-if="uploadForm.errors.telemetry_file" class="text-xs text-rose-400 mt-1">{{ uploadForm.errors.telemetry_file }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Etiqueta identificativa (opcional)</label>
                        <input v-model="uploadForm.label" type="text" placeholder="Ej. Setup Qualy Tarde Calurosa" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-400 mb-1">Temp. Pista °C (opc.)</label>
                            <input v-model="uploadForm.track_temp_override" type="number" step="0.1" placeholder="Auto" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-400 mb-1">Temp. Aire °C (opc.)</label>
                            <input v-model="uploadForm.air_temp_override" type="number" step="0.1" placeholder="Auto" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500" />
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-xs text-zinc-300 cursor-pointer">
                        <input v-model="uploadForm.is_wet" type="checkbox" class="rounded border-zinc-700 bg-zinc-950 text-emerald-500" />
                        Pista declarada en mojado / lluvia
                    </label>

                    <button type="submit" :disabled="uploadForm.processing" class="w-full py-2.5 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-white font-semibold text-sm transition cursor-pointer">
                        Subir Archivo Local
                    </button>
                </form>
            </div>

            <div class="lg:col-span-2 rounded-2xl bg-zinc-900 border border-zinc-800 overflow-hidden">
                <div class="px-6 py-4 border-b border-zinc-800">
                    <h2 class="font-semibold text-white">Referencias Guardadas ({{ references.length }})</h2>
                </div>

                <div class="divide-y divide-zinc-800/80">
                    <div v-for="ref in references" :key="ref.id" class="px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-white">{{ ref.track?.name }}</span>
                                <span v-if="ref.source === 'garage61_autopilot'" class="text-[11px] px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                                    G61 Autopilot
                                </span>
                                <span v-else-if="ref.source === 'garage61_manual'" class="text-[11px] px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                    G61 Importada
                                </span>
                                <span v-else :class="ref.is_owned ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-zinc-800 text-zinc-400 border-zinc-700'" class="text-[11px] px-2 py-0.5 rounded-full border">
                                    {{ ref.is_owned ? 'Propia' : 'Base' }}
                                </span>
                                <span v-if="ref.is_wet" class="text-[11px] px-2 py-0.5 rounded-full bg-sky-500/20 text-sky-300 border border-sky-500/30">Mojado</span>
                            </div>
                            <div class="text-xs text-zinc-400 mt-1">
                                {{ ref.car_name }} · <span class="text-zinc-300">{{ ref.label }}</span>
                            </div>
                            <div class="text-xs text-zinc-500 mt-0.5">
                                Asfalto: <strong class="text-amber-300">{{ ref.track_temp_c }} °C</strong> · Aire: {{ ref.air_temp_c }} °C
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <div class="font-mono text-base font-bold text-emerald-400">{{ formatLapTime(ref.lap_time_ms) }}</div>
                            <button v-if="ref.is_owned" @click="removeRef(ref.id)" class="text-xs text-rose-400 hover:text-rose-300 px-2 py-1 rounded border border-rose-500/30 cursor-pointer">
                                Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
