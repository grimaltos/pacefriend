<?php

namespace Tests\Feature;

use App\Models\DrivingSession;
use App\Models\ReferenceLap;
use App\Models\Track;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaceFriendPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_user_can_register_with_callsign_and_upload_binary_ibt_reference(): void
    {
        $response = $this->post('/register', [
            'name' => 'Carlos Grimaltos',
            'driver_callsign' => 'Carlos',
            'email' => 'carlos@pacefriend.local',
            'password' => 'RaceEngineer2026!',
            'password_confirmation' => 'RaceEngineer2026!',
        ]);

        $response->assertRedirect('/dashboard');
        $user = User::query()->where('email', 'carlos@pacefriend.local')->firstOrFail();
        $this->assertSame('Carlos', $user->effectiveCallsign());

        $ibtBinary = $this->buildSyntheticBinaryIbtFile(trackTempC: 29.5, airTempC: 21.0);
        $file = UploadedFile::fake()->createWithContent('spa_porsche_qualy.ibt', $ibtBinary);

        $uploadResp = $this->actingAs($user)->post('/references', [
            'telemetry_file' => $file,
            'label' => 'Qualy Tarde 29.5C',
        ]);

        $uploadResp->assertSessionHasNoErrors();

        $ref = ReferenceLap::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('ibt_upload', $ref->source);
        $this->assertSame('Porsche 911 GT3 R (992)', $ref->car_name);
        $this->assertEqualsWithDelta(29.5, $ref->track_temp_c, 0.2);
        $this->assertNotEmpty($ref->normalized_points);
        $this->assertNotEmpty($ref->corner_baselines);
    }

    public function test_weather_matching_and_sliding_window_coaching_plan_with_callsign_and_pro_report(): void
    {
        $user = User::factory()->create([
            'name' => 'Fernando Piloto',
            'driver_callsign' => 'Fer',
        ]);

        $token = $user->createToken('SimRig')->plainTextToken;
        $spa = Track::query()->where('slug', 'spa-up')->firstOrFail();

        // Seeded references: cool (21.0 °C) and hot (33.0 °C)
        $hotRef = ReferenceLap::query()
            ->where('track_id', $spa->id)
            ->where('track_temp_c', '>=', 30.0)
            ->firstOrFail();

        // Send 4 clean laps at 32.2 °C asphalt where driver brakes 25m early at Turn 1 (La Source)
        // and gradually improves lap times across the stint (140.2s -> 139.4s)
        $lapTimes = [140200, 139900, 139600, 139400];
        $lastJson = null;

        foreach ($lapTimes as $idx => $lapTimeMs) {
            $lapNumber = $idx + 1;
            $resp = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/telemetry/lap', [
                    'track_slug' => 'spa-up',
                    'track_name' => 'Circuit de Spa-Francorchamps',
                    'track_length_km' => 7.004,
                    'car_name' => 'Porsche 911 GT3 R (992)',
                    'session_id' => 5001,
                    'sub_session_id' => 9001,
                    'lap_number' => $lapNumber,
                    'lap_time_ms' => $lapTimeMs,
                    'is_clean' => true,
                    'incident_delta' => 0,
                    'track_temp_c' => 32.2,
                    'air_temp_c' => 23.5,
                    'is_wet' => false,
                    'sample_rate_hz' => 20,
                    'samples' => $this->buildDriverEarlyBrakingSamples($lapTimeMs),
                ]);

            $resp->assertCreated();
            $lastJson = $resp->json();
        }

        // Verify hot reference (33 °C) was dynamically selected instead of faster cold reference (21 °C)
        $this->assertSame($hotRef->id, $lastJson['matched_reference_lap_id']);
        $this->assertNotNull($lastJson['coaching_plan']);
        $this->assertSame(1, $lastJson['coaching_plan']['focus_turn']);
        $this->assertSame('La Source', $lastJson['coaching_plan']['focus_turn_name']);

        // Verify coach addresses driver by callsign ("Fer") in pre-brake trigger & caches AI voice audio
        $preTrigger = $lastJson['coaching_plan']['triggers'][0];
        $this->assertStringContainsString('Fer,', $preTrigger['text']);
        $this->assertNotEmpty($preTrigger['audio_sha256']);

        $audioResp = $this->get("/api/v1/audio/{$preTrigger['audio_sha256']}");
        $audioResp->assertOk();

        // Generate on-demand PRO Post-Session Report
        $session = DrivingSession::query()->findOrFail($lastJson['driving_session_id']);
        $reportResp = $this->actingAs($user)->post("/sessions/{$session->id}/report");
        $reportResp->assertRedirect();

        $session->refresh();
        $this->assertNotNull($session->report);
        $this->assertGreaterThan(0, $session->report->quantitative_summary['pace_gain_ms']);
        $this->assertStringContainsString('Fer', $session->report->narrative_markdown);
        $this->assertStringContainsString('La Source', $session->report->narrative_markdown);
    }

    public function test_user_can_download_preconfigured_windows_client_zip_bundle(): void
    {
        $user = User::factory()->create(['driver_callsign' => 'Vicent']);

        $resp = $this->actingAs($user)->get('/settings/client-bundle');
        $resp->assertOk();
        $this->assertSame('application/zip', $resp->headers->get('Content-Type'));

        $zipPath = $resp->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);

        $this->assertNotFalse($zip->locateName('PaceFriend/pacefriend-client.exe'));
        $jsonRaw = $zip->getFromName('PaceFriend/pacefriend.json');
        $this->assertNotFalse($jsonRaw);
        $decoded = json_decode((string) $jsonRaw, true);
        $this->assertNotEmpty($decoded['api_token']);
        $this->assertNotEmpty($decoded['api_url']);
        $zip->close();
    }

    public function test_radio_settings_briefing_fuel_alerts_positive_reinforcement_and_pit_tyre_debrief(): void
    {
        $user = User::factory()->create([
            'name' => 'Vicent Grimaltos',
            'driver_callsign' => 'Vicent',
        ]);

        // 1. Configure Ximena neural voice, 70% volume, 2 clean laps threshold
        $this->actingAs($user)->put('/settings/radio', [
            'voice_id' => 'es-ES-XimenaNeural',
            'volume' => 70,
            'radio_beep' => true,
            'pit_wall_fx' => true,
            'finish_callout_mode' => 'full',
            'min_clean_laps' => 2,
            'fuel_alerts' => true,
            'tyre_temp_alerts' => true,
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('es-ES-XimenaNeural', $user->radioSettings()['voice_id']);
        $this->assertSame(70, $user->radioSettings()['volume']);
        $this->assertSame(2, $user->radioSettings()['min_clean_laps']);

        // 2. Preview radio clip & verify content-addressed SHA-256 matches served bytes
        $previewResp = $this->actingAs($user)->postJson('/settings/radio/preview', [
            'voice_id' => 'es-ES-XimenaNeural',
            'volume' => 70,
            'radio_beep' => true,
            'pit_wall_fx' => true,
        ]);
        $previewResp->assertOk();
        $previewSha = $previewResp->json('sha256');
        $rawClip = $this->get("/api/v1/audio/{$previewSha}");
        $rawClip->assertOk();
        $this->assertSame($previewSha, hash('sha256', $rawClip->getContent()));

        // Attach target fuel to seeded reference so fuel delta is reported in briefing
        $spa = Track::query()->where('slug', 'spa-up')->firstOrFail();
        $ref = ReferenceLap::query()->where('track_id', $spa->id)->where('track_temp_c', '<', 25.0)->firstOrFail();
        $ref->update(['fuel_level_l' => 16.0, 'fuel_used_l' => 2.7]);

        $token = $user->createToken('SimRig')->plainTextToken;

        // 3. Out-lap Briefing with 52L vs 16L target (+36L weight delta)
        $briefResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/briefing', [
                'track_slug' => 'spa-up',
                'track_name' => 'Circuit de Spa-Francorchamps',
                'track_length_km' => 7.004,
                'car_name' => 'Porsche 911 GT3 R (992)',
                'session_id' => 7701,
                'sub_session_id' => 8801,
                'track_temp_c' => 21.5,
                'air_temp_c' => 17.0,
                'is_wet' => false,
                'fuel_level_l' => 52.0,
                'reason' => 'session_start',
            ]);
        $briefResp->assertOk();
        $this->assertSame(70, $briefResp->json('audio_settings.volume'));
        $briefText = $briefResp->json('radio_callouts.0.text');
        $this->assertStringContainsString('Buenas Vicent', $briefText);
        $this->assertStringContainsString('36 litros más que el target', $briefText);

        // 4. Two clean laps -> Lap 2 triggers PB positive reinforcement + 2-lap coaching plan + 3-lap fuel warning
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/lap', [
                'track_slug' => 'spa-up',
                'car_name' => 'Porsche 911 GT3 R (992)',
                'session_id' => 7701,
                'sub_session_id' => 8801,
                'lap_number' => 1,
                'lap_time_ms' => 139800,
                'is_clean' => true,
                'track_temp_c' => 21.5,
                'air_temp_c' => 17.0,
                'fuel_level_l' => 10.5,
                'fuel_used_l' => 2.8,
                'samples' => $this->buildDriverEarlyBrakingSamples(139800),
            ])->assertCreated();

        $lap2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/lap', [
                'track_slug' => 'spa-up',
                'car_name' => 'Porsche 911 GT3 R (992)',
                'session_id' => 7701,
                'sub_session_id' => 8801,
                'lap_number' => 2,
                'lap_time_ms' => 139200,
                'is_clean' => true,
                'track_temp_c' => 21.5,
                'air_temp_c' => 17.0,
                'fuel_level_l' => 7.7, // ~2.75 laps left -> triggers 3-lap fuel alert!
                'fuel_used_l' => 2.8,
                'samples' => $this->buildDriverEarlyBrakingSamples(139200),
            ]);
        $lap2->assertCreated();
        $this->assertNotNull($lap2->json('coaching_plan'), 'Expected coaching plan after 2 clean laps (custom threshold)');
        $calloutTexts = implode(' | ', array_column($lap2->json('radio_callouts'), 'text'));
        $this->assertStringContainsString('Buena vuelta Vicent', $calloutTexts);
        $this->assertStringContainsString('tres vueltas', $calloutTexts);

        // 5. Pit-stop real tyre pyrometer check (overheated LF = 99.4 °C)
        $pitResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/pit-stop', [
                'lf_temp_c' => 99.4,
                'rf_temp_c' => 88.0,
                'lr_temp_c' => 85.5,
                'rr_temp_c' => 84.2,
                'laps_completed' => 2,
            ]);
        $pitResp->assertOk();
        $this->assertStringContainsString('delantera izquierda', $pitResp->json('radio_callouts.0.text'));
        $this->assertStringContainsString('99 grados', $pitResp->json('radio_callouts.0.text'));
    }

    public function test_dirty_laps_still_train_coach_from_clean_partials_and_compute_optimal_lap(): void
    {
        $user = User::factory()->create([
            'name' => 'Vicent Grimaltos',
            'driver_callsign' => 'Vicent',
            'preferences' => ['min_clean_laps' => 2],
        ]);
        $token = $user->createToken('SimRig')->plainTextToken;

        // Lap 1 & Lap 2 BOTH have an off-track in Sector 3 (pct = 0.91, flags = 1),
        // while Sector 1 (Turn 1 La Source) and Sector 2 are 100% clean!
        for ($lapNum = 1; $lapNum <= 2; $lapNum++) {
            $samples = $this->buildDriverEarlyBrakingSamples(140000);
            foreach ($samples as &$s) {
                if ($s['pct'] >= 0.90 && $s['pct'] <= 0.92) {
                    $s['flags'] = 1; // FlagOffTrack in Sector 3 only
                }
            }
            unset($s);

            $resp = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/telemetry/lap', [
                    'track_slug' => 'spa-up',
                    'car_name' => 'Porsche 911 GT3 R (992)',
                    'session_id' => 9101,
                    'sub_session_id' => 9201,
                    'lap_number' => $lapNum,
                    'lap_time_ms' => 140000,
                    'is_clean' => false,
                    'incident_delta' => 1,
                    'track_temp_c' => 22.0,
                    'air_temp_c' => 18.0,
                    'sector_splits' => [0.0, 0.341, 0.685],
                    'samples' => $samples,
                ]);
            $resp->assertCreated();
        }

        // Even with 0 full clean laps, Turn 1 (Sector 1) has 2 clean passes -> coaching plan MUST be active!
        $session = DrivingSession::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, $session->clean_laps_count);
        $this->assertNotNull($session->active_coaching_plan);
        $this->assertSame(1, $session->active_coaching_plan['focus_turn']);
        $this->assertNull($session->optimal_lap_time_ms, 'Sector 3 has not been completed cleanly yet');

        // Now complete Sector 3 cleanly (e.g. from Out-lap or Lap 3 partial) -> Optimal Lap unlocks!
        $cleanS3Samples = array_values(array_filter(
            $this->buildDriverEarlyBrakingSamples(139000),
            static fn (array $s) => $s['pct'] >= 0.68 && $s['pct'] <= 0.996
        ));

        $secResp = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/sector', [
                'track_slug' => 'spa-up',
                'car_name' => 'Porsche 911 GT3 R (992)',
                'session_id' => 9101,
                'sub_session_id' => 9201,
                'lap_number' => 3,
                'sector_number' => 3,
                'sector_start_pct' => 0.685,
                'sector_end_pct' => 1.0,
                'sector_time_ms' => 42500,
                'is_clean' => true,
                'track_temp_c' => 22.0,
                'air_temp_c' => 18.0,
                'sector_splits' => [0.0, 0.341, 0.685],
                'samples' => $cleanS3Samples,
            ]);
        $secResp->assertCreated();

        $session->refresh();
        $this->assertNotNull($session->optimal_lap_time_ms);
        $this->assertGreaterThan(100000, $session->optimal_lap_time_ms);
    }

    public function test_causal_trajectory_diagnoses_narrow_entry_root_cause_and_builds_2d_corner_map(): void
    {
        $user = User::factory()->create([
            'name' => 'Vicent Grimaltos',
            'driver_callsign' => 'Vicent',
            'preferences' => ['min_clean_laps' => 2],
        ]);
        $spa = Track::query()->where('slug', 'spa-up')->firstOrFail();
        $normalizer = app(\App\Services\Telemetry\TelemetryNormalizerService::class);

        // Create a reference lap with 64-bit Lat/Lon trajectory & steering at 25.0 °C
        $refSamples = $this->buildGeoTrajectorySamples(138000, narrowEntryTurn5Meters: 0.0, lateThrottleTurn5: false);
        $ref = ReferenceLap::query()->create([
            'user_id' => $user->id,
            'track_id' => $spa->id,
            'car_name' => 'Porsche 911 GT3 R (992)',
            'label' => 'Ref GPS Spa 25C',
            'lap_time_ms' => 138000,
            'track_temp_c' => 25.0,
            'air_temp_c' => 19.0,
            'is_wet' => false,
            'is_pinned' => true,
            'source' => 'ibt_upload',
            'normalized_points' => $normalizer->normalizeSamples($refSamples),
            'corner_baselines' => $normalizer->extractCornerBaselines($refSamples, $spa->corners ?? [], 138000, $spa->length_km, true),
        ]);

        // Driver enters Turn 5 (Les Combes, right-hander) 1.8m too far inside (narrow entry) AND opens throttle late as a result!
        $token = $user->createToken('SimRig')->plainTextToken;
        $lastResp = null;

        for ($lapNum = 1; $lapNum <= 2; $lapNum++) {
            $drvSamples = $this->buildGeoTrajectorySamples(139200, narrowEntryTurn5Meters: 1.8, lateThrottleTurn5: true);
            $lastResp = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/telemetry/lap', [
                    'track_slug' => 'spa-up',
                    'car_name' => 'Porsche 911 GT3 R (992)',
                    'session_id' => 9501,
                    'sub_session_id' => 9601,
                    'lap_number' => $lapNum,
                    'lap_time_ms' => 139200,
                    'is_clean' => true,
                    'track_temp_c' => 25.0,
                    'air_temp_c' => 19.0,
                    'samples' => $drvSamples,
                ]);
            $lastResp->assertCreated();
        }

        // Root cause MUST be 'narrow_entry' (entry trajectory), NOT 'late_throttle' symptom!
        $plan = $lastResp->json('coaching_plan');
        $this->assertNotNull($plan);
        $this->assertSame('narrow_entry', $plan['primary_issue']);
        $this->assertTrue($plan['telemetry_breakdown']['has_trajectory']);
        $this->assertGreaterThan(1.2, $plan['telemetry_breakdown']['entry_inside_m']);
        $this->assertStringContainsString('ábrete bien', $plan['triggers'][0]['text']);

        // Verify 2D Top-Down Trajectory Map builds cleanly for the focus corner
        $session = DrivingSession::query()->findOrFail($lastResp->json('driving_session_id'));
        $focusCorner = collect($spa->corners)->firstWhere('turn', $plan['focus_turn']);
        $map = $normalizer->buildCornerTrajectoryMap($ref, $drvSamples, $focusCorner);
        $this->assertNotNull($map);
        $this->assertGreaterThanOrEqual(4, count($map['ref_points']));
        $this->assertGreaterThanOrEqual(4, count($map['driver_points']));
    }

    public function test_incomparable_reference_switches_to_session_self_coaching_and_detects_abs_and_brake_drag(): void
    {
        $user = User::factory()->create([
            'name' => 'Vicent Grimaltos',
            'driver_callsign' => 'Vicent',
            'preferences' => ['min_clean_laps' => 2],
        ]);
        $token = $user->createToken('SimRig')->plainTextToken;

        // 1. Track is scorching at 43.5 °C (closest Spa reference is 33.0 °C -> ΔT = 10.5 °C > 7 °C limit!)
        $brief = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/telemetry/briefing', [
                'track_slug' => 'spa-up',
                'car_name' => 'Porsche 911 GT3 R (992)',
                'session_id' => 9801,
                'sub_session_id' => 9901,
                'track_temp_c' => 43.5,
                'air_temp_c' => 31.0,
                'is_wet' => false,
                'reason' => 'session_start',
            ]);
        $brief->assertOk();
        $this->assertNull($brief->json('matched_reference_lap_id'));
        $this->assertStringContainsString('no ser comparable', $brief->json('radio_callouts.0.text'));

        // 2. Drive 2 clean laps at 43.5 °C with NO external reference, abusing ABS in Turn 1 (flags=8)
        // and dragging 3% left-foot brake at full throttle on Kemmel straight!
        $lastResp = null;
        for ($lapNum = 1; $lapNum <= 2; $lapNum++) {
            $samples = $this->buildGeoTrajectorySamples(141000, narrowEntryTurn5Meters: 0.0, lateThrottleTurn5: false);
            foreach ($samples as &$s) {
                // Heavy ABS active across Turn 1 La Source braking zone
                if (($s['pct'] >= 0.965 || $s['pct'] <= 0.015) && $s['brake'] < 0.25) {
                    $s['brake'] = 0.92;
                }
                if ($s['pct'] >= 0.965 || $s['pct'] <= 0.015) {
                    $s['flags'] = 8; // FlagABSActive (not dirty!)
                }
                // Left-foot 3% brake drag on Kemmel straight (0.12 .. 0.22)
                if ($s['pct'] >= 0.12 && $s['pct'] <= 0.22) {
                    $s['throttle'] = 1.0;
                    $s['brake'] = 0.035;
                    $s['speed'] = 71.0;
                }
            }
            unset($s);

            $lastResp = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/telemetry/lap', [
                    'track_slug' => 'spa-up',
                    'car_name' => 'Porsche 911 GT3 R (992)',
                    'session_id' => 9801,
                    'sub_session_id' => 9901,
                    'lap_number' => $lapNum,
                    'lap_time_ms' => 141000,
                    'is_clean' => true,
                    'track_temp_c' => 43.5,
                    'air_temp_c' => 31.0,
                    'samples' => $samples,
                ]);
            $lastResp->assertCreated();
            if ($lapNum === 1) {
                $callouts1 = implode(' | ', array_column($lastResp->json('radio_callouts'), 'text'));
                $this->assertStringContainsString('rozando el freno en recta', $callouts1);
            }
        }

        // Verify Self-Reference mode activated WITHOUT external reference and caught ABS abuse!
        $this->assertNull($lastResp->json('matched_reference_lap_id'));
        $plan = $lastResp->json('coaching_plan');
        $this->assertNotNull($plan);
        $this->assertSame('session_self', $plan['reference_mode']);
        $this->assertSame('abs_abuse', $plan['primary_issue']);
        $this->assertStringContainsString('ABS', $plan['triggers'][0]['text']);
    }

    public function test_collective_learning_auto_discovers_track_corners_anonymizes_community_pool_and_detects_under_driving_grip(): void
    {
        $pilotA = User::factory()->create([
            'name' => 'Alex Rapido',
            'driver_callsign' => 'Alex',
            'preferences' => ['min_clean_laps' => 2],
        ]);
        $tokenA = $pilotA->createToken('RigA')->plainTextToken;

        // 1. Pilot A drives 2 fast clean laps on a brand-new uncurated circuit ('navarra-gp') pulling 1.65G lateral
        foreach ([1 => 98200, 2 => 97800] as $lapNum => $lapMs) {
            $this->withHeader('Authorization', "Bearer {$tokenA}")
                ->postJson('/api/v1/telemetry/lap', [
                    'track_slug' => 'navarra-gp',
                    'track_name' => 'Circuito de Navarra',
                    'track_length_km' => 3.933,
                    'car_name' => 'Porsche 911 GT3 R (992)',
                    'session_id' => 6100,
                    'sub_session_id' => 6200,
                    'lap_number' => $lapNum,
                    'lap_time_ms' => $lapMs,
                    'is_clean' => true,
                    'track_temp_c' => 26.0,
                    'air_temp_c' => 19.5,
                    'samples' => $this->buildNavarraGripSamples($lapMs, cornerLatG: 1.65, earlyBrakeMeters: 0.0),
                ])->assertCreated();
        }

        // Verify zero-config corner auto-discovery mapped Navarra's 3 real corners automatically
        $navarra = Track::query()->where('slug', 'navarra-gp')->firstOrFail();
        $this->assertTrue($navarra->corners_is_curated);
        $this->assertCount(3, $navarra->corners);

        // Verify 100% anonymized community pool reference (driver_name = null, user_id = null)
        $communityRef = ReferenceLap::query()
            ->where('track_id', $navarra->id)
            ->where('source', 'pacefriend_community')
            ->firstOrFail();
        $this->assertNull($communityRef->user_id);
        $this->assertNull($communityRef->driver_name);
        $this->assertSame(97800, $communityRef->lap_time_ms);

        // Verify CarPhysicsProfile learned the 1.65G lateral grip ceiling
        $carProfile = \App\Models\CarPhysicsProfile::query()
            ->where('car_name', 'Porsche 911 GT3 R (992)')
            ->firstOrFail();
        $this->assertGreaterThan(1.55, $carProfile->p90_lat_g);

        // 2. Pilot B joins Navarra WITHOUT Garage 61 and drives 3 slower laps (100.4s -> 100.1s -> 99.8s)
        $this->app['auth']->forgetGuards();
        $pilotB = User::factory()->create([
            'name' => 'Vicent Grimaltos',
            'driver_callsign' => 'Vicent',
            'preferences' => ['min_clean_laps' => 2],
        ]);
        $tokenB = $pilotB->createToken('RigB')->plainTextToken;

        $respB2 = null;
        foreach ([1 => 100400, 2 => 100100, 3 => 99800] as $lapNum => $lapMs) {
            $resp = $this->withHeader('Authorization', "Bearer {$tokenB}")
                ->postJson('/api/v1/telemetry/lap', [
                    'track_slug' => 'navarra-gp',
                    'track_name' => 'Circuito de Navarra',
                    'track_length_km' => 3.933,
                    'car_name' => 'Porsche 911 GT3 R (992)',
                    'session_id' => 6300,
                    'sub_session_id' => 6400,
                    'lap_number' => $lapNum,
                    'lap_time_ms' => $lapMs,
                    'is_clean' => true,
                    'track_temp_c' => 26.5,
                    'air_temp_c' => 20.0,
                    'samples' => $this->buildNavarraGripSamples($lapMs, cornerLatG: 1.45, earlyBrakeMeters: 28.0),
                ]);
            $resp->assertCreated();
            if ($lapNum === 2) {
                $respB2 = $resp;
            }
        }

        // Pilot B is automatically coached against Pilot A's anonymized community lap, and slower laps NEVER overwrite 97800ms!
        $this->assertSame($communityRef->id, $respB2->json('matched_reference_lap_id'));
        $this->assertNotNull($respB2->json('coaching_plan'));
        $this->assertSame(97800, $communityRef->fresh()->lap_time_ms);
        $this->assertGreaterThanOrEqual(1, \App\Models\CoachingOutcome::query()->where('track_id', $navarra->id)->count());

        // 3. Now Pilot B drives at scorching 42.0 °C (no external reference -> session_self) using only 0.85G of the 1.65G Grip Circle!
        $hotResp = null;
        for ($lapNum = 1; $lapNum <= 2; $lapNum++) {
            $hotResp = $this->withHeader('Authorization', "Bearer {$tokenB}")
                ->postJson('/api/v1/telemetry/lap', [
                    'track_slug' => 'navarra-gp',
                    'track_name' => 'Circuito de Navarra',
                    'track_length_km' => 3.933,
                    'car_name' => 'Porsche 911 GT3 R (992)',
                    'session_id' => 6500,
                    'sub_session_id' => 6600,
                    'lap_number' => $lapNum,
                    'lap_time_ms' => 102500,
                    'is_clean' => true,
                    'track_temp_c' => 42.0,
                    'air_temp_c' => 31.0,
                    'samples' => $this->buildNavarraGripSamples(102500, cornerLatG: 0.85, earlyBrakeMeters: 0.0),
                ]);
            $hotResp->assertCreated();
        }

        $gripPlan = $hotResp->json('coaching_plan');
        $this->assertNotNull($gripPlan);
        $this->assertSame('session_self', $gripPlan['reference_mode']);
        $this->assertSame('under_driving_grip', $gripPlan['primary_issue']);
        $this->assertStringContainsString('Vicent,', $gripPlan['triggers'][0]['text']);
        $this->assertStringContainsString('margen de goma', $gripPlan['triggers'][0]['text']);
    }

    /**
     * Build synthetic 250-sample telemetry for a 3-corner circuit with configurable Friction Circle lateral G.
     *
     * @return array<int, array<string, float|int>>
     */
    private function buildNavarraGripSamples(int $lapTimeMs, float $cornerLatG, float $earlyBrakeMeters): array
    {
        $samples = [];
        $steps = 250;
        $trackMeters = 3933.0;
        $stepMeters = $trackMeters / $steps;
        $earlyPctOffset = $earlyBrakeMeters / $trackMeters;
        $elapsedMs = 0.0;

        $zones = [
            [0.150, 0.180, 0.215],
            [0.450, 0.480, 0.515],
            [0.750, 0.780, 0.815],
        ];

        for ($i = 0; $i < $steps; $i++) {
            $pct = round($i / $steps, 4);
            $speed = 66.0;
            $throttle = 1.0;
            $brake = 0.0;
            $latG = 0.05;
            $longG = 0.25;
            $gear = 5;

            foreach ($zones as $idx => [$bPct, $aPct, $ePct]) {
                $effBrakePct = $idx === 0 ? ($bPct - $earlyPctOffset) : $bPct;
                if ($pct >= $effBrakePct && $pct <= $ePct) {
                    if ($pct < $aPct) {
                        $speed = $pct < ($aPct - 0.010) ? 44.0 : 29.0;
                        $brake = 0.82;
                        $throttle = 0.0;
                        $longG = -1.25;
                        $latG = $cornerLatG * 0.65;
                    } else {
                        $speed = $pct < ($aPct + 0.012) ? 28.5 : 46.0;
                        $brake = 0.0;
                        $throttle = $pct >= ($aPct + 0.008) ? 0.96 : 0.25;
                        $longG = 0.45;
                        $latG = $cornerLatG;
                    }
                    $gear = 2;
                    break;
                }
            }

            $steerRad = 0.0;
            if ($latG > 0.3) {
                // Smooth progressive turn-in so dSteer/dt stays realistic (< 120 deg/s)
                $steerRad = $brake > 0.1 ? -0.14 : -0.28;
            }

            $samples[] = [
                't_ms' => (int) round($elapsedMs),
                'pct' => $pct,
                'speed' => $speed,
                'throttle' => $throttle,
                'brake' => $brake,
                'lat_g' => round($latG, 3),
                'long_g' => round($longG, 3),
                'steer' => $steerRad,
                'yaw' => $latG > 0.3 ? -0.28 : 0.0,
                'lap' => 1,
                'gear' => $gear,
                'flags' => 0,
            ];
            $elapsedMs += ($stepMeters / $speed) * 1000.0;
        }

        $scale = $elapsedMs > 0 ? ($lapTimeMs / $elapsedMs) : 1.0;
        foreach ($samples as &$s) {
            $s['t_ms'] = (int) round($s['t_ms'] * $scale);
        }

        return $samples;
    }

    /**
     * Build synthetic Spa telemetry with realistic 64-bit Lat/Lon heading right at Turn 5 (Les Combes, pct ~ 0.28..0.33).
     *
     * @return array<int, array<string, float|int>>
     */
    private function buildGeoTrajectorySamples(int $lapTimeMs, float $narrowEntryTurn5Meters, bool $lateThrottleTurn5): array
    {
        $samples = [];
        $steps = 250;
        $lat = 50.4372000;
        $lon = 5.9714000;
        $headingRad = M_PI / 2.0; // Travelling due North (+Y) approaching Les Combes
        $stepMeters = 7004.0 / $steps;
        $elapsedMs = 0.0;

        for ($i = 0; $i < $steps; $i++) {
            $pct = round($i / $steps, 4);
            $speed = 70.0;
            $throttle = 1.0;
            $brake = 0.0;
            $steer = 0.0;
            $yaw = 0.0;

            // Right-hand corner around Les Combes (0.270 .. 0.330)
            if ($pct >= 0.270 && $pct <= 0.330) {
                $speed = $narrowEntryTurn5Meters > 0 ? 33.0 : 39.0; // Slower due to narrow entry!
                $brake = $pct <= 0.294 ? 0.85 : 0.0;
                $fullGasPct = $lateThrottleTurn5 ? 0.318 : 0.302;
                $throttle = $pct >= $fullGasPct ? 0.98 : 0.15;
                $headingRad -= 0.11; // Turning clockwise (Right-hander: turn_dir = -1, inside = East/+X)
                $steer = -0.45;
                $yaw = -0.28;
            }

            $lat += ($stepMeters * sin($headingRad)) / 110540.0;
            $lon += ($stepMeters * cos($headingRad)) / (111320.0 * cos(deg2rad(50.4372)));

            // Apply lateral shift toward inside (+East when travelling North into a right-hander) at Turn 5 entry!
            $sampleLon = $lon;
            if ($narrowEntryTurn5Meters > 0 && $pct >= 0.265 && $pct <= 0.295) {
                $sampleLon += $narrowEntryTurn5Meters / (111320.0 * cos(deg2rad(50.4372)));
            }

            $samples[] = [
                't_ms' => (int) round($elapsedMs),
                'pct' => $pct,
                'speed' => $speed,
                'throttle' => $throttle,
                'brake' => $brake,
                'steer' => $steer,
                'yaw' => $yaw,
                'lat' => round($lat, 7),
                'lon' => round($sampleLon, 7),
                'lap' => 1,
                'gear' => 4,
                'flags' => 0,
            ];

            $elapsedMs += ($stepMeters / $speed) * 1000.0;
        }

        $scale = $elapsedMs > 0 ? ($lapTimeMs / $elapsedMs) : 1.0;
        foreach ($samples as &$s) {
            $s['t_ms'] = (int) round($s['t_ms'] * $scale);
        }

        return $samples;
    }

    /**
     * Synthesize a byte-exact binary .ibt telemetry buffer for testing IbtFileParserService.
     */
    private function buildSyntheticBinaryIbtFile(float $trackTempC, float $airTempC): string
    {
        $yaml = "---\nWeekendInfo:\n TrackName: spa up\n TrackLength: 7.00 km\n TrackDisplayName: Circuit de Spa-Francorchamps\nDriverInfo:\n DriverCarIdx: 1\n Drivers:\n - CarIdx: 1\n   CarScreenName: Porsche 911 GT3 R (992)\n\0";

        $channels = [
            ['Speed', 4, 0],
            ['Brake', 4, 4],
            ['Throttle', 4, 8],
            ['Lap', 2, 12],
            ['LapDistPct', 4, 16],
            ['Gear', 2, 20],
            ['SessionTime', 5, 24],
            ['PlayerTrackSurface', 2, 32],
            ['TrackTempCrew', 4, 36],
            ['AirTemp', 4, 40],
        ];

        $varHdrOffset = 176; // 112 header + 64 diskSubHeader
        $varHdrBytes = '';
        foreach ($channels as [$name, $type, $offset]) {
            $entry = pack('VVV', $type, $offset, 1)
                .str_repeat("\0", 4)
                .str_pad($name, 32, "\0")
                .str_pad('', 64, "\0")
                .str_pad('', 32, "\0");
            $varHdrBytes .= $entry;
        }

        $yamlOffset = $varHdrOffset + strlen($varHdrBytes);
        $dataOffset = $yamlOffset + strlen($yaml);
        $bufLen = 48;
        $records = 80;

        $rows = '';
        for ($i = 0; $i < $records; $i++) {
            $pct = $i / ($records - 1);
            $simTime = 10.0 + ($pct * 137.5);
            $rows .= pack('g', 68.0)              // Speed
                .pack('g', $pct > 0.97 ? 0.9 : 0) // Brake
                .pack('g', $pct > 0.97 ? 0.0 : 1) // Throttle
                .pack('l', 1)                     // Lap 1
                .pack('g', $pct)                  // LapDistPct
                .pack('l', 5)                     // Gear
                .pack('e', $simTime)              // SessionTime (double)
                .pack('l', 3)                     // PlayerTrackSurface = OnTrack (3)
                .pack('g', $trackTempC)           // TrackTempCrew
                .pack('g', $airTempC)             // AirTemp
                .str_repeat("\0", 4);
        }

        // 112-byte irsdk_header
        $header = pack(
            'VVVVVVVVVV',
            2,
            1,
            20,
            1,
            strlen($yaml),
            $yamlOffset,
            count($channels),
            $varHdrOffset,
            1,
            $bufLen
        )
        .str_repeat("\0", 8) // pad1[2]
        .pack('VV', 1, $dataOffset).str_repeat("\0", 8)
        .str_repeat("\0", 48); // remaining 3 varBuf descriptors

        // 64-byte irsdk_diskSubHeader
        $diskSubHeader = pack('PeeVV', time(), 10.0, 147.5, 1, $records).str_repeat("\0", 32);

        return $header.$diskSubHeader.$varHdrBytes.$yaml.$rows;
    }

    /**
     * @return array<int, array<string, float|int>>
     */
    private function buildDriverEarlyBrakingSamples(int $lapTimeMs): array
    {
        $samples = [];
        $steps = 250;
        $elapsedMs = 0.0;
        $stepMeters = 7004.0 / $steps;

        for ($i = 0; $i < $steps; $i++) {
            $pct = round($i / $steps, 4);
            $speed = 74.0;
            $throttle = 1.0;
            $brake = 0.0;
            $gear = 6;

            // Chronic early braking starting at 0.964 instead of optimal 0.980 in Turn 1 La Source (~112m early / slow segment!)
            if ($pct >= 0.964 || $pct <= 0.032) {
                $speed = $pct <= 0.015 ? 18.5 : 27.0;
                $brake = $pct <= 0.014 || $pct >= 0.964 ? 0.85 : 0.0;
                $throttle = ($pct > 0.018 && $pct <= 0.032) ? 0.95 : 0.0;
                $gear = 1;
            } elseif ($pct >= 0.278 && $pct <= 0.326) {
                $speed = $pct < 0.296 ? 38.0 : 52.0;
                $brake = $pct < 0.294 ? 0.85 : 0.0;
                $throttle = $pct >= 0.297 ? 0.95 : 0.0;
                $gear = 3;
            } elseif ($pct >= 0.428 && $pct <= 0.474) {
                $speed = $pct < 0.449 ? 29.0 : 48.0;
                $brake = $pct < 0.446 ? 0.80 : 0.0;
                $throttle = $pct >= 0.451 ? 0.95 : 0.0;
                $gear = 2;
            } elseif ($pct >= 0.898 && $pct <= 0.948) {
                $speed = $pct < 0.921 ? 23.0 : 45.0;
                $brake = $pct < 0.918 ? 0.90 : 0.0;
                $throttle = $pct >= 0.924 ? 0.95 : 0.0;
                $gear = 2;
            }

            $samples[] = [
                't_ms' => (int) round($elapsedMs),
                'pct' => $pct,
                'speed' => $speed,
                'throttle' => $throttle,
                'brake' => $brake,
                'lap' => 1,
                'gear' => $gear,
                'flags' => 0,
            ];
            $elapsedMs += ($stepMeters / $speed) * 1000.0;
        }

        $scale = $elapsedMs > 0 ? ($lapTimeMs / $elapsedMs) : 1.0;
        foreach ($samples as &$s) {
            $s['t_ms'] = (int) round($s['t_ms'] * $scale);
        }

        return $samples;
    }
}
