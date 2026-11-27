<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Track;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TrackCatalogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tracks/Index', [
            'tracks' => Track::query()->orderBy('name')->get(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'exists:tracks,id'],
            'slug' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:180'],
            'length_km' => ['required', 'numeric', 'min:0.4', 'max:30'],
            'corners' => ['required', 'array', 'min:1'],
            'corners.*.turn' => ['required', 'integer', 'min:1', 'max:99'],
            'corners.*.name' => ['required', 'string', 'max:100'],
            'corners.*.brake_pct' => ['required', 'numeric', 'min:0', 'max:0.9999'],
            'corners.*.apex_pct' => ['required', 'numeric', 'min:0', 'max:0.9999'],
            'corners.*.exit_pct' => ['required', 'numeric', 'min:0', 'max:0.9999'],
        ]);

        $slug = Str::slug($validated['slug']) ?: Str::slug($validated['name']);

        if (! empty($validated['id'])) {
            $track = Track::query()->findOrFail((int) $validated['id']);
            $track->update([
                'slug' => $slug,
                'name' => $validated['name'],
                'length_km' => (float) $validated['length_km'],
                'corners' => array_values($validated['corners']),
                'corners_is_curated' => true,
            ]);
        } else {
            $track = Track::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $validated['name'],
                    'length_km' => (float) $validated['length_km'],
                    'corners' => array_values($validated['corners']),
                    'corners_is_curated' => true,
                ]
            );
        }

        return back()->with('success', "Circuito \"{$track->name}\" guardado con ".count($track->corners)." unidades tácticas.");
    }
}
