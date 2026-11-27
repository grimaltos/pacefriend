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
