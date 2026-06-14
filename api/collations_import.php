<?php
// api/collations_import.php
// @version 1.0.1
// POST JSON { rows: [...], period: "...", source: "...", month: "YYYY-MM" }
// Upsert rows into collations table

require __DIR__.'/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST required');
if (($me['role'] ?? '') !== 'admin') apiError(403, 'Admin only');

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) apiError(400, 'Invalid JSON');

$rows   = $body['rows']   ?? [];
$period = trim($body['period']  ?? '');
$source = trim($body['source']  ?? '');
$month  = trim($body['month']   ?? '');

if (!$rows || !$period || !$source || !$month) apiError(400, 'rows, period, source, month required');

ensureCollationsSchema($db);

function importTextField(array $row, string $key): ?string {
    if (!array_key_exists($key, $row) || $row[$key] === null) return null;
    $value = trim((string)$row[$key]);
    return $value === '' ? null : $value;
}

function importNumberField(array $row, string $key): float {
    if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') return 0.0;
    if (is_string($row[$key])) {
        $value = str_replace(["\xc2\xa0", ' '], '', $row[$key]);
        $value = str_replace(',', '.', $value);
        return (float)$value;
    }
    return (float)$row[$key];
}

function importDateField(string $value): ?DateTimeImmutable {
    foreach (['!Y-m-d', '!m-d-Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date && $date->format(substr($format, 1)) === $value) return $date;
    }
    return null;
}

function importPeriodDays(string $period): int {
    $parts = explode('_', $period, 2);
    if (count($parts) !== 2) return 0;

    $from = importDateField(trim($parts[0]));
    $to = importDateField(trim($parts[1]));
    if (!$from || !$to || $to < $from) return 0;

    return $from->diff($to)->days + 1;
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

$periodDays = importPeriodDays($period);

$errors   = [];
$inserted = 0;
$updated  = 0;
$skipped  = 0;
$ignoredPeriod = 0;

$db->exec("
    ALTER TABLE public.collations
        ADD COLUMN IF NOT EXISTS sum_m1_deposits NUMERIC(15,2) NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS sum_m1_marketing_spend NUMERIC(15,2) NOT NULL DEFAULT 0
");

$receivedRows = count($rows);
$aggregatedRows = [];

foreach ($rows as $i => $r) {
    if (!is_array($r)) {
        $skipped++;
        $errors[] = 'Row ' . ($i + 1) . ': expected object';
        continue;
    }

    $trackingCode = importTextField($r, 'trackingCode');
    $brandName = importTextField($r, 'brandName');
    $country = importTextField($r, 'country');
    $partnerId = importTextField($r, 'partnerId');
    $key = json_encode([$source, $month, $trackingCode, $brandName, $country], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!isset($aggregatedRows[$key])) {
        $aggregatedRows[$key] = [
            'partnerId' => $partnerId,
            'brandName' => $brandName,
            'country' => $country,
            'trackingCode' => $trackingCode,
            'firstDepositCount' => 0,
            'sumM1Deposits' => 0.0,
            'sumM1MarketingSpend' => 0.0,
            '_sourceRows' => [],
        ];
    } elseif ($partnerId !== null) {
        $aggregatedRows[$key]['partnerId'] = $partnerId;
    }

    $aggregatedRows[$key]['_sourceRows'][] = $i + 1;
    $aggregatedRows[$key]['firstDepositCount'] += (int)importNumberField($r, 'firstDepositCount');
    $aggregatedRows[$key]['sumM1Deposits'] += importNumberField($r, 'sumM1Deposits');
    $aggregatedRows[$key]['sumM1MarketingSpend'] += importNumberField($r, 'sumM1MarketingSpend');
}

$rows = array_values($aggregatedRows);

// Use xmax to detect insert vs update:
// after ON CONFLICT DO UPDATE, xmax != 0 means update
$sql = "
    INSERT INTO public.collations (
        source, partner_id, brand_name, country, tracking_code, first_deposit_count,
        sum_m1_deposits, sum_m1_marketing_spend, month, period
    )
    VALUES (
        :source, :partner_id, :brand_name, :country, :tracking_code, :first_deposit_count,
        :sum_m1_deposits, :sum_m1_marketing_spend, :month, :period
    )
    ON CONFLICT (source, brand_name, country, tracking_code, month)
    DO UPDATE SET
        first_deposit_count     = EXCLUDED.first_deposit_count,
        sum_m1_deposits         = EXCLUDED.sum_m1_deposits,
        sum_m1_marketing_spend  = EXCLUDED.sum_m1_marketing_spend,
        partner_id              = EXCLUDED.partner_id,
        period                  = EXCLUDED.period,
        updated_at              = NOW()
    WHERE
        EXCLUDED.period = public.collations.period
        OR CAST(:period_days AS int) > COALESCE(
            CASE
                WHEN public.collations.period ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                THEN split_part(public.collations.period, '_', 2)::date - split_part(public.collations.period, '_', 1)::date + 1
                WHEN public.collations.period ~ '^[0-9]{2}-[0-9]{2}-[0-9]{4}_[0-9]{2}-[0-9]{2}-[0-9]{4}$'
                THEN to_date(split_part(public.collations.period, '_', 2), 'MM-DD-YYYY') - to_date(split_part(public.collations.period, '_', 1), 'MM-DD-YYYY') + 1
                ELSE NULL
            END,
            0
        )
    RETURNING (xmax = 0) AS is_insert
";
$stmt = $db->prepare($sql);

foreach ($rows as $i => $r) {
    try {
        $stmt->execute([
            ':source'              => $source,
            ':partner_id'          => $r['partnerId']         ?? null,
            ':brand_name'          => $r['brandName']         ?? null,
            ':country'             => $r['country']           ?? null,
            ':tracking_code'       => $r['trackingCode']      ?? null,
            ':first_deposit_count' => (int)($r['firstDepositCount'] ?? 0),
            ':sum_m1_deposits'     => (float)($r['sumM1Deposits'] ?? 0),
            ':sum_m1_marketing_spend' => (float)($r['sumM1MarketingSpend'] ?? 0),
            ':month'               => $month,
            ':period'              => $period,
            ':period_days'          => $periodDays,
        ]);
        $row = $stmt->fetch();
        if (!$row)                 $ignoredPeriod++;
        elseif ($row['is_insert']) $inserted++;
        else                       $updated++;
    } catch (Exception $e) {
        $skipped++;
        $sourceRows = implode(',', $r['_sourceRows'] ?? [$i + 1]);
        $errors[] = "Row " . $sourceRows . ": " . $e->getMessage();
    }
}

apiOk([
    'inserted' => $inserted,
    'updated'  => $updated,
    'skipped'  => $skipped,
    'ignored_period' => $ignoredPeriod,
    'errors'   => $errors,
    'received_rows' => $receivedRows,
    'aggregated_rows' => count($rows),
    'duplicates_merged' => max(0, $receivedRows - count($rows) - $skipped),
    'source'   => $source,
    'period'   => $period,
    'month'    => $month,
]);
