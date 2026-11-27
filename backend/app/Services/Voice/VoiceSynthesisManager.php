<?php

namespace App\Services\Voice;

use App\Models\VoiceClip;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class VoiceSynthesisManager
{
    private const SAMPLE_RATE = 24000;

    /**
     * Synthesize or retrieve a cached AI voice clip for a coaching radio callout,
     * applying optional Push-To-Talk radio beep and pit-wall helmet EQ.
     *
     * @param  array{voice_id?: string, radio_beep?: bool, pit_wall_fx?: bool}  $options
     * @return array{url: string, sha256: string}
     */
    public function ensureAudioClip(string $text, array $options = []): array
    {
        $configuredDriver = (string) config('pacefriend.voice.driver', 'edge');
        $radioBeep = (bool) ($options['radio_beep'] ?? true);
        $pitWallFx = (bool) ($options['pit_wall_fx'] ?? true);

        $edgeVoice = (string) ($options['voice_id'] ?? config('pacefriend.voice.edge.default_voice', 'es-ES-AlvaroNeural'));
        $allowedEdgeVoices = array_keys(config('pacefriend.voice.edge.voices', [
            'es-ES-AlvaroNeural' => 'Álvaro',
            'es-ES-XimenaNeural' => 'Ximena',
            'es-ES-ElviraNeural' => 'Elvira',
        ]));
        if (! in_array($edgeVoice, $allowedEdgeVoices, true)) {
            $edgeVoice = 'es-ES-AlvaroNeural';
        }

        $useElevenLabs = $configuredDriver === 'elevenlabs' && filled(config('pacefriend.voice.elevenlabs.api_key'));
        $effectiveDriver = $useElevenLabs
            ? 'elevenlabs'
            : ($configuredDriver === 'offline_test' ? 'local' : 'edge');

        $voiceKey = $useElevenLabs
            ? (string) config('pacefriend.voice.elevenlabs.voice_id', 'default')
            : ($effectiveDriver === 'edge' ? $edgeVoice : 'local-radio-synth');

        $compositeVoiceId = substr(
            "{$voiceKey}|b".($radioBeep ? '1' : '0').'|fx'.($pitWallFx ? '1' : '0'),
            0,
            96
        );

        $existing = VoiceClip::query()
            ->where('provider', $effectiveDriver)
            ->where('voice_id', $compositeVoiceId)
            ->where('text', $text)
            ->first();

        if ($existing !== null && Storage::disk('local')->exists($existing->disk_path)) {
            return [
                'url' => url("/api/v1/audio/{$existing->hash}"),
                'sha256' => $existing->hash,
            ];
        }

        if ($useElevenLabs) {
            $mp3Bytes = $this->fetchElevenLabsMp3($text, $voiceKey);
            if ($mp3Bytes !== null) {
                return $this->finalizeSpeechClip(
                    $mp3Bytes,
                    $text,
                    'elevenlabs',
                    $compositeVoiceId,
                    $radioBeep,
                    $pitWallFx
                );
            }
        }

        if ($effectiveDriver !== 'local') {
            $mp3Bytes = $this->synthesizeEdgeTtsMp3($text, $edgeVoice);
            if ($mp3Bytes !== null) {
                return $this->finalizeSpeechClip(
                    $mp3Bytes,
                    $text,
                    'edge',
                    $compositeVoiceId,
                    $radioBeep,
                    $pitWallFx
                );
            }
        }

        $fallbackVoiceId = substr('local-radio-synth|b'.($radioBeep ? '1' : '0'), 0, 96);
        return $this->synthesizeLocalDevelopmentWav($text, $fallbackVoiceId, $radioBeep);
    }

    /**
     * Synthesize neural Spanish speech via Microsoft Edge TTS CLI (`edge-tts`) at zero cost.
     */
    private function synthesizeEdgeTtsMp3(string $text, string $voiceId): ?string
    {
        $finder = new ExecutableFinder();
        $edgeBinary = $finder->find('edge-tts', null, ['/usr/local/bin', '/usr/bin']);
        if ($edgeBinary === null) {
            return null;
        }

        $tmpMp3 = tempnam(sys_get_temp_dir(), 'pf_edge_').'.mp3';
        $rate = (string) config('pacefriend.voice.edge.rate', '+4%');

        try {
            $process = new Process([
                $edgeBinary,
                '--voice',
                $voiceId,
                "--rate={$rate}",
                '--text',
                $text,
                '--write-media',
                $tmpMp3,
            ]);
            $process->setTimeout(14);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($tmpMp3) || filesize($tmpMp3) < 256) {
                Log::warning('Edge TTS synthesis failed, falling back to local WAV', [
                    'voice' => $voiceId,
                    'exit_code' => $process->getExitCode(),
                    'stderr' => trim($process->getErrorOutput()),
                ]);
                return null;
            }

            return (string) file_get_contents($tmpMp3);
        } catch (\Throwable $e) {
            Log::warning('Edge TTS exception, falling back to local WAV', [
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            if (is_file($tmpMp3)) {
                @unlink($tmpMp3);
            }
        }
    }

    /**
     * Call ElevenLabs Multilingual v2 API when explicitly configured.
     */
    private function fetchElevenLabsMp3(string $text, string $voiceId): ?string
    {
        try {
            $response = Http::withHeaders([
                'xi-api-key' => (string) config('pacefriend.voice.elevenlabs.api_key'),
                'Accept' => 'audio/mpeg',
            ])->timeout(12)->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}", [
                'text' => $text,
                'model_id' => (string) config('pacefriend.voice.elevenlabs.model_id', 'eleven_multilingual_v2'),
                'voice_settings' => [
                    'stability' => (float) config('pacefriend.voice.elevenlabs.stability', 0.55),
                    'similarity_boost' => (float) config('pacefriend.voice.elevenlabs.similarity_boost', 0.80),
                ],
            ]);

            if (! $response->successful()) {
                Log::warning('ElevenLabs TTS failed', ['status' => $response->status()]);
                return null;
            }

            return $response->body();
        } catch (\Throwable $e) {
            Log::warning('ElevenLabs TTS exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Convert synthesized MP3 speech into a 24 kHz 16-bit mono WAV (for instant zero-latency
     * SND_MEMORY RAM playback & volume scaling on Windows), applying pit-wall radio EQ and PTT beep.
     *
     * @return array{url: string, sha256: string}
     */
    private function finalizeSpeechClip(
        string $mp3Bytes,
        string $text,
        string $provider,
        string $compositeVoiceId,
        bool $radioBeep,
        bool $pitWallFx
    ): array {
        $pcm = $this->decodeMp3ToPcmWithFfmpeg($mp3Bytes, $pitWallFx);

        if ($pcm === null) {
            $contentHash = hash('sha256', $mp3Bytes);
            $diskPath = "audio-cache/{$contentHash}.mp3";
            Storage::disk('local')->put($diskPath, $mp3Bytes);

            VoiceClip::query()->updateOrCreate(
                ['hash' => $contentHash],
                [
                    'provider' => $provider,
                    'voice_id' => $compositeVoiceId,
                    'text' => $text,
                    'disk_path' => $diskPath,
                    'mime_type' => 'audio/mpeg',
                ]
            );

            return [
                'url' => url("/api/v1/audio/{$contentHash}"),
                'sha256' => $contentHash,
            ];
        }

        if ($pitWallFx) {
            $pcm = $this->mixSubtlePitCarrierTexture($pcm);
        }

        if ($radioBeep) {
            $pcm = $this->generatePttOpenBeep().$pcm.$this->generatePttCloseClick();
        }

        $wavBytes = $this->wrapPcmAsWav($pcm, self::SAMPLE_RATE);
        $contentHash = hash('sha256', $wavBytes);
        $durationMs = (int) round((strlen($pcm) / 2 / self::SAMPLE_RATE) * 1000);
        $diskPath = "audio-cache/{$contentHash}.wav";
        Storage::disk('local')->put($diskPath, $wavBytes);

        VoiceClip::query()->updateOrCreate(
            ['hash' => $contentHash],
            [
                'provider' => $provider,
                'voice_id' => $compositeVoiceId,
                'text' => $text,
                'disk_path' => $diskPath,
                'mime_type' => 'audio/wav',
                'duration_ms' => $durationMs,
            ]
        );

        return [
            'url' => url("/api/v1/audio/{$contentHash}"),
            'sha256' => $contentHash,
        ];
    }

    /**
     * Decode MP3 to raw 24 kHz 16-bit signed LE mono PCM via ffmpeg, optionally applying
     * motorsport pit-wall helmet bandpass + dynamic compression so voice cuts over engine roar.
     */
    private function decodeMp3ToPcmWithFfmpeg(string $mp3Bytes, bool $pitWallFx): ?string
    {
        $finder = new ExecutableFinder();
        $ffmpeg = $finder->find('ffmpeg', null, ['/usr/local/bin', '/usr/bin']);
        if ($ffmpeg === null) {
            return null;
        }

        $tmpIn = tempnam(sys_get_temp_dir(), 'pf_in_').'.mp3';
        $tmpOut = tempnam(sys_get_temp_dir(), 'pf_out_').'.pcm';
        file_put_contents($tmpIn, $mp3Bytes);

        $audioFilter = $pitWallFx
            ? 'highpass=f=310,lowpass=f=3450,equalizer=f=1650:t=q:w=1.2:g=4.5,acompressor=threshold=-18dB:ratio=4:attack=4:release=55:makeup=3dB,alimiter=limit=0.90'
            : 'alimiter=limit=0.95';

        try {
            $process = new Process([
                $ffmpeg,
                '-y',
                '-nostdin',
                '-loglevel',
                'error',
                '-i',
                $tmpIn,
                '-af',
                $audioFilter,
                '-ac',
                '1',
                '-ar',
                (string) self::SAMPLE_RATE,
                '-f',
                's16le',
                '-acodec',
                'pcm_s16le',
                $tmpOut,
            ]);
            $process->setTimeout(10);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($tmpOut) || filesize($tmpOut) < 100) {
                return null;
            }

            return (string) file_get_contents($tmpOut);
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
        }
    }

    /**
     * Add a subtle pit-wall radio carrier hiss & warm saturation so it feels like a live race channel.
     */
    private function mixSubtlePitCarrierTexture(string $pcm): string
    {
        $samples = unpack('s*', $pcm);
        if (! is_array($samples) || $samples === []) {
            return $pcm;
        }

        $seed = 1337;
        $out = '';
        foreach ($samples as $sample) {
            // Deterministic fast LCG band-limited radio floor
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
            $noise = (($seed % 2001) - 1000) * 0.22; // ~ -48 dBFS subtle carrier
            $mixed = (int) round(($sample * 0.96) + $noise);
            if ($mixed > 32767) {
                $mixed = 32767;
            } elseif ($mixed < -32768) {
                $mixed = -32768;
            }
            $out .= pack('v', $mixed & 0xFFFF);
        }

        return $out;
    }

    /**
     * Crisp F1/GT3 Push-To-Talk opening chirp (95 ms dual-tone 1120 Hz -> 1480 Hz + 40 ms gap).
     */
    private function generatePttOpenBeep(): string
    {
        $sr = self::SAMPLE_RATE;
        $beepSamples = (int) ($sr * 0.095);
        $gapSamples = (int) ($sr * 0.040);
        $pcm = '';

        for ($i = 0; $i < $beepSamples; $i++) {
            $t = $i / $sr;
            $env = min(1.0, $i / 120.0) * min(1.0, ($beepSamples - $i) / 180.0);
            $freq = $i < ($beepSamples * 0.45) ? 1120.0 : 1480.0;
            $val = (int) round(sin(2.0 * M_PI * $freq * $t) * 5800.0 * $env);
            $pcm .= pack('v', $val & 0xFFFF);
        }

        return $pcm.str_repeat("\x00\x00", $gapSamples);
    }

    /**
     * Subtle Push-To-Talk mic release squelch tail (30 ms gap + 50 ms squelch click).
     */
    private function generatePttCloseClick(): string
    {
        $sr = self::SAMPLE_RATE;
        $gapSamples = (int) ($sr * 0.030);
        $tailSamples = (int) ($sr * 0.050);
        $pcm = str_repeat("\x00\x00", $gapSamples);
        $seed = 9001;

        for ($i = 0; $i < $tailSamples; $i++) {
            $t = $i / $sr;
            $env = min(1.0, $i / 60.0) * min(1.0, ($tailSamples - $i) / 260.0);
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
            $squelch = (($seed % 2001) - 1000) * 1.6;
            $tone = sin(2.0 * M_PI * 960.0 * $t) * 2800.0;
            $val = (int) round(($tone + $squelch) * $env);
            $pcm .= pack('v', $val & 0xFFFF);
        }

        return $pcm;
    }

    private function wrapPcmAsWav(string $pcm, int $sampleRate): string
    {
        $dataSize = strlen($pcm);
        $header = 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVEfmt '
            .pack('V', 16)
            .pack('v', 1) // PCM
            .pack('v', 1) // Mono
            .pack('V', $sampleRate)
            .pack('V', $sampleRate * 2)
            .pack('v', 2)
            .pack('v', 16)
            .'data'
            .pack('V', $dataSize);

        return $header.$pcm;
    }

    /**
     * Offline fallback synthesizer used in CI/tests or when network/edge-tts is unavailable.
     *
     * @return array{url: string, sha256: string}
     */
    private function synthesizeLocalDevelopmentWav(string $text, string $compositeVoiceId, bool $radioBeep = true): array
    {
        $pcm = $radioBeep
            ? $this->generatePttOpenBeep().$this->generatePttCloseClick()
            : str_repeat("\x00\x00", (int) (self::SAMPLE_RATE * 0.15));

        // Embed deterministic sub-LSB signature of text so distinct test phrases have distinct hashes
        $sig = substr(hash('sha256', "{$compositeVoiceId}:{$text}", true), 0, 16);
        $wavBytes = $this->wrapPcmAsWav($pcm.$sig, self::SAMPLE_RATE);
        $contentHash = hash('sha256', $wavBytes);
        $diskPath = "audio-cache/{$contentHash}.wav";
        Storage::disk('local')->put($diskPath, $wavBytes);

        VoiceClip::query()->updateOrCreate(
            ['hash' => $contentHash],
            [
                'provider' => 'local',
                'voice_id' => $compositeVoiceId,
                'text' => $text,
                'disk_path' => $diskPath,
                'mime_type' => 'audio/wav',
                'duration_ms' => (int) round((strlen($pcm) / 2 / self::SAMPLE_RATE) * 1000),
            ]
        );

        return [
            'url' => url("/api/v1/audio/{$contentHash}"),
            'sha256' => $contentHash,
        ];
    }
}
