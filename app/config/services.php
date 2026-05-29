<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.5'),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'medium'),
        'timeout_seconds' => (int) env('OPENAI_TIMEOUT_SECONDS', 180),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-pro'),
        'deep_research_agent' => env('GEMINI_DEEP_RESEARCH_AGENT', 'deep-research-pro-preview-12-2025'),
        'deep_research_agents' => env('GEMINI_DEEP_RESEARCH_AGENTS', ''),
        'timeout_seconds' => (int) env('GEMINI_TIMEOUT_SECONDS', 180),
        'deep_research_timeout_seconds' => (int) env('GEMINI_DEEP_RESEARCH_TIMEOUT_SECONDS', 0),
    ],

    'perplexity' => [
        'api_key' => env('PERPLEXITY_API_KEY'),
        'base_url' => env('PERPLEXITY_BASE_URL', 'https://api.perplexity.ai'),
        'model' => env('PERPLEXITY_MODEL', 'sonar-deep-research'),
        'models' => env('PERPLEXITY_MODELS', ''),
    ],

    'audit' => [
        'max_content_chars' => (int) env('AUDIT_MAX_CONTENT_CHARS', 18000),
        'max_category_content_chars' => (int) env('AUDIT_MAX_CATEGORY_CONTENT_CHARS', 7000),
        'user_agent' => env('AUDIT_USER_AGENT', 'ClickonAuditBot/1.0 (+https://clickon-audit.local)'),
        'use_jina' => (bool) env('AUDIT_USE_JINA_READER', true),
        'jina_base_url' => env('AUDIT_JINA_BASE_URL', 'https://r.jina.ai/'),
        'jina_api_key' => env('JINA_API_KEY'),
        'firestore_sync' => (bool) env('AUDIT_FIRESTORE_SYNC', false),
        'firestore_fallback' => (bool) env('AUDIT_FIRESTORE_FALLBACK', false),
        'max_ai_step_response_bytes' => (int) env('AUDIT_MAX_AI_STEP_RESPONSE_BYTES', 0),
        'batch_job_timeout_seconds' => (int) env('AUDIT_BATCH_JOB_TIMEOUT_SECONDS', 0),
        'ai_http_timeout_seconds' => (int) env('AUDIT_AI_HTTP_TIMEOUT_SECONDS', 0),
        'ai_http_connect_timeout_seconds' => (int) env('AUDIT_AI_HTTP_CONNECT_TIMEOUT_SECONDS', 30),
        'ai_http_retry_attempts' => (int) env('AUDIT_AI_HTTP_RETRY_ATTEMPTS', 3),
        'ai_http_retry_sleep_ms' => (int) env('AUDIT_AI_HTTP_RETRY_SLEEP_MS', 2000),
        'gemini_pdf_max_bytes' => (int) env('AUDIT_GEMINI_PDF_MAX_BYTES', 10 * 1024 * 1024),
        'gemini_deep_research_watchdog_stale_seconds' => (int) env('AUDIT_GEMINI_DEEP_RESEARCH_WATCHDOG_STALE_SECONDS', 1800),
        'stale_run_recovery_enabled' => (bool) env('AUDIT_STALE_RUN_RECOVERY_ENABLED', true),
        'stale_run_recovery_limit' => (int) env('AUDIT_STALE_RUN_RECOVERY_LIMIT', 20),
        'deep_research_research_provider' => env('AUDIT_DEEP_RESEARCH_RESEARCH_PROVIDER', 'perplexity'),
        'deep_research_research_model' => env('AUDIT_DEEP_RESEARCH_RESEARCH_MODEL', env('PERPLEXITY_MODEL', 'sonar-deep-research')),
        'deep_research_research_reasoning_effort' => env('AUDIT_DEEP_RESEARCH_RESEARCH_REASONING_EFFORT', 'medium'),
        'deep_research_research_use_async' => env('AUDIT_DEEP_RESEARCH_RESEARCH_USE_ASYNC', true),
        'deep_research_async_timeout_seconds' => (int) env('AUDIT_DEEP_RESEARCH_ASYNC_TIMEOUT_SECONDS', 900),
        'deep_research_async_poll_interval_ms' => (int) env('AUDIT_DEEP_RESEARCH_ASYNC_POLL_INTERVAL_MS', 3000),
        'deep_research_async_retry_attempts' => (int) env('AUDIT_DEEP_RESEARCH_ASYNC_RETRY_ATTEMPTS', 2),
        'deep_research_async_retry_sleep_ms' => (int) env('AUDIT_DEEP_RESEARCH_ASYNC_RETRY_SLEEP_MS', 1500),
        'deep_research_reasoning_provider' => env('AUDIT_DEEP_RESEARCH_REASONING_PROVIDER', 'openai'),
        'deep_research_reasoning_model' => env('AUDIT_DEEP_RESEARCH_REASONING_MODEL', env('OPENAI_MODEL', 'gpt-5.5')),
        'deep_research_formatter_provider' => env('AUDIT_DEEP_RESEARCH_FORMATTER_PROVIDER', 'openai'),
        'deep_research_formatter_model' => env('AUDIT_DEEP_RESEARCH_FORMATTER_MODEL', env('OPENAI_MODEL', 'gpt-5.5')),
        'callback_timeout_seconds' => (int) env('AUDIT_CALLBACK_TIMEOUT_SECONDS', 30),
        'callback_retry_attempts' => (int) env('AUDIT_CALLBACK_RETRY_ATTEMPTS', 3),
        'callback_retry_sleep_ms' => (int) env('AUDIT_CALLBACK_RETRY_SLEEP_MS', 2000),
    ],

];
