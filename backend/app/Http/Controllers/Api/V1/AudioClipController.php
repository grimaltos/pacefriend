<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VoiceClip;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AudioClipController extends Controller
{
    public function show(string $hash): Response
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            abort(404);
        }

        $clip = VoiceClip::query()->where('hash', $hash)->firstOrFail();

        if (! Storage::disk('local')->exists($clip->disk_path)) {
            abort(404);
        }

        return response(Storage::disk('local')->get($clip->disk_path), 200, [
            'Content-Type' => $clip->mime_type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => "\"{$clip->hash}\"",
        ]);
    }
}
