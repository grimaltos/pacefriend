<?php

namespace App\Console\Commands;

use App\Models\Track;
use Illuminate\Console\Command;

class SyncTracksCatalogCommand extends Command
{
    protected $signature = 'tracks:sync {--file= : Optional path to a custom JSON catalog}';

    protected $description = 'Sincroniza el catálogo curado de circuitos (tracks_catalog.json) en base de datos sin borrar sesiones.';

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path('data/tracks_catalog.json');
        if (! is_file($path)) {
            $this->error("No se encontró el archivo de catálogo: {$path}");
            return self::FAILURE;
        }

        $entries = json_decode((string) file_get_contents($path), true);
        if (! is_array($entries)) {
            $this->error('El archivo JSON de circuitos no tiene un formato válido.');
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($entries as $entry) {
            if (empty($entry['slug']) || empty($entry['name']) || empty($entry['corners'])) {
                continue;
            }
            Track::query()->updateOrCreate(
                ['slug' => $entry['slug']],
                [
                    'name' => $entry['name'],
                    'length_km' => (float) ($entry['length_km'] ?? 5.0),
                    'corners' => $entry['corners'],
                ]
            );
            $count++;
        }

        $this->info("Sincronizados {$count} circuitos tácticos correctamente.");
        return self::SUCCESS;
    }
}
