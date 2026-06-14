<?php
// lib/DB.php

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
            self::ensureCampaignGeoFunction(self::$instance);
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

    private static function ensureCampaignGeoFunction(PDO $db): void
    {
        $db->exec("
            CREATE OR REPLACE FUNCTION public.campaign_geo(name TEXT)
            RETURNS TEXT
            LANGUAGE plpgsql
            IMMUTABLE
            AS \$\$
            DECLARE
                parts TEXT[];
                part TEXT;
                i INT;
                candidate TEXT := '';
                max_i INT;
            BEGIN
                IF name IS NULL OR btrim(name) = '' THEN
                    RETURN 'XX';
                END IF;

                parts := string_to_array(name, '_');
                max_i := COALESCE(array_length(parts, 1), 0);
                IF max_i = 0 THEN
                    RETURN 'XX';
                END IF;

                FOR i IN 1..max_i LOOP
                    part := upper(btrim(parts[i]));
                    IF part ~ '^[A-Z]{2}$' AND part NOT IN ('BC', 'CBO', 'ABO', 'SLOT', 'CRASH') AND i >= 4 THEN
                        RETURN part;
                    END IF;
                END LOOP;

                FOR i IN 1..max_i LOOP
                    part := upper(btrim(parts[i]));
                    IF part ~ '^[A-Z]{2}$' AND part NOT IN ('BC', 'CBO', 'ABO', 'SLOT', 'CRASH') THEN
                        candidate := part;
                        EXIT;
                    END IF;
                END LOOP;

                RETURN COALESCE(NULLIF(candidate, ''), 'XX');
            END;
            \$\$;
        ");
    }
}
