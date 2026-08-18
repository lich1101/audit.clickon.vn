<?php

return [
    'dry_run' => filter_var(env('INDEX_DRY_RUN', true), FILTER_VALIDATE_BOOLEAN),
    'sa_json_path' => env('INDEX_SA_JSON_PATH', storage_path('app/google-indexing-sa.json')),
    'default_gcp_project_key' => env('INDEX_GCP_PROJECT_KEY', 'default'),
    'daily_publish_quota' => (int) env('INDEX_DAILY_PUBLISH_QUOTA', 200),
    'daily_inspect_quota' => (int) env('INDEX_DAILY_INSPECT_QUOTA', 2000),
    'ca_bundle' => env('INDEX_CA_BUNDLE', dirname(base_path()).'/.tools/php84/extras/ssl/cacert.pem'),
    'publish_batch_size' => (int) env('INDEX_PUBLISH_BATCH_SIZE', 50),
    'stale_sending_minutes' => (int) env('INDEX_STALE_SENDING_MINUTES', 15),
];
