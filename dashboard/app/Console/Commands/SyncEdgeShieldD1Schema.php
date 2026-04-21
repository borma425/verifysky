<?php

namespace App\Console\Commands;

use App\Services\EdgeShield\D1SchemaSyncService;
use Illuminate\Console\Command;

class SyncEdgeShieldD1Schema extends Command
{
    protected $signature = 'edgeshield:d1:schema-sync';

    protected $description = 'Bootstrap and reconcile the required EdgeShield D1 schema.';

    public function __construct(private readonly D1SchemaSyncService $schemaSync)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->schemaSync->sync();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $changes = is_array($result['changes'] ?? null) ? $result['changes'] : [];
        $this->info('EdgeShield D1 schema sync completed.');
        $this->line('Changes applied: '.count($changes));
        foreach ($changes as $change) {
            $this->line('- '.$change);
        }

        if ($changes === []) {
            $this->line('- No schema changes were required.');
        }

        return self::SUCCESS;
    }
}
