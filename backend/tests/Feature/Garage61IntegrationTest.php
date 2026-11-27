<?php

namespace Tests\Feature;

use App\Models\DrivingSession;
use App\Models\ReferenceLap;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Garage61IntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_option_a_imports_multi_lap_analysis_url_and_maps_curated_corners(): void
    {
        Http::fake([
            'https://garage61.net/api/v1/me' => Http::response([
                'id' => 'u_01',
                'firstName' => 'Carlos',
                'lastName' => 'Grimaltos',
            ], 200),
            'https://garage61.net/api/v1/laps/01J8MONZA_COOL' => Http::response([
                'id' => '01J8MONZA_COOL',
                'lapTime' => 107.420,
                'trackTemp' => 21.5,
                'airTemp' => 18.0,
                'clean' => true,
                'canViewTelemetry' => true,
                'track' => ['name' => 'Autodromo Nazionale Monza'],
                'car' => ['name' => 'Ferrari 296 GT3'],
                'driver' => ['firstName' => 'Marco', 'lastName' => 'Rossi'],
            ], 200),
            'https://garage61.net/api/v1/laps/01J8MONZA_HOT' => Http::response([
                'id' => '01J8MONZA_HOT',
                'lapTime' => 108.310,
                'trackTemp' => 36.0,
                'airTemp' => 27.0,
                'clean' => true,
                'canViewTelemetry' => true,
                'track' => ['name' => 'Autodromo Nazionale Monza'],
                'car' => ['name' => 'Ferrari 296 GT3'],
                'driver' => ['firstName' => 'Marco', 'lastName' => 'Rossi'],
            ], 200),
            'https://garage61.net/api/v1/laps/*/csv' => Http::response($this->buildSyntheticG61Csv(), 200),
        ]);

        $user = User::factory()->create();

        // 1. Save & verify G61 token in Settings
        $this->actingAs($user)->put('/settings/garage61', [
            'garage61_token' => 'g61_pat_test_secret',
            'autopilot_enabled' => true,
            'autopilot_mode' => 'progressive',
            'autopilot_delta_ms' => 1000,
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('g61_pat_test_secret', $user->garage61_token);

        // 2. Paste Garage 61 multi-lap analysis URL
        $this->actingAs($user)->post('/references/garage61/import-url', [
            'g61_input' => 'https://garage61.net/app/analysis/shared?laps=01J8MONZA_COOL,01J8MONZA_HOT',
        ])->assertSessionHasNoErrors();

        $refs = ReferenceLap::query()->where('user_id', $user->id)->orderBy('track_temp_c')->get();
        $this->assertCount(2, $refs);
        $this->assertSame('garage61_manual', $refs[0]->source);
        $this->assertSame('Marco Rossi', $refs[0]->driver_name);
        $this->assertSame(107420, $refs[0]->lap_time_ms);

        // Verify Monza curated corner names were applied (e.g. Variante del Rettifilo)
        $cornerNames = array_column($refs[0]->corner_baselines, 'name');
        $this->assertContains('Variante del Rettifilo', $cornerNames);
        $this->assertContains('Variante Ascari', $cornerNames);
    }

    public function test_option_c_progressive_autopilot_picks_step_up_lap_and_levels_up_when_beaten(): void
    {
        Http::fake([
            'https://garage61.net/api/v1/tracks' => Http::response([
                'items' => [['id' => 44, 'name' => 'Autodromo Internazionale Enzo e Dino Ferrari']],
            ], 200),
            'https://garage61.net/api/v1/cars' => Http::response([
                'items' => [['id' => 99, 'name' => 'Porsche 911 GT3 R (992)']],
            ], 200),
            'https://garage61.net/api/v1/laps*' => function ($request) {
                $url = $request->url();
                if (str_ends_with($url, '/csv')) {
                    return Http::response($this->buildSyntheticG61Csv(), 200);
                }
                if (str_contains($url, '/laps/01J8IMOLA_STEP')) {
                    return Http::response([
                        'id' => '01J8IMOLA_STEP',
                        'lapTime' => 102.150,
                        'trackTemp' => 28.0,
                        'airTemp' => 21.0,
                        'track' => ['name' => 'Autodromo Internazionale Enzo e Dino Ferrari'],
                        'car' => ['name' => 'Porsche 911 GT3 R (992)'],
                        'driver' => ['nickName' => 'StepTargetDriver'],
                    ], 200);
                }
                if (str_contains($url, '/laps/01J8IMOLA_ALIEN')) {
                    return Http::response([
                        'id' => '01J8IMOLA_ALIEN',
                        'lapTime' => 100.100,
                        'trackTemp' => 28.2,
                        'airTemp' => 21.0,
                        'track' => ['name' => 'Autodromo Internazionale Enzo e Dino Ferrari'],
                        'car' => ['name' => 'Porsche 911 GT3 R (992)'],
                        'driver' => ['nickName' => 'AlienWorldRecord'],
                    ], 200);
                }

                // Search endpoint /laps?...
                return Http::response([
                    'items' => [
                        [
                            'id' => '01J8IMOLA_PRIVATE',
                            'lapTime' => 102.100,
                            'trackTemp' => 28.0,
                            'clean' => true,
                            'canViewTelemetry' => false, // Blocked telemetry -> must be ignored!
                        ],
                        [
                            'id' => '01J8IMOLA_ALIEN',
                            'lapTime' => 100.100, // 3.1s faster than 103.2s -> too aggressive initially
                            'trackTemp' => 28.2,
                            'clean' => true,
                            'canViewTelemetry' => true,
                        ],
                        [
                            'id' => '01J8IMOLA_STEP',
                            'lapTime' => 102.150, // 1.05s faster than 103.2s -> ideal progressive target!
                            'trackTemp' => 28.0,
                            'clean' => true,
                            'canViewTelemetry' => true,
                        ],
                    ],
                ], 200);
            },
        ]);

        $user = User::factory()->create([
            'garage61_token' => 'g61_pat_live',
            'autopilot_enabled' => true,
            'autopilot_mode' => 'progressive',
            'autopilot_delta_ms' => 1000,
        ]);
        $token = $user->createToken('Rig')->plainTextToken;

        // 1. Driver completes Lap 1 at Imola (no local reference yet!) in 1:43.200 (103200 ms) at 27.9 °C
        $resp1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/lap', [
                'track_slug' => 'imola-gp',
                'track_name' => 'Autodromo Internazionale Enzo e Dino Ferrari',
                'track_length_km' => 4.909,
                'car_name' => 'Porsche 911 GT3 R (992)',
                'session_id' => 777,
                'sub_session_id' => 888,
                'lap_number' => 1,
                'lap_time_ms' => 103200,
                'is_clean' => true,
                'track_temp_c' => 27.9,
                'air_temp_c' => 21.0,
                'samples' => $this->buildSimpleLapSamples(103200),
            ]);

        $resp1->assertCreated();
        $session = DrivingSession::query()->findOrFail($resp1->json('driving_session_id'));
        $session->refresh();

        $this->assertNotNull($session->activeReferenceLap);
        $this->assertSame('01J8IMOLA_STEP', $session->activeReferenceLap->external_id);
        $this->assertSame('garage61_autopilot', $session->activeReferenceLap->source);

        // 2. Driver improves to 1:42.250 (102250 ms, within 250ms of 102150ms target!) -> Autopilot levels up to Alien (100100 ms)!
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/lap', [
                'track_slug' => 'imola-gp',
                'track_name' => 'Autodromo Internazionale Enzo e Dino Ferrari',
                'track_length_km' => 4.909,
                'car_name' => 'Porsche 911 GT3 R (992)',
                'session_id' => 777,
                'sub_session_id' => 888,
                'lap_number' => 2,
                'lap_time_ms' => 102250,
                'is_clean' => true,
                'track_temp_c' => 28.0,
                'air_temp_c' => 21.0,
                'samples' => $this->buildSimpleLapSamples(102250),
            ])->assertCreated();

        $session->refresh();
        $this->assertSame('01J8IMOLA_ALIEN', $session->activeReferenceLap->external_id);
    }

    public function test_user_can_add_custom_circuit_via_panel_and_sync_command(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/tracks', [
            'slug' => 'road-america-full',
            'name' => 'Road America',
            'length_km' => 6.515,
            'corners' => [
                ['turn' => 1, 'name' => 'Curva 1', 'brake_pct' => 0.110, 'apex_pct' => 0.135, 'exit_pct' => 0.160],
                ['turn' => 5, 'name' => 'Curva 5 (Frenada Bajada)', 'brake_pct' => 0.340, 'apex_pct' => 0.365, 'exit_pct' => 0.395],
                ['turn' => 9, 'name' => 'Carousel', 'brake_pct' => 0.560, 'apex_pct' => 0.610, 'exit_pct' => 0.650],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tracks', [
            'slug' => 'road-america-full',
            'name' => 'Road America',
        ]);

        $this->artisan('tracks:sync')->assertSuccessful();
    }

    private function buildSyntheticG61Csv(): string
    {
        $rows = ['LapDistPct,Speed,Throttle,Brake,Gear,LapCurrentLapTime'];
        for ($i = 0; $i < 100; $i++) {
            // 0..100 percentage format to verify automatic scale normalization
            $pct100 = round($i, 2);
            $timeSec = round(($i / 100) * 102.0, 3);
            $isCorner = ($i >= 14 && $i <= 19) || ($i >= 68 && $i <= 74);
            $speed = $isCorner ? 31.0 : 71.0;
            $throttle = $isCorner ? 0.15 : 1.0;
            $brake = $isCorner ? 0.85 : 0.0;
            $gear = $isCorner ? 2 : 6;
            $rows[] = "{$pct100},{$speed},{$throttle},{$brake},{$gear},{$timeSec}";
        }
        return implode("\n", $rows);
    }

    /**
     * @return array<int, array<string, float|int>>
     */
    private function buildSimpleLapSamples(int $lapTimeMs): array
    {
        $samples = [];
        for ($i = 0; $i < 40; $i++) {
            $pct = round($i / 40, 4);
            $samples[] = [
                't_ms' => (int) round($pct * $lapTimeMs),
                'pct' => $pct,
                'speed' => 60.0,
                'throttle' => 1.0,
                'brake' => 0.0,
                'gear' => 5,
            ];
        }
        return $samples;
    }
}
