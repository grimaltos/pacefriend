<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Lap;
use App\Models\ReferenceLap;
use App\Models\Track;
use App\Services\Garage61\Garage61ImporterService;
use App\Services\Telemetry\IbtFileParserService;
use App\Services\Telemetry\TelemetryNormalizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class ReferenceLapController extends Controller
{
    public function __construct(
        private readonly IbtFileParserService $ibtParser,
        private readonly TelemetryNormalizerService $normalizer,
        private readonly Garage61ImporterService $g61Importer,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $references = ReferenceLap::query()
            ->with('track:id,name,slug,length_km')
            ->where(static function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->orderByRaw('CASE WHEN user_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (ReferenceLap $r) => [
                'id' => $r->id,
                'is_owned' => $r->user_id === $user->id,
                'label' => $r->label ?: "{$r->car_name} ({$r->track_temp_c} °C)",
                'car_name' => $r->car_name,
                'driver_name' => $r->driver_name,
                'lap_time_ms' => $r->lap_time_ms,
                'track_temp_c' => $r->track_temp_c,
                'air_temp_c' => $r->air_temp_c,
                'is_wet' => $r->is_wet,
                'source' => $r->source,
                'external_id' => $r->external_id,
                'track' => $r->track,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        return Inertia::render('References/Index', [
            'references' => $references,
            'tracks' => Track::query()->orderBy('name')->get(['id', 'slug', 'name']),
            'garage61Connected' => filled($user->garage61_token),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'telemetry_file' => ['required', 'file', 'max:51200'],
            'label' => ['nullable', 'string', 'max:180'],
            'track_temp_override' => ['nullable', 'numeric', 'min:-10', 'max:75'],
            'air_temp_override' => ['nullable', 'numeric', 'min:-20', 'max:60'],
            'is_wet' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('telemetry_file');

        try {
            $parsed = $this->ibtParser->parseContent(
                (string) file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['telemetry_file' => $e->getMessage()]);
        }

        $track = Track::resolveOrCreateCurated(
            $parsed['track_slug'],
            $parsed['track_name'],
            $parsed['track_length_km']
        );

        $trackTemp = isset($validated['track_temp_override']) && $validated['track_temp_override'] !== null
            ? (float) $validated['track_temp_override']
            : (float) $parsed['track_temp_c'];

        $airTemp = isset($validated['air_temp_override']) && $validated['air_temp_override'] !== null
            ? (float) $validated['air_temp_override']
            : (float) $parsed['air_temp_c'];

        $isWet = $request->has('is_wet') ? $request->boolean('is_wet') : (bool) $parsed['is_wet'];

        $normalized = $this->normalizer->normalizeSamples($parsed['samples']);
        $baselines = $this->normalizer->extractCornerBaselines($parsed['samples'], $track->corners ?? [], $parsed['lap_time_ms']);

        ReferenceLap::query()->create([
            'user_id' => $request->user()->id,
            'track_id' => $track->id,
            'car_name' => $parsed['car_name'],
            'label' => $validated['label'] ?: "{$file->getClientOriginalName()} ({$trackTemp} °C)",
            'lap_time_ms' => $parsed['lap_time_ms'],
            'track_temp_c' => $trackTemp,
            'air_temp_c' => $airTemp,
            'is_wet' => $isWet,
            'source' => $parsed['source'],
            'normalized_points' => $normalized,
            'corner_baselines' => $baselines,
        ]);

        return back()->with('success', "Vuelta de referencia cargada para {$track->name} ({$trackTemp} °C asfalto).");
    }

    public function importGarage61Url(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'g61_input' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $imported = $this->g61Importer->importFromUrlOrIds($request->user(), $validated['g61_input']);
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withErrors(['g61_input' => $e->getMessage()]);
        }

        $count = count($imported);
        return back()->with('success', "Se importaron {$count} vuelta(s) de referencia desde Garage 61.");
    }

    public function searchGarage61(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'track_id' => ['required', 'integer', 'exists:tracks,id'],
            'car_name' => ['required', 'string', 'max:160'],
            'track_temp_c' => ['nullable', 'numeric', 'min:-10', 'max:75'],
            'only_team' => ['nullable', 'boolean'],
        ]);

        $track = Track::query()->findOrFail((int) $validated['track_id']);

        try {
            $items = $this->g61Importer->searchForPanel(
                $request->user(),
                $track,
                $validated['car_name'],
                isset($validated['track_temp_c']) ? (float) $validated['track_temp_c'] : null,
                (bool) ($validated['only_team'] ?? false)
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['items' => $items]);
    }

    public function importGarage61Lap(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lap_id' => ['required', 'string', 'max:96'],
            'track_id' => ['nullable', 'integer', 'exists:tracks,id'],
            'car_name' => ['nullable', 'string', 'max:160'],
        ]);

        $track = ! empty($validated['track_id']) ? Track::query()->find((int) $validated['track_id']) : null;

        try {
            $ref = $this->g61Importer->importSingleLap(
                $request->user(),
                $validated['lap_id'],
                'garage61_manual',
                $track,
                $validated['car_name'] ?? null
            );
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Referencia G61 importada: {$ref->label}.");
    }

    public function promoteFromLap(Request $request, Lap $lap): RedirectResponse
    {
        abort_unless($lap->user_id === $request->user()->id, 403);

        if (! $lap->is_clean) {
            return back()->with('error', 'Solo se pueden promocionar vueltas limpias como referencia.');
        }

        $lap->loadMissing('track');
        $normalized = $this->normalizer->normalizeSamples($lap->samples ?? []);
        $baselines = $this->normalizer->extractCornerBaselines(
            $lap->samples ?? [],
            $lap->track->corners ?? [],
            $lap->lap_time_ms
        );

        ReferenceLap::query()->create([
            'user_id' => $request->user()->id,
            'track_id' => $lap->track_id,
            'car_name' => $lap->car_name,
            'label' => "Vuelta #{$lap->lap_number} propia ({$lap->track_temp_c} °C)",
            'lap_time_ms' => $lap->lap_time_ms,
            'track_temp_c' => $lap->track_temp_c,
            'air_temp_c' => $lap->air_temp_c,
            'is_wet' => $lap->is_wet,
            'source' => 'promoted_lap',
            'normalized_points' => $normalized,
            'corner_baselines' => $baselines,
        ]);

        return back()->with('success', "Vuelta #{$lap->lap_number} guardada en tu biblioteca de referencias ({$lap->track_temp_c} °C).");
    }

    public function destroy(Request $request, ReferenceLap $referenceLap): RedirectResponse
    {
        abort_unless($referenceLap->user_id === $request->user()->id, 403);
        $referenceLap->delete();

        return back()->with('success', 'Vuelta de referencia eliminada.');
    }
}
