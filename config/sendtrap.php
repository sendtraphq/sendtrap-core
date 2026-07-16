<?php

return [
    /*
     * The address/port the SMTP ingestion daemon binds to locally.
     */
    'smtp_bind' => env('SENDTRAP_SMTP_BIND', '0.0.0.0'),
    'smtp_port' => (int) env('SENDTRAP_SMTP_PORT', 1025),

    /*
     * The public-facing SMTP host/port shown to users in inbox credentials
     * (what they point their app's MAIL_HOST / MAIL_PORT at).
     */
    'public_host' => env('SENDTRAP_PUBLIC_HOST', 'localhost'),
    'public_ports' => array_map('intval', explode(',', env('SENDTRAP_PUBLIC_PORTS', '1025,2525,587'))),

    /*
     * Maximum accepted message size in bytes (advertised via the SIZE extension).
     */
    'max_size' => (int) env('SENDTRAP_MAX_SIZE', 25 * 1024 * 1024),

    /*
     * STARTTLS support. When enabled, the daemon advertises STARTTLS and lets
     * clients upgrade the connection to TLS. Provide a cert/key, or leave them
     * unset to auto-generate a self-signed cert (typical for an email sandbox).
     */
    'tls' => filter_var(env('SENDTRAP_TLS', true), FILTER_VALIDATE_BOOL),
    'tls_cert' => env('SENDTRAP_TLS_CERT'),
    'tls_key' => env('SENDTRAP_TLS_KEY'),

    /*
     * Hardening limits for the SMTP daemon (abuse / DoS protection).
     */
    'max_connections' => (int) env('SENDTRAP_MAX_CONNECTIONS', 200),
    'max_connections_per_ip' => (int) env('SENDTRAP_MAX_CONNECTIONS_PER_IP', 10),
    'idle_timeout' => (int) env('SENDTRAP_IDLE_TIMEOUT', 60),       // seconds
    'max_session' => (int) env('SENDTRAP_MAX_SESSION', 300),        // seconds, hard cap
    'max_auth_attempts' => (int) env('SENDTRAP_MAX_AUTH_ATTEMPTS', 3),
    'max_errors' => (int) env('SENDTRAP_MAX_ERRORS', 10),
    'max_recipients' => (int) env('SENDTRAP_MAX_RECIPIENTS', 100),
    'max_line' => (int) env('SENDTRAP_MAX_LINE', 4096),            // bytes per command line
    'require_tls' => filter_var(env('SENDTRAP_REQUIRE_TLS', false), FILTER_VALIDATE_BOOL),

    /*
     * Content lint thresholds used by Sendtrap\Core\Support\MessageLinter.
     */
    'lint' => [
        'max_html_bytes' => (int) env('SENDTRAP_LINT_MAX_HTML_BYTES', 100 * 1024),
    ],

    /*
     * Hard ceiling on the `wait` query param / POST /assert timeout (seconds).
     * Kept well under the reverse proxy's read timeout so a blocking request
     * never gets killed mid-wait, and short enough that a busy-wait loop
     * can't tie up an HTTP worker for long.
     */
    'wait_max_seconds' => (int) env('SENDTRAP_WAIT_MAX_SECONDS', 30),

    // Note: the `live_check` key that used to live here is Cloud ops config,
    // not core — it now lives in the host's own config/sendtrap-live-check.php
    // (Plan 06 Phase 3b slice 1, §1.8 L-6).
];
