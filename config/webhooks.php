<?php

return [

    // Backoff between attempts: min(base * 2 ^ (attempts - 1), max).
    'max_attempts' => (int) env('WEBHOOK_MAX_ATTEMPTS', 5),

    'backoff' => [
        'base_seconds' => (int) env('WEBHOOK_BACKOFF_BASE', 30),
        'max_seconds' => (int) env('WEBHOOK_BACKOFF_MAX', 3600),
    ],

    'claim' => [
        'batch_size' => (int) env('WEBHOOK_CLAIM_BATCH_SIZE', 200),
        'max_batch_size' => (int) env('WEBHOOK_CLAIM_MAX_BATCH_SIZE', 1000),

        // Stays under the scheduler interval so runs do not overlap.
        'max_run_seconds' => (int) env('WEBHOOK_CLAIM_MAX_RUN_SECONDS', 8),

        'max_queue_depth' => (int) env('WEBHOOK_MAX_QUEUE_DEPTH', 1000),

        // Must stay well above the HTTP timeout below.
        'stale_lock_minutes' => (int) env('WEBHOOK_STALE_LOCK_MINUTES', 10),
    ],

    'fanout_chunk_size' => (int) env('WEBHOOK_FANOUT_CHUNK_SIZE', 500),

    'max_payload_bytes' => (int) env('WEBHOOK_MAX_PAYLOAD_BYTES', 65536),

    'http' => [
        'timeout' => (int) env('WEBHOOK_HTTP_TIMEOUT', 5),
        'connect_timeout' => (int) env('WEBHOOK_HTTP_CONNECT_TIMEOUT', 3),
    ],

    'queues' => [
        'fanout' => env('WEBHOOK_QUEUE_FANOUT', 'fanout'),
        'delivery' => env('WEBHOOK_QUEUE_DELIVERY', 'webhook-deliveries'),
    ],

];
