<?php

namespace App\Jobs;

use App\Models\DrivingSession;
use App\Services\Garage61\Garage61ImporterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncGarage61AutopilotJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $drivingSessionId,
        public readonly int $driverCleanPaceMs,
    ) {}

    public function handle(Garage61ImporterService $importer): void
    {
        $session = DrivingSession::query()->find($this->drivingSessionId);
        if ($session === null || $session->ended_at !== null) {
            return;
        }

        $importer->syncAutopilotForSession($session, $this->driverCleanPaceMs);
    }
}
