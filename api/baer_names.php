<?php
// api/baer_names.php
// @version 1.0.1

require __DIR__ . '/_bootstrap.php';

ensureCollationsSchema($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($me['role'] ?? '') !== 'admin') apiError(403, 'Admin only');

    $body = json_decode(file_get_contents('php://input'), true);
    $names = $body['names'] ?? [];
    if (!is_array($names)) apiError(400, 'names must be object');

    $stmt = $db->prepare("
        INSERT INTO public.collation_baer_names (tracking_code, nickname, updated_at)
        VALUES (:code, :nick, NOW())
        ON CONFLICT (tracking_code)
        DO UPDATE SET nickname = EXCLUDED.nickname, updated_at = NOW()
    ");
    $saved = 0;
    foreach ($names as $code => $nick) {
        $stmt->execute([':code' => trim((string)$code), ':nick' => trim((string)$nick)]);
        $saved++;
    }
    apiOk(['saved' => $saved]);
}

$rows = $db->query("
    SELECT DISTINCT c.tracking_code, COALESCE(n.nickname, '') AS nickname
    FROM public.collations c
    LEFT JOIN public.collation_baer_names n ON n.tracking_code = c.tracking_code
    WHERE c.tracking_code IS NOT NULL AND c.tracking_code != ''
    ORDER BY c.tracking_code
")->fetchAll(PDO::FETCH_ASSOC);

apiOk($rows);

function ensureCollationsSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.collations (
            id                      BIGSERIAL PRIMARY KEY,
            source                  TEXT NOT NULL DEFAULT '',
            partner_id              TEXT,
            brand_name              TEXT NOT NULL DEFAULT '',
            country                 TEXT NOT NULL DEFAULT '',
            tracking_code           TEXT NOT NULL DEFAULT '',
            first_deposit_count     INTEGER NOT NULL DEFAULT 0,
            sum_m1_deposits         NUMERIC(15,2) NOT NULL DEFAULT 0,
            sum_m1_marketing_spend  NUMERIC(15,2) NOT NULL DEFAULT 0,
            month                   CHAR(7) NOT NULL DEFAULT '',
            period                  TEXT NOT NULL DEFAULT '',
            created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.collation_baer_names (
            tracking_code TEXT PRIMARY KEY,
            nickname      TEXT NOT NULL DEFAULT '',
            updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");
    $db->exec("
        ALTER TABLE public.collations
            ADD COLUMN IF NOT EXISTS partner_id TEXT
    ");
    $db->exec("
        ALTER TABLE public.collations
            ADD COLUMN IF NOT EXISTS sum_m1_deposits NUMERIC(15,2) NOT NULL DEFAULT 0
    ");
    $db->exec("
        ALTER TABLE public.collations
            ADD COLUMN IF NOT EXISTS sum_m1_marketing_spend NUMERIC(15,2) NOT NULL DEFAULT 0
    ");
    $db->exec("
        ALTER TABLE public.collations
            ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    ");
    $db->exec("
        ALTER TABLE public.collations
            ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    ");
    $db->exec("
        ALTER TABLE public.collations
            DROP CONSTRAINT IF EXISTS collations_source_brand_name_country_tracking_code_period_key
    ");
    $db->exec("
        ALTER TABLE public.collations
            DROP CONSTRAINT IF EXISTS collations_source_brand_name_country_tracking_code_month_key
    ");
    $db->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint
                WHERE conname = 'collations_source_brand_name_country_tracking_code_month_key'
            ) THEN
                ALTER TABLE public.collations
                    ADD CONSTRAINT collations_source_brand_name_country_tracking_code_month_key
                    UNIQUE (source, brand_name, country, tracking_code, month);
            END IF;
        END $$;
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_collations_month ON public.collations (month)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_collations_tracking_code ON public.collations (tracking_code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_collations_source ON public.collations (source)");
}
