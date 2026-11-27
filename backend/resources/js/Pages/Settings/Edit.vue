<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    profile: Object,
    tokens: Array,
    apiBaseUrl: String,
});

const page = usePage();
const newPlainToken = computed(() => page.props.flash?.plain_api_token);

const profileForm = useForm({
    name: props.profile.name || '',
    driver_callsign: props.profile.driver_callsign || '',
    callsign_phonetic: props.profile.callsign_phonetic || '',
});

const g61Form = useForm({
    garage61_token: '',
    clear_token: false,
    autopilot_enabled: props.profile.autopilot_enabled ?? true,
    autopilot_mode: props.profile.autopilot_mode || 'progressive',
    autopilot_delta_ms: props.profile.autopilot_delta_ms || 1000,
});

const tokenForm = useForm({
    token_name: 'PC Simulador Windows',
});

function saveProfile() {
    profileForm.put('/settings/profile');
}

function saveGarage61() {
    g61Form.put('/settings/garage61', {
        onSuccess: () => {
            g61Form.garage61_token = '';
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

        <div class="grid lg:grid-cols-2 gap-8 mb-8">
            <!-- Driver Callsign & Voice Personalization -->
            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-6">
                <h2 class="text-lg font-semibold text-white mb-1">Identidad en Radio (Callsign)</h2>
                <p class="text-xs text-zinc-400 mb-5">Tu ingeniero se dirigirá a ti por este nombre tanto en los avisos pre-frenada como en el Informe PRO.</p>

                <form @submit.prevent="saveProfile" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Nombre completo</label>
                        <input v-model="profileForm.name" type="text" required class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">¿Cómo te llama el Coach? (Callsign)</label>
                        <input v-model="profileForm.driver_callsign" type="text" placeholder="Ej. Carlos" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                        <p class="text-[11px] text-zinc-500 mt-1">Ejemplo: <em>“{{ profileForm.driver_callsign || profile.effective_callsign }}, en La Source retrasa diez metros la frenada.”</em></p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">Guía fonética para voz IA (opcional)</label>
                        <input v-model="profileForm.callsign_phonetic" type="text" placeholder="Solo si usas un apodo extranjero o siglas" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                    </div>

                    <button type="submit" :disabled="profileForm.processing" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold text-sm transition cursor-pointer">
                        Guardar Preferencias de Radio
                    </button>
                </form>
            </div>

            <!-- Garage 61 API + Progressive Autopilot (Options A + C) -->
            <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-6">
                <div class="flex items-center justify-between mb-1">
                    <h2 class="text-lg font-semibold text-white">Garage 61 &amp; Autopilot Progresivo</h2>
                    <span :class="profile.garage61_connected ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-zinc-800 text-zinc-400 border-zinc-700'" class="text-[11px] px-2.5 py-0.5 rounded-full border font-medium">
                        {{ profile.garage61_connected ? (profile.garage61_auth_type === 'oauth' ? 'Conectado vía OAuth2' : 'Token Personal Conectado') : 'Sin Conectar' }}
                    </span>
                </div>
                <p class="text-xs text-zinc-400 mb-4">
                    Vincula tu cuenta en 1 clic con OAuth2 (ideal para ti y tus compañeros de equipo) o usa un Personal Access Token manual.
                </p>

                <!-- 1-Click OAuth2 Button -->
                <div class="mb-4">
                    <a
                        v-if="profile.garage61_oauth_available"
                        href="/settings/garage61/redirect"
                        class="w-full inline-flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-bold text-sm transition"
                    >
                        <span>{{ profile.garage61_connected && profile.garage61_auth_type === 'oauth' ? 'Reconectar cuenta de Garage 61 (OAuth2)' : 'Conectar mi cuenta de Garage 61 (1 Clic)' }}</span>
                    </a>
                    <div v-else class="rounded-xl border border-zinc-800 bg-zinc-950/60 px-3.5 py-2.5 text-[11px] text-zinc-400">
                        Botón OAuth2 listo: se activará automáticamente en cuanto añadas <code class="text-zinc-200">GARAGE61_CLIENT_ID</code> y <code class="text-zinc-200">GARAGE61_CLIENT_SECRET</code> en el <code class="text-zinc-200">.env</code> (Callback: <code class="text-emerald-400">{{ profile.garage61_callback_url }}</code>).
                    </div>
                </div>

                <div v-if="profile.garage61_status" class="mb-4 rounded-xl border border-zinc-800 bg-zinc-950/70 p-3 text-xs space-y-1">
                    <div v-if="profile.garage61_status.error" class="text-rose-400">{{ profile.garage61_status.error }}</div>
                    <template v-else>
                        <div class="text-zinc-300">Piloto G61: <strong class="text-white">{{ profile.garage61_status.driver }}</strong> · Plan: <span class="uppercase text-emerald-400">{{ profile.garage61_status.plan }}</span></div>
                        <div class="text-zinc-400">Equipos: <span class="text-zinc-200">{{ profile.garage61_status.teams?.join(', ') || 'Ninguno' }}</span></div>
                        <div class="text-zinc-400">
                            Permisos activos en token:
                            <span v-if="profile.garage61_status.permissions?.length" class="font-mono text-emerald-300">{{ profile.garage61_status.permissions.join(', ') }}</span>
                            <span v-else class="text-amber-300 font-medium">Solo lectura básica (pendiente que Garage 61 active driving_data / analyses)</span>
                        </div>
                    </template>
                </div>

                <form @submit.prevent="saveGarage61" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-zinc-300 mb-1">
                            {{ profile.garage61_connected ? 'Reemplazar Personal Access Token (dejar vacío para mantener)' : 'Personal Access Token de Garage 61' }}
                        </label>
                        <input v-model="g61Form.garage61_token" type="password" placeholder="Pega aquí tu token de Garage 61..." class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500" />
                        <p v-if="g61Form.errors.garage61_token" class="text-xs text-rose-400 mt-1">{{ g61Form.errors.garage61_token }}</p>
                    </div>

                    <label class="flex items-center gap-2.5 text-sm text-zinc-200 cursor-pointer">
                        <input v-model="g61Form.autopilot_enabled" type="checkbox" class="rounded border-zinc-700 bg-zinc-950 text-emerald-500" />
                        <span>Activar <strong>Autopilot en Pista</strong> (descarga referencia térmica automáticamente)</span>
                    </label>

                    <div class="grid sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-400 mb-1">Estrategia de Objetivo</label>
                            <select v-model="g61Form.autopilot_mode" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500">
                                <option value="progressive">Escalón Progresivo (Recomendado)</option>
                                <option value="team">Solo Compañeros de mi Equipo G61</option>
                                <option value="alien">Récord Top Alien Disponible</option>
                            </select>
                        </div>
                        <div v-if="g61Form.autopilot_mode === 'progressive'">
                            <label class="block text-xs font-medium text-zinc-400 mb-1">Salto objetivo (ms más rápido)</label>
                            <input v-model="g61Form.autopilot_delta_ms" type="number" step="100" min="400" max="3000" class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-2 text-sm text-white focus:outline-none focus:border-emerald-500" />
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <button type="submit" :disabled="g61Form.processing" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold text-sm transition cursor-pointer">
                            Guardar Integración G61
                        </button>
                        <button v-if="profile.garage61_connected" type="button" @click="g61Form.clear_token = true; saveGarage61()" class="text-xs text-rose-400 hover:underline cursor-pointer">
                            Desvincular Token
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sanctum Token Management for Go Client -->
        <div class="rounded-2xl bg-zinc-900 border border-zinc-800 p-6">
            <h2 class="text-lg font-semibold text-white mb-1">Conexión con <code class="text-emerald-400">pacefriend-client.exe</code></h2>
            <p class="text-xs text-zinc-400 mb-5">Genera un token personal para autenticar el binario ultraligero de Go en tu PC de simulación.</p>

            <form @submit.prevent="generateToken" class="flex max-w-lg gap-2 mb-5">
                <input v-model="tokenForm.token_name" type="text" required class="flex-1 rounded-xl bg-zinc-950 border border-zinc-800 px-3.5 py-2 text-sm text-white focus:outline-none focus:border-emerald-500" />
                <button type="submit" :disabled="tokenForm.processing" class="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-zinc-950 font-semibold text-sm cursor-pointer">
                    Generar Token
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
