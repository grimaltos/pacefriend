<?php

namespace App\Services\Voice;

use App\Models\VoiceClip;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VoiceSynthesisManager
{
    /**
     * Synthesize or retrieve a cached AI voice clip for a coaching radio callout.
     *
     * @return array{url: string, sha256: string}
     */
    public function ensureAudioClip(string $text): array
    {
        $driver = (string) config('pacefriend.voice.driver', 'local');
        $voiceId = $driver === 'elevenlabs'
            ? (string) config('pacefriend.voice.elevenlabs.voice_id', 'default')
            : 'local-radio-synth';

        $hash = hash('sha256', "{$driver}:{$voiceId}:{$text}");

        $existing = VoiceClip::query()->where('hash', $hash)->first();
        if ($existing !== null && Storage::disk('local')->exists($existing->disk_path)) {
            return [
                'url' => url("/api/v1/audio/{$hash}"),
                'sha256' => $hash,
            ];
        }

        if ($driver === 'elevenlabs' && filled(config('pacefriend.voice.elevenlabs.api_key'))) {
            $result = $this->synthesizeElevenLabs($text, $voiceId, $hash);
            if ($result !== null) {
                return $result;
            }
        }

        return $this->synthesizeLocalDevelopmentWav($text, $hash);
    }

    /**
     * Call ElevenLabs Multilingual v2 API to render natural single-take engineer speech.
     *
     * @return array{url: string, sha256: string}|null
     */
    private function synthesizeElevenLabs(string $text, string $voiceId, string $hash): ?array
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
                Log::warning('ElevenLabs TTS failed, falling back to local WAV', [
                    'status' => $response->status(),
                ]);
                return null;
            }

            $diskPath = "audio-cache/{$hash}.mp3";
            Storage::disk('local')->put($diskPath, $response->body());

            VoiceClip::query()->updateOrCreate(
                ['hash' => $hash],
                [
                    'provider' => 'elevenlabs',
                    'voice_id' => $voiceId,
                    'text' => $text,
                    'disk_path' => $diskPath,
                    'mime_type' => 'audio/mpeg',
                ]
            );

            return [
                'url' => url("/api/v1/audio/{$hash}"),
                'sha256' => $hash,
            ];
        } catch (\Throwable $e) {
            Log::warning('ElevenLabs TTS exception, falling back to local WAV', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Zero-cost development synthesizer that writes a valid 16-bit PCM WAV radio tone
     * so audio caching, SHA-256 verification, and client RAM preloading work offline.
     *
     * @return array{url: string, sha256: string}
     */
    private function synthesizeLocalDevelopmentWav(string $text, string $hash): array
    {
        $sampleRate = 22050;
        $durationSec = 0.22;
        $numSamples = (int) ($sampleRate * $durationSec);
        $pcm = '';

        for ($i = 0; $i < $numSamples; $i++) {
            $t = $i / $sampleRate;
            $envelope = min(1.0, $i / 400.0) * min(1.0, ($numSamples - $i) / 800.0);
            $freq = $i < ($numSamples / 2) ? 880.0 : 1174.66; // Two-tone motorsport radio chirp
            $sample = (int) round(sin(2.0 * M_PI * $freq * $t) * 6500.0 * $envelope);
            $pcm .= pack('v', $sample & 0xFFFF);
        }

        $dataSize = strlen($pcm);
        $wavHeader = 'RIFF'
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

        $diskPath = "audio-cache/{$hash}.wav";
        Storage::disk('local')->put($diskPath, $wavHeader.$pcm);

        VoiceClip::query()->updateOrCreate(
            ['hash' => $hash],
            [
                'provider' => 'local',
                'voice_id' => 'local-radio-synth',
                'text' => $text,
                'disk_path' => $diskPath,
                'mime_type' => 'audio/wav',
                'duration_ms' => (int) ($durationSec * 1000),
            ]
        );

        return [
            'url' => url("/api/v1/audio/{$hash}"),
            'sha256' => $hash,
        ];
    }
}
