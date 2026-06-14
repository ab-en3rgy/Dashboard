<?php
// api/collations_report.php
// @version 1.0.1
// GET ?group=baer|geo|geo_brand|brand|detail&month=YYYY-MM
require __DIR__.'/_bootstrap.php';

try {
    ensureCollationsSchema($db);

    $group  = $_GET['group']  ?? 'baer';
    $period = $_GET['period'] ?? 'current';
    $monthParam = trim((string)($_GET['month'] ?? ''));
    $filterField = $_GET['filter_field'] ?? null;
    $filterValue = $_GET['filter_value'] ?? null;
    $allowedGroups = ['baer', 'geo', 'geo_brand', 'brand', 'detail'];
    if (!in_array($group, $allowedGroups, true)) {
        $group = 'baer';
    }

    $tz    = appTimezoneName($me['display_tz'] ?? 'Europe/Kyiv');
    $now   = new DateTime('now', appDateTimeZone($tz));
    $currentMonth = $now->format('Y-m');

    if (!empty($_GET['months'])) {
        $stmt = $db->query("
            SELECT DISTINCT month
            FROM public.collations
            WHERE month IS NOT NULL
              AND month ~ '^[0-9]{4}-[0-9]{2}$'
            ORDER BY month DESC
        ");
        apiOk(['months' => $stmt->fetchAll(PDO::FETCH_COLUMN)], ['current_month' => $currentMonth]);
    }

    if (preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
        $month = $monthParam;
    } elseif ($period === 'previous') {
        $month = (clone $now)->modify('first day of last month')->format('Y-m');
    } else {
        $month = $currentMonth;
    }

    $groupCol = match($group) {
        'geo'   => 'c.country',
        'geo_brand' => 'c.country, c.brand_name',
        'brand' => 'c.brand_name',
        'detail' => 'c.country, c.tracking_code, c.brand_name',
        default => 'c.tracking_code',
    };

    $labelCol = match($group) {
        'geo'   => 'country AS label',
        'geo_brand' => "COALESCE(NULLIF(c.country,''),'—') || ' / ' || COALESCE(NULLIF(c.brand_name,''),'—') AS label, c.country, c.brand_name",
        'brand' => 'brand_name AS label',
        'detail' => "COALESCE(NULLIF(c.country,''),'—') AS country, COALESCE(NULLIF(c.brand_name,''),'—') AS brand_name, c.tracking_code AS tracking_code, c.tracking_code AS buyer_code, COALESCE(NULLIF(n.nickname,''), c.tracking_code) AS buyer_label",
        default => "COALESCE(NULLIF(n.nickname,''), c.tracking_code) AS label, c.tracking_code AS sub_label",
    };

    $joinNick = in_array($group, ['baer', 'detail'], true)
        ? "LEFT JOIN public.collation_baer_names n ON n.tracking_code = c.tracking_code"
        : "";

    $groupExtra = '';
    if ($group === 'baer' && $joinNick) {
        $groupExtra = ', n.nickname';
    } elseif ($group === 'detail' && $joinNick) {
        $groupExtra = ', n.nickname';
    }

    // Allowed fields for filtering
    $allowedFilterFields = ['tracking_code', 'country', 'brand_name'];
    $filterSql = '';
    $filterParams = [];
    if ($filterField && in_array($filterField, $allowedFilterFields) && $filterValue !== null) {
        $filterSql = " AND c.{$filterField} = :filter_value";
        $filterParams[':filter_value'] = $filterValue;
    }

    $stmt = $db->prepare("
        SELECT
            {$labelCol},
            SUM(first_deposit_count)      AS deps,
            SUM(sum_m1_deposits)          AS sum_m1_deposits,
            SUM(sum_m1_marketing_spend)   AS sum_m1_marketing_spend,
            CASE
                WHEN SUM(sum_m1_marketing_spend) > 0
                THEN SUM(sum_m1_deposits) / SUM(sum_m1_marketing_spend) * 100
                ELSE 0
            END                           AS kpi,
            COUNT(DISTINCT source)        AS sources
        FROM public.collations c
        {$joinNick}
        WHERE c.month = :month
        {$filterSql}
        GROUP BY {$groupCol}{$groupExtra}
        ORDER BY SUM(first_deposit_count) DESC
    ");
    $stmt->execute(array_merge([':month' => $month], $filterParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    apiOk($rows, ['month' => $month, 'group' => $group]);
} catch (Throwable $e) {
    error_log('[collations_report] ' . $e->getMessage());
    apiError(500, $e->getMessage());
}

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
