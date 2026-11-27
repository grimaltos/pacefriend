<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    tracks: Array,
});

const form = useForm({
    id: null,
    slug: '',
    name: '',
    length_km: 5.0,
    corners: [
        { turn: 1, name: 'Curva 1', brake_pct: 0.10, apex_pct: 0.13, exit_pct: 0.16 },
    ],
});

function loadTrack(t) {
    form.id = t.id;
    form.slug = t.slug;
    form.name = t.name;
    form.length_km = t.length_km;
    form.corners = (t.corners || []).map((c) => ({ ...c }));
}

function resetNew() {
    form.id = null;
    form.slug = '';
    form.name = '';
    form.length_km = 5.0;
    form.corners = [
        { turn: 1, name: 'Curva 1', brake_pct: 0.10, apex_pct: 0.13, exit_pct: 0.16 },
    ];
}

function addCorner() {
    const nextTurn = form.corners.length ? Number(form.corners[form.corners.length - 1].turn) + 1 : 1;
    form.corners.push({ turn: nextTurn, name: `Curva ${nextTurn}`, brake_pct: 0.50, apex_pct: 0.53, exit_pct: 0.56 });
}

function removeCorner(idx) {
    if (form.corners.length > 1) {
        form.corners.splice(idx, 1);
    }
}

function submit() {
    form.post('/tracks', {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Catálogo de Circuitos" />
    <AppLayout>
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-8">
            <div>
                <p class="text-xs uppercase tracking-widest text-emerald-400 font-semibold mb-1">Unidades Tácticas</p>
                <h1 class="text-2xl sm:text-3xl font-bold text-white">Gestor de Circuitos y Curvas</h1>
                <p class="text-sm text-zinc-400 mt-1">
                    Añade nuevos trazados de iRacing o ajusta los nombres y porcentajes (<code class="text-zinc-200">0.000 .. 0.999</code>) que pronuncia el coach.
                </p>
            </div>
            <button @click="resetNew" class="px-4 py-2 rounded-xl bg-zinc-900 border border-zinc-700 hover:border-emerald-500 text-sm font-medium text-white cursor-pointer">
                + Nuevo Circuito
            </button>
        </div>

        <div class="grid lg:grid-cols-5 gap-6">
            <!-- Existing Curated Tracks -->
            <div class="lg:col-span-2 rounded-2xl bg-zinc-900 border border-zinc-800 overflow-hidden h-fit">
                <div class="px-5 py-3.5 border-b border-zinc-800 font-semibold text-sm text-white">
                    Circuitos Activos ({{ tracks.length }})
                </div>
                <div class="divide-y divide-zinc-800/80 max-h-[620px] overflow-y-auto">
                    <button
                        v-for="t in tracks"
                        :key="t.id"
                        type="button"
                        @click="loadTrack(t)"
                        class="w-full text-left px-5 py-3.5 hover:bg-zinc-800/50 transition flex items-center justify-between cursor-pointer"
                        :class="form.id === t.id ? 'bg-emerald-500/10 border-l-2 border-emerald-400' : ''"
                    >
                        <div>
                            <div class="font-semibold text-sm text-white">{{ t.name }}</div>
                            <div class="text-xs text-zinc-500 font-mono">{{ t.slug }} · {{ t.length_km }} km</div>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-zinc-800 text-zinc-300">
                            {{ t.corners?.length || 0 }} zonas
                        </span>
                    </button>
                </div>
            </div>

            <!-- Visual Corner Editor -->
            <div class="lg:col-span-3 rounded-2xl bg-zinc-900 border border-zinc-800 p-6">
                <h2 class="text-lg font-semibold text-white mb-4">
                    {{ form.id ? `Editando: ${form.name}` : 'Crear Nuevo Circuito' }}
                </h2>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block text-xs text-zinc-400 mb-1">Nombre oficial del circuito</label>
                            <input v-model="form.name" type="text" required placeholder="Ej. Road America" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500" />
                        </div>
                        <div>
                            <label class="block text-xs text-zinc-400 mb-1">Longitud (km)</label>
                            <input v-model="form.length_km" type="number" step="0.001" required class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-zinc-400 mb-1">Slug de iRacing (<code class="text-zinc-300">TrackName</code> en YAML, ej. <code class="text-zinc-300">road-america-full</code>)</label>
                        <input v-model="form.slug" type="text" required placeholder="road-america-full" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm font-mono text-white focus:outline-none focus:border-emerald-500" />
                    </div>

                    <div class="border-t border-zinc-800 pt-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-300">Zonas Tácticas de Curva</span>
                            <button type="button" @click="addCorner" class="text-xs text-emerald-400 hover:underline font-semibold cursor-pointer">
                                + Añadir Zona / Curva
                            </button>
                        </div>

                        <div class="space-y-2.5">
                            <div v-for="(c, idx) in form.corners" :key="idx" class="grid grid-cols-12 gap-2 items-center rounded-xl bg-zinc-950/80 border border-zinc-800 p-2.5 text-xs">
                                <div class="col-span-2 sm:col-span-1">
                                    <label class="block text-[10px] text-zinc-500">#</label>
                                    <input v-model.number="c.turn" type="number" min="1" required class="w-full bg-zinc-900 border border-zinc-800 rounded px-1.5 py-1 text-white font-mono" />
                                </div>
                                <div class="col-span-10 sm:col-span-4">
                                    <label class="block text-[10px] text-zinc-500">Nombre por Radio</label>
                                    <input v-model="c.name" type="text" required placeholder="Carousel" class="w-full bg-zinc-900 border border-zinc-800 rounded px-2 py-1 text-white" />
                                </div>
                                <div class="col-span-4 sm:col-span-2">
                                    <label class="block text-[10px] text-zinc-500">Frenada</label>
                                    <input v-model.number="c.brake_pct" type="number" step="0.001" min="0" max="0.999" required class="w-full bg-zinc-900 border border-zinc-800 rounded px-1.5 py-1 text-white font-mono" />
                                </div>
                                <div class="col-span-4 sm:col-span-2">
                                    <label class="block text-[10px] text-zinc-500">Vértice</label>
                                    <input v-model.number="c.apex_pct" type="number" step="0.001" min="0" max="0.999" required class="w-full bg-zinc-900 border border-zinc-800 rounded px-1.5 py-1 text-white font-mono" />
                                </div>
                                <div class="col-span-3 sm:col-span-2">
                                    <label class="block text-[10px] text-zinc-500">Salida</label>
                                    <input v-model.number="c.exit_pct" type="number" step="0.001" min="0" max="0.999" required class="w-full bg-zinc-900 border border-zinc-800 rounded px-1.5 py-1 text-white font-mono" />
                                </div>
                                <div class="col-span-1 text-right pt-3">
                                    <button type="button" @click="removeCorner(idx)" class="text-rose-400 hover:text-rose-300 cursor-pointer">✕</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" :disabled="form.processing" class="w-full py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold text-sm transition cursor-pointer">
                        Guardar Circuito en PaceFriend
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
