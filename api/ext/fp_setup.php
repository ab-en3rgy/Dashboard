<?php
// api/ext/fp_setup.php — External API for Facebook Page setup data
// POST { secret, geo }
// Response: { fp_urls, stop_words }

require __DIR__ . '/_bootstrap.php';

$geo = strtoupper(trim((string)($body['geo'] ?? '')));

if (!preg_match('/^[A-Z]{2}$/', $geo)) {
    extError(400, 'Invalid geo');
}

ensureFanpageSetupTables($db);

$urls = findFanpageUrls($db, $geo);
$stopWords = findFanpageStopWords($db, $geo);

echo json_encode([
    'fp_urls' => array_values(array_map(static fn(array $row): string => $row['fp_url'], $urls)),
    'stop_words' => $stopWords,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

function ensureFanpageSetupTables(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.fanpage_urls (
            id bigserial PRIMARY KEY,
            fp_url varchar(2048) NOT NULL,
            status varchar(10) NOT NULL DEFAULT 'active'
                CONSTRAINT fanpage_urls_status_chk CHECK (status IN ('active', 'banned')),
            created_at timestamptz NOT NULL DEFAULT now(),
            updated_at timestamptz NOT NULL DEFAULT now()
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.fanpage_stop_words (
            id bigserial PRIMARY KEY,
            geo char(2) NOT NULL UNIQUE,
            stop_words text NOT NULL DEFAULT '',
            created_at timestamptz NOT NULL DEFAULT now(),
            updated_at timestamptz NOT NULL DEFAULT now()
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_fanpage_urls_status ON public.fanpage_urls (status)");
    $db->exec("
        DO $$
        BEGIN
            IF EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name = 'fanpage_urls'
                  AND column_name = 'geo'
            ) THEN
                ALTER TABLE public.fanpage_urls ALTER COLUMN geo SET DEFAULT 'XX';
            END IF;
        END $$;
    ");
}

function findFanpageUrls(PDO $db, string $geo): array {
    $stmt = $db->prepare("
        SELECT fp_url
        FROM public.fanpage_urls
        WHERE status = 'active'
        ORDER BY id
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function findFanpageStopWords(PDO $db, string $geo): array {
    $stmt = $db->prepare("
        SELECT stop_words
        FROM public.fanpage_stop_words
        WHERE geo = :geo
        LIMIT 1
    ");
    $stmt->execute(['geo' => $geo]);
    return normalizeStopWords($stmt->fetchColumn() ?: '');
}

function normalizeStopWords(mixed $raw): array {
    if (is_array($raw)) {
        $items = $raw;
    } else {
        $items = preg_split('/[\r\n,;]+/', (string)$raw) ?: [];
    }
    $out = [];
    $seen = [];
    foreach ($items as $item) {
        $word = trim((string)$item);
        $key = mb_strtolower($word);
        if ($word === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $word;
    }
    return $out;
}
