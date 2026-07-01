<?php
// lib/DB.php
// @version 1.0.1

class DB {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $cfg = require __DIR__.'/../config/config.php';
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;options=--timezone=UTC',
                $cfg['db']['host'],
                $cfg['db']['port'],
                $cfg['db']['name'],
            );
            self::$instance = new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Always work in UTC at the connection level
            self::$instance->exec("SET timezone = 'UTC'");
            self::ensureInsightsDailySchema(self::$instance);
            self::ensureAdsSchema(self::$instance);
        }
        return self::$instance;
    }

    private static function ensureInsightsDailySchema(PDO $db): void
    {
        $db->exec("
            ALTER TABLE IF EXISTS insights_daily
                ADD COLUMN IF NOT EXISTS sub_id_10 TEXT,
                ADD COLUMN IF NOT EXISTS sub_id_11 TEXT,
                ADD COLUMN IF NOT EXISTS sub_id_12 TEXT,
                ADD COLUMN IF NOT EXISTS sub_id_13 TEXT,
                ADD COLUMN IF NOT EXISTS sub_id_14 TEXT,
                ADD COLUMN IF NOT EXISTS sub_id_15 TEXT,
                ADD COLUMN IF NOT EXISTS kt_synced_at TIMESTAMPTZ
        ");
    }

    private static function ensureAdsSchema(PDO $db): void
    {
        $db->exec("
            ALTER TABLE IF EXISTS ads
                ADD COLUMN IF NOT EXISTS review_feedback_json JSONB,
                ADD COLUMN IF NOT EXISTS issues_info_json JSONB,
                ADD COLUMN IF NOT EXISTS disapproval_reason TEXT,
                ADD COLUMN IF NOT EXISTS review_checked_at TIMESTAMPTZ
        ");
    }

}
