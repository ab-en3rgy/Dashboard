<?php
// config/config.php
// Sanitized example config for source archive. Fill values from environment or local secrets.

if (!function_exists('appEnvValue')) {
    function appEnvValue(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }

        static $localEnv = null;
        if ($localEnv === null) {
            $localEnv = [];
            $localEnvFile = __DIR__ . '/local.env';
            if (is_file($localEnvFile)) {
                $lines = file($localEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    if (str_starts_with($line, 'export ')) {
                        $line = trim(substr($line, 7));
                    }
                    $pos = strpos($line, '=');
                    if ($pos === false) {
                        continue;
                    }
                    $key = trim(substr($line, 0, $pos));
                    if ($key === '') {
                        continue;
                    }
                    $rawValue = trim(substr($line, $pos + 1));
                    $len = strlen($rawValue);
                    if ($len >= 2) {
                        $first = $rawValue[0];
                        $last = $rawValue[$len - 1];
                        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                            $rawValue = substr($rawValue, 1, -1);
                        }
                    }
                    $localEnv[$key] = $rawValue;
                }
            }
        }

        return $localEnv[$name] ?? $default;
    }
}

return [
    'db' => [
        'host' => appEnvValue('DB_HOST', '127.0.0.1'),
        'port' => (int) appEnvValue('DB_PORT', '5432'),
        'name' => appEnvValue('DB_NAME', 'fb_ads'),
        'user' => appEnvValue('DB_USER', 'fb_ads_user'),
        'pass' => appEnvValue('DB_PASS', ''),
    ],

    'display_tz' => appEnvValue('DISPLAY_TZ', 'Europe/Chisinau'),

    'fbtool' => [
        'key' => appEnvValue('FBTOOL_KEY', ''),
        'url' => appEnvValue('FBTOOL_URL', 'https://fbtool.pro/'),
        'account_ids' => array_filter(array_map('trim', explode(',', appEnvValue('FBTOOL_ACCOUNT_IDS', '')))),
        'cookie_header' => appEnvValue('FBTOOL_COOKIE', ''),
        'user_agent' => appEnvValue('FBTOOL_USER_AGENT', ''),
        'accounts_cache_ttl_hours' => (int) appEnvValue('FBTOOL_ACCOUNTS_CACHE_TTL_HOURS', '24'),
    ],

    'auto_rules' => [
        'skip_cpc' => filter_var(appEnvValue('AUTO_RULES_SKIP_CPC', '1'), FILTER_VALIDATE_BOOLEAN),
        'low_c2l_today_guard_enabled' => filter_var(appEnvValue('AUTO_RULES_LOW_C2L_TODAY_GUARD_ENABLED', '1'), FILTER_VALIDATE_BOOLEAN),
        'low_c2l_today_bad_factor' => (float) appEnvValue('AUTO_RULES_LOW_C2L_TODAY_BAD_FACTOR', '3.0'),
        'low_c2l_today_min_clicks' => (float) appEnvValue('AUTO_RULES_LOW_C2L_TODAY_MIN_CLICKS', '10.0'),
    ],

    'auto_rules_v2' => [
        'enabled' => filter_var(appEnvValue('AUTO_RULES_V2_ENABLED', '1'), FILTER_VALIDATE_BOOLEAN),
        'shadow_only' => filter_var(appEnvValue('AUTO_RULES_V2_SHADOW_ONLY', '0'), FILTER_VALIDATE_BOOLEAN),
        'act_on_pause_today' => filter_var(appEnvValue('AUTO_RULES_V2_ACT_ON_PAUSE_TODAY', '0'), FILTER_VALIDATE_BOOLEAN),
        'act_on_tomorrow_restart' => filter_var(appEnvValue('AUTO_RULES_V2_ACT_ON_TOMORROW_RESTART', '0'), FILTER_VALIDATE_BOOLEAN),
        'act_on_hold_stop' => filter_var(appEnvValue('AUTO_RULES_V2_ACT_ON_HOLD_STOP', '0'), FILTER_VALIDATE_BOOLEAN),
        'intraday_baseline_tolerance' => (float) appEnvValue('AUTO_RULES_V2_INTRADAY_BASELINE_TOLERANCE', '1.05'),
        'strict_profit_payouts' => (float) appEnvValue('AUTO_RULES_V2_STRICT_PROFIT_PAYOUTS', '5.0'),
        'test_stop_payouts' => (float) appEnvValue('AUTO_RULES_V2_TEST_STOP_PAYOUTS', '0.5'),
        'restart_hysteresis_hours' => (float) appEnvValue('AUTO_RULES_V2_RESTART_HYSTERESIS_HOURS', '6.0'),
        'no_click_expected_clicks' => (float) appEnvValue('AUTO_RULES_V2_NO_CLICK_EXPECTED_CLICKS', '3.0'),
        'min_intraday_spend_ratio' => (float) appEnvValue('AUTO_RULES_V2_MIN_INTRADAY_SPEND_RATIO', '0.5'),
        'balanced_mode' => filter_var(appEnvValue('AUTO_RULES_V2_BALANCED_MODE', '1'), FILTER_VALIDATE_BOOLEAN),
        'v1_stop_guard' => filter_var(appEnvValue('AUTO_RULES_V2_V1_STOP_GUARD', '1'), FILTER_VALIDATE_BOOLEAN),
        'breathe_payouts' => (float) appEnvValue('AUTO_RULES_V2_BREATHE_PAYOUTS', '2.0'),
        'high_confidence_payouts' => (float) appEnvValue('AUTO_RULES_V2_HIGH_CONFIDENCE_PAYOUTS', '3.0'),
        'protect_min_p_profit' => (int) appEnvValue('AUTO_RULES_V2_PROTECT_MIN_P_PROFIT', '90'),
        'start_min_p_profit' => (int) appEnvValue('AUTO_RULES_V2_START_MIN_P_PROFIT', '80'),
        'stop_on_v1_stop_after_payouts' => (float) appEnvValue('AUTO_RULES_V2_STOP_ON_V1_STOP_AFTER_PAYOUTS', '1.0'),
        'review_min_p_profit' => (int) appEnvValue('AUTO_RULES_V2_REVIEW_MIN_P_PROFIT', '65'),
        'high_confidence_min_p_profit' => (int) appEnvValue('AUTO_RULES_V2_HIGH_CONFIDENCE_MIN_P_PROFIT', '80'),
    ],

    'telegram' => [
        'bot_token' => appEnvValue('TELEGRAM_BOT_TOKEN', ''),
        'chat_id' => appEnvValue('TELEGRAM_CHAT_ID', ''),
    ],

    'keitaro' => [
        'url' => appEnvValue('KEITARO_URL', ''),
        'key' => appEnvValue('KEITARO_KEY', ''),
        'sub_id' => appEnvValue('KEITARO_SUB_ID', 'sub_id_1'),
        'tz' => appEnvValue('KEITARO_TZ', 'UTC'),
        'insecure_ssl' => filter_var(appEnvValue('KEITARO_INSECURE_SSL', '0'), FILTER_VALIDATE_BOOLEAN),
    ],

    'extension_secret' => appEnvValue('EXTENSION_SECRET', ''),

    'pexels' => [
        'api_key' => appEnvValue('PEXELS_API_KEY', ''),
    ],
];
