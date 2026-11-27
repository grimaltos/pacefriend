<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    profile: Object,
    voiceCatalog: Object,
    tokens: Array,
    apiBaseUrl: String,
    clientExeAvailable: Boolean,
});

const page = usePage();
const newPlainToken = computed(() => page.props.flash?.plain_api_token);

const profileForm = useForm({
    name: props.profile.name || '',
    driver_callsign: props.profile.driver_callsign || '',
    callsign_phonetic: props.profile.callsign_phonetic || '',
});

const radioDefaults = props.profile.radio || {};
const radioForm = useForm({
    voice_id: radioDefaults.voice_id || 'es-ES-AlvaroNeural',
    volume: radioDefaults.volume ?? 85,
    radio_beep: radioDefaults.radio_beep ?? true,
    pit_wall_fx: radioDefaults.pit_wall_fx ?? true,
    finish_callout_mode: radioDefaults.finish_callout_mode || 'full',
    min_clean_laps: radioDefaults.min_clean_laps || 3,
    fuel_alerts: radioDefaults.fuel_alerts ?? true,
    tyre_temp_alerts: radioDefaults.tyre_temp_alerts ?? true,
});

const g61Form = useForm({
    clear_token: false,
    autopilot_enabled: props.profile.autopilot_enabled ?? true,
    reference_scope: props.profile.reference_scope || 'team_then_public',
    autopilot_mode: props.profile.autopilot_mode || 'progressive',
    autopilot_delta_ms: props.profile.autopilot_delta_ms || 1000,
});

const tokenForm = useForm({
    token_name: 'PC Simulador Windows',
});

const previewLoading = ref(false);
const previewError = ref('');
let activeAudio = null;

function saveProfile() {
    profileForm.put('/settings/profile');
}

function saveRadio() {
    radioForm.put('/settings/radio');
}

async function testRadioPreview() {
    previewLoading.value = true;
    previewError.value = '';
    try {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const xsrfCookie = document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1];

        const headers = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        };
        if (csrf) {
            headers['X-CSRF-TOKEN'] = csrf;
        } else if (xsrfCookie) {
            headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfCookie);
        }

        const resp = await fetch('/settings/radio/preview', {
            method: 'POST',
            headers,
            body: JSON.stringify({
                voice_id: radioForm.voice_id,
                volume: Number(radioForm.volume),
                radio_beep: Boolean(radioForm.radio_beep),
                pit_wall_fx: Boolean(radioForm.pit_wall_fx),
            }),
        });

        if (!resp.ok) {
            throw new Error(`HTTP ${resp.status}`);
        }
        const data = await resp.json();
        if (activeAudio) {
            activeAudio.pause();
        }
        activeAudio = new Audio(data.url);
        activeAudio.volume = Math.max(0, Math.min(1, Number(radioForm.volume) / 100));
        await activeAudio.play();
    } catch (e) {
        previewError.value = 'No se pudo reproducir la prueba de radio (' + e.message + ').';
    } finally {
        previewLoading.value = false;
    }
}

function saveGarage61() {
    g61Form.put('/settings/garage61', {
        onSuccess: () => {
            g61Form.clear_token = false;
        },
    });
}

function generateToken() {
    tokenForm.post('/settings/tokens');
}

function downloadConfigJson(token) {
    const payload = JSON.stringify({
        api_url: props.apiBaseUrl,
        api_token: token,
        mute: false,
    }, null, 2);
    const blob = new Blob([payload], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'pacefriend.json';
    a.click();
    URL.revokeObjectURL(url);
}

function revoke(id) {
    router.delete(`/settings/tokens/${id}`);
}
</script>

<template>
    <Head title="Configuración de Piloto & Cliente" />
    <AppLayout>
        <div class="mb-8">
            <p class="text-xs uppercase tracking-widest text-emerald-400 font-semibold mb-1">Personalización de Radio, Garage 61 &amp; Puente Go</p>
            <h1 class="text-2xl sm:text-3xl font-bold text-white">Ajustes de Piloto y Conexión Local</h1>
        </div>

        <!-- Full-width Engineer Voice, FX & On-Track Callouts -->
        <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-6 mb-8">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5 pb-4 border-b border-zinc-800">
                <div>
                    <h2 class="text-lg font-semibold text-white">Radio del Ingeniero, Voz Neural y Avisos en Pista</h2>
                    <p class="text-xs text-zinc-400">
                        Elige la voz de tu ingeniero, efectos de muro de boxes, volumen independiente y qué información te canta durante la sesión.
                    </p>
                </div>
                <button
                    type="button"
                    :disabled="previewLoading"
                    @click="testRadioPreview"
                    class="px-4 py-2.5 rounded-xl bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-emerald-300 font-semibold text-xs transition cursor-pointer shrink-0"
                >
                    {{ previewLoading ? 'Sintetizando radio...' : '🔊 Escuchar prueba de radio' }}
                </button>
            </div>

            <p v-if="previewError" class="mb-4 text-xs text-rose-400">{{ previewError }}</p>

            <form @submit.prevent="saveRadio" class="space-y-6">
                <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-5">
                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Voz del Ingeniero (Edge Neural)</label>
                        <select v-model="radioForm.voice_id" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500">
                            <option v-for="(label, id) in voiceCatalog" :key="id" :value="id">
                                {{ label }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="text-xs font-medium text-zinc-300">Volumen de Radio</label>
                            <span class="text-xs font-bold text-emerald-400">{{ radioForm.volume }}%</span>
                        </div>
                        <input
                            v-model.number="radioForm.volume"
                            type="range"
                            min="0"
                            max="100"
                            step="5"
                            class="w-full accent-emerald-500 cursor-pointer mt-2"
                        />
                        <p class="text-[11px] text-zinc-500 mt-1">Se aplica en vivo al cliente Windows sin tocar el volumen del motor de iRacing.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Aviso al cruzar por meta</label>
                        <select v-model="radioForm.finish_callout_mode" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500">
                            <option value="full">Completo (tiempos + refuerzo positivo)</option>
                            <option value="improvements">Solo mejoras y refuerzo positivo</option>
                            <option value="off">Desactivado (silencio en recta de meta)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Vueltas limpias antes de corregir curvas</label>
                        <select v-model.number="radioForm.min_clean_laps" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500">
                            <option :value="2">2 vueltas limpias (Feedback rápido)</option>
                            <option :value="3">3 vueltas limpias (Equilibrado · Recomendado)</option>
                            <option :value="4">4 vueltas limpias</option>
                            <option :value="5">5 vueltas limpias (Solo hábitos muy marcados)</option>
                        </select>
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-1">
                    <label class="flex items-start gap-2.5 rounded-xl bg-zinc-950/70 border border-zinc-800 p-3.5 cursor-pointer">
                        <input v-model="radioForm.radio_beep" type="checkbox" class="mt-0.5 rounded border-zinc-700 bg-zinc-950 text-emerald-500" />
                        <div class="text-xs">
                            <div class="font-semibold text-zinc-200">Bip de apertura/cierre (PTT)</div>
                            <div class="text-zinc-500 mt-0.5">Doble tono corto antes de hablar y clic al soltar radio.</div>
                        </div>
                    </label>

                    <label class="flex items-start gap-2.5 rounded-xl bg-zinc-950/70 border border-zinc-800 p-3.5 cursor-pointer">
                        <input v-model="radioForm.pit_wall_fx" type="checkbox" class="mt-0.5 rounded border-zinc-700 bg-zinc-950 text-emerald-500" />
                        <div class="text-xs">
                            <div class="font-semibold text-zinc-200">Efecto radio muro de boxes</div>
                            <div class="text-zinc-500 mt-0.5">Ecualización de casco y compresión para cortar sobre el motor.</div>
                        </div>
                    </label>

                    <label class="flex items-start gap-2.5 rounded-xl bg-zinc-950/70 border border-zinc-800 p-3.5 cursor-pointer">
                        <input v-model="radioForm.fuel_alerts" type="checkbox" class="mt-0.5 rounded border-zinc-700 bg-zinc-950 text-emerald-500" />
                        <div class="text-xs">
                            <div class="font-semibold text-zinc-200">Combustible vs Target y Reserva</div>
                            <div class="text-zinc-500 mt-0.5">Compara peso/gasto frente a la referencia y avisa a 3 y 1 vueltas.</div>
                        </div>
                    </label>

                    <label class="flex items-start gap-2.5 rounded-xl bg-zinc-950/70 border border-zinc-800 p-3.5 cursor-pointer">
                        <input v-model="radioForm.tyre_temp_alerts" type="checkbox" class="mt-0.5 rounded border-zinc-700 bg-zinc-950 text-emerald-500" />
                        <div class="text-xs">
                            <div class="font-semibold text-zinc-200">Avisos de Neumáticos</div>
                            <div class="text-zinc-500 mt-0.5">Precaución de goma fría en out-lap y lectura real al parar en boxes.</div>
                        </div>
                    </label>
                </div>

                <div>
                    <button type="submit" :disabled="radioForm.processing" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold text-sm transition cursor-pointer">
                        Guardar Configuración de Radio
                    </button>
                </div>
            </form>
        </div>

        <div class="grid lg:grid-cols-2 gap-8 mb-8">
            <!-- Driver Callsign & Voice Personalization -->
            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-6">
                <h2 class="text-lg font-semibold text-white mb-1">Identidad en Radio (Callsign)</h2>
                <p class="text-xs text-zinc-400 mb-5">Tu ingeniero se dirigirá a ti por este nombre tanto en el saludo inicial como en las frenadas y el Informe PRO.</p>

                <form @submit.prevent="saveProfile" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Nombre completo</label>
                        <input v-model="profileForm.name" type="text" required class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">¿Cómo te llama el Coach? (Callsign)</label>
                        <input v-model="profileForm.driver_callsign" type="text" placeholder="Ej. Vicent" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                        <p class="text-[11px] text-zinc-500 mt-1">Ejemplo: <em>“{{ profileForm.driver_callsign || profile.effective_callsign }}, en La Source retrasa diez metros la frenada.”</em></p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Guía fonética para voz IA (opcional)</label>
                        <input v-model="profileForm.callsign_phonetic" type="text" placeholder="Solo si usas un apodo extranjero o siglas" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    </div>

                    <button type="submit" :disabled="profileForm.processing" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold text-sm transition cursor-pointer">
                        Guardar Identidad
                    </button>
                </form>
            </div>

            <!-- Garage 61 OAuth2 + Progressive Autopilot -->
            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-6">
                <div class="flex items-center justify-between mb-1">
                    <h2 class="text-lg font-semibold text-white">Garage 61 &amp; Autopilot Progresivo</h2>
                    <span :class="profile.garage61_connected ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-zinc-800 text-zinc-400 border-zinc-700'" class="text-[11px] px-2.5 py-0.5 rounded-full border font-medium">
                        {{ profile.garage61_connected ? 'Cuenta Vinculada' : 'Sin Vincular' }}
                    </span>
                </div>
                <p class="text-xs text-zinc-400 mb-4">
                    Vincula tu cuenta de Garage 61 en un clic para sincronizar tus vueltas, tus análisis y las referencias de tu equipo.
                </p>

                <div class="mb-4">
                    <a
                        v-if="profile.garage61_oauth_available"
                        href="/settings/garage61/redirect"
                        class="w-full inline-flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl font-bold text-sm transition"
                        :class="profile.garage61_connected ? 'bg-zinc-800 hover:bg-zinc-700 text-zinc-200 border border-zinc-700' : 'bg-emerald-500 hover:bg-emerald-400 text-zinc-950'"
                    >
                        <span>{{ profile.garage61_connected ? 'Reconectar cuenta de Garage 61' : 'Conectar mi cuenta de Garage 61' }}</span>
                    </a>
                    <div v-else class="rounded-xl border border-zinc-800 bg-zinc-950/60 px-3.5 py-2.5 text-[11px] text-zinc-400">
                        Configura <code class="text-zinc-200">GARAGE61_CLIENT_ID</code> y <code class="text-zinc-200">GARAGE61_CLIENT_SECRET</code> en el servidor para habilitar OAuth2.
                    </div>
                </div>

                <div v-if="profile.garage61_status" class="mb-4 rounded-xl border border-zinc-800 bg-zinc-950/70 p-3 text-xs space-y-1">
                    <div v-if="profile.garage61_status.error" class="text-rose-400">{{ profile.garage61_status.error }}</div>
                    <template v-else>
                        <div class="text-zinc-300">Piloto G61: <strong class="text-white">{{ profile.garage61_status.driver }}</strong> · Plan: <span class="uppercase text-emerald-400">{{ profile.garage61_status.plan }}</span></div>
                        <div class="text-zinc-400">Equipos: <span class="text-zinc-200">{{ profile.garage61_status.teams?.join(', ') || 'Ninguno' }}</span></div>
                    </template>
                </div>

                <form @submit.prevent="saveGarage61" class="space-y-4">
                    <label class="flex items-center gap-2.5 text-sm text-zinc-200 cursor-pointer">
                        <input v-model="g61Form.autopilot_enabled" type="checkbox" class="rounded border-zinc-700 bg-zinc-950 text-emerald-500" />
                        <span>Activar <strong>Autopilot en Pista</strong> (descarga referencia térmica automáticamente)</span>
                    </label>

                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">¿De qué pilotos quieres tomar referencias? (Ámbito)</label>
                        <select v-model="g61Form.reference_scope" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500">
                            <option value="team_then_public">Mi Equipo primero; Comunidad Pública si falta a esa temp. (Cascada · Recomendado)</option>
                            <option value="team_only">Solo miembros de mi Equipo G61 y mis vueltas</option>
                            <option value="public_all">Toda la Comunidad Pública + Equipo</option>
                        </select>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-400 mb-1">Estrategia de Ritmo Objetivo</label>
                            <select v-model="g61Form.autopilot_mode" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500">
                                <option value="progressive">Escalón Progresivo (Recomendado)</option>
                                <option value="fastest">Vuelta más rápida disponible (Top)</option>
                            </select>
                        </div>
                        <div v-if="g61Form.autopilot_mode === 'progressive'">
                            <label class="block text-xs font-medium text-zinc-400 mb-1">Salto objetivo (ms más rápido)</label>
                            <input v-model="g61Form.autopilot_delta_ms" type="number" step="100" min="400" max="3000" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500" />
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <button type="submit" :disabled="g61Form.processing" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold text-sm transition cursor-pointer">
                            Guardar Ajustes Autopilot
                        </button>
                        <button v-if="profile.garage61_connected" type="button" @click="g61Form.clear_token = true; saveGarage61()" class="text-xs text-rose-400 hover:underline cursor-pointer">
                            Desvincular cuenta G61
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Windows Client Download & Sanctum Token Management -->
        <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6 pb-6 border-b border-zinc-800">
                <div>
                    <h2 class="text-lg font-semibold text-white mb-1">App para Windows (<code class="text-emerald-400">pacefriend-client.exe</code>)</h2>
                    <p class="text-xs text-zinc-400">
                        Descarga el paquete ZIP listo para usar: ya incluye el ejecutable de bandeja de Windows y tu archivo <code class="text-zinc-200">pacefriend.json</code> preconfigurado con tu cuenta.
                    </p>
                </div>

                <div v-if="clientExeAvailable" class="flex flex-wrap items-center gap-3 shrink-0">
                    <a
                        href="/settings/client-bundle"
                        class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold text-sm transition shadow-lg shadow-emerald-500/10"
                    >
                        ⬇ Descargar PaceFriend Windows (.zip configurado)
                    </a>
                    <a
                        href="/settings/client-exe"
                        class="px-3.5 py-2.5 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-zinc-200 text-xs font-medium transition"
                    >
                        Solo .exe
                    </a>
                </div>
            </div>

            <h3 class="text-sm font-semibold text-zinc-200 mb-3">Tokens de Dispositivos Vinculados</h3>

            <form @submit.prevent="generateToken" class="flex max-w-lg gap-2 mb-5">
                <input v-model="tokenForm.token_name" type="text" required class="flex-1 rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2 text-sm text-white focus:outline-none focus:border-emerald-500" />
                <button type="submit" :disabled="tokenForm.processing" class="px-4 py-2 rounded-xl bg-zinc-800 hover:bg-zinc-700 text-white font-semibold text-xs cursor-pointer">
                    Generar Token Manual
                </button>
            </form>

            <div v-if="newPlainToken" class="mb-6 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
                <div class="text-xs font-bold text-amber-300 mb-1">Copia tu token o descarga el archivo para la Tray App (solo se muestra una vez):</div>
                <code class="block text-xs bg-zinc-950 p-2.5 rounded-lg text-emerald-300 break-all select-all mb-3">{{ newPlainToken }}</code>
                <div class="flex flex-wrap items-center gap-3 mb-3">
                    <button type="button" @click="downloadConfigJson(newPlainToken)" class="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold text-xs cursor-pointer">
                        Descargar pacefriend.json (poner junto al .exe)
                    </button>
                    <span class="text-[11px] text-zinc-300">Con ese archivo al lado, basta hacer doble clic en <code>pacefriend-client.exe</code>.</span>
                </div>
                <div class="text-[11px] text-zinc-400 mb-1">O por consola:</div>
                <code class="block text-[11px] bg-zinc-950 p-2.5 rounded-lg text-zinc-200 break-all select-all">pacefriend-client.exe -api-url "{{ apiBaseUrl }}" -api-token "{{ newPlainToken }}"</code>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <div v-for="t in tokens" :key="t.id" class="flex items-center justify-between rounded-xl bg-zinc-950 border border-zinc-800 px-4 py-3 text-xs">
                    <div>
                        <div class="font-semibold text-zinc-200">{{ t.name }}</div>
                        <div class="text-zinc-500">{{ t.last_used_at ? `Último envío: ${t.last_used_at}` : 'Sin usar todavía' }}</div>
                    </div>
                    <button @click="revoke(t.id)" class="text-rose-400 hover:text-rose-300 cursor-pointer">Revocar</button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
