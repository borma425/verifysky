<?php

return [
    'wae_dataset' => env('CLOUDFLARE_WAE_DATASET', 'verifysky_usage'),
    'wae_staging_dataset' => env('CLOUDFLARE_WAE_STAGING_DATASET', 'verifysky_usage_staging'),

    'rates' => [
        'workers_requests_per_million' => (float) env('CLOUDFLARE_COST_WORKERS_REQUESTS_PER_MILLION', 0.30),
        'workers_cpu_per_million_ms' => (float) env('CLOUDFLARE_COST_WORKERS_CPU_PER_MILLION_MS', 0.02),
        'd1_rows_read_per_million' => (float) env('CLOUDFLARE_COST_D1_ROWS_READ_PER_MILLION', 0.001),
        'd1_rows_written_per_million' => (float) env('CLOUDFLARE_COST_D1_ROWS_WRITTEN_PER_MILLION', 1.00),
        'kv_reads_per_million' => (float) env('CLOUDFLARE_COST_KV_READS_PER_MILLION', 0.50),
        'kv_writes_per_million' => (float) env('CLOUDFLARE_COST_KV_WRITES_PER_MILLION', 5.00),
        'kv_deletes_per_million' => (float) env('CLOUDFLARE_COST_KV_DELETES_PER_MILLION', 5.00),
        'kv_lists_per_million' => (float) env('CLOUDFLARE_COST_KV_LISTS_PER_MILLION', 5.00),
    ],
];
