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
        'deep_research_agent' => env('GEMINI_DEEP_RESEARCH_AGENT', 'deep-research-preview-04-2026'),
        'timeout_seconds' => (int) env('GEMINI_TIMEOUT_SECONDS', 180),
        'deep_research_timeout_seconds' => (int) env('GEMINI_DEEP_RESEARCH_TIMEOUT_SECONDS', 1800),
    ],

    'audit' => [
        'max_content_chars' => (int) env('AUDIT_MAX_CONTENT_CHARS', 18000),
        'max_category_content_chars' => (int) env('AUDIT_MAX_CATEGORY_CONTENT_CHARS', 7000),
        'user_agent' => env('AUDIT_USER_AGENT', 'ClickonAuditBot/1.0 (+https://clickon-audit.local)'),
        'use_jina' => (bool) env('AUDIT_USE_JINA_READER', true),
        'jina_base_url' => env('AUDIT_JINA_BASE_URL', 'https://r.jina.ai/'),
        'jina_api_key' => env('JINA_API_KEY'),
        'firestore_sync' => (bool) env('AUDIT_FIRESTORE_SYNC', false),
        'firestore_fallback' => (bool) env('AUDIT_FIRESTORE_FALLBACK', true),
    ],

];
