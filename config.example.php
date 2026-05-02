<?php
/**
 * ScholarHub Configuration
 * ------------------------------------------------
 * 1. Copy this file and rename it to: config.php
 * 2. Fill in your real values below
 * 3. config.php is gitignored — never commit secrets
 *
 * Get Supabase values from: https://supabase.com → Project → Settings → API
 * Get Groq key from: https://console.groq.com/keys
 */

define('SUPABASE_URL',         'https://your-project-id.supabase.co');
define('SUPABASE_ANON_KEY',    'your-anon-key-here');
define('SUPABASE_SERVICE_KEY', 'your-service-role-key-here');
define('SESSION_SECRET',       'a-very-long-random-secret-at-least-32-chars');
define('ADMIN_PASSWORD',       'your-admin-password-here');
define('GROQ_API_KEY',         'gsk_your-groq-api-key-here');
