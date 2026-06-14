<?php
// api/fanpage_data.php — CRUD for fp_setup fanpage URLs and stop words

require_once __DIR__ . '/_bootstrap.php';

if (($me['role'] ?? '') !== 'admin') {
    apiError(403, 'Admin only');
}

$action = $_GET['action'] ?? '';
$type = $_GET['type'] ?? '';
$body = [];
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? $action;
    $type = $body['type'] ?? $type;
}

ensureFanpageTables($db);

function ensureFanpageTables(PDO $db): void {
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

function normalizeGeoValue(mixed $value): string {
    return strtoupper(trim((string)$value));
}

function validateGeoValue(string $geo): ?string {
    return preg_match('/^[A-Z]{2}$/', $geo) ? null : 'geo must be 2 letters, for example AR';
}

function normalizeFpUrl(mixed $value): string {
    $url = trim((string)$value);
    if ($url !== '' && !preg_match('/^https?:\/\//i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

function validateUrlPayload(array $b): ?string {
    $url = normalizeFpUrl($b['fp_url'] ?? '');
    if ($url === '') return 'fp_url is required';
    if (mb_strlen($url) > 2048) return 'fp_url max 2048 characters';
    if (!filter_var($url, FILTER_VALIDATE_URL)) return 'fp_url must be a valid URL';
    if (!in_array(($b['status'] ?? 'active'), ['active', 'banned'], true)) return 'status must be active or banned';
    return null;
}

function validateStopWordsPayload(array $b): ?string {
    $geo = normalizeGeoValue($b['geo'] ?? '');
    if ($err = validateGeoValue($geo)) return $err;
    if (mb_strlen((string)($b['stop_words'] ?? '')) > 20000) return 'stop_words max 20000 characters';
    return null;
}

function urlParams(array $b): array {
    return [
        'fp_url' => normalizeFpUrl($b['fp_url'] ?? ''),
        'status' => in_array(($b['status'] ?? 'active'), ['active', 'banned'], true) ? $b['status'] : 'active',
    ];
}

function stopWordsParams(array $b): array {
    return [
        'geo' => normalizeGeoValue($b['geo'] ?? ''),
        'stop_words' => trim((string)($b['stop_words'] ?? '')),
    ];
}

if ($method === 'GET' && $action === 'list') {
    if ($type === 'stop_words') {
        $geo = normalizeGeoValue($_GET['geo'] ?? '');
        $q = trim((string)($_GET['q'] ?? ''));
        $where = [];
        $params = [];
        if ($geo !== '') {
            $where[] = 'geo = :geo';
            $params['geo'] = $geo;
        }
        if ($q !== '') {
            $where[] = '(geo ILIKE :q OR stop_words ILIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $db->prepare("
            SELECT id, geo, stop_words, created_at, updated_at
            FROM public.fanpage_stop_words
            {$whereSql}
            ORDER BY geo
        ");
        $stmt->execute($params);
        $geos = $db->query("SELECT DISTINCT geo FROM public.fanpage_stop_words ORDER BY geo")->fetchAll(PDO::FETCH_COLUMN);
        apiOk($stmt->fetchAll(), ['geos' => $geos]);
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $status = $_GET['status'] ?? 'all';
    if (!in_array($status, ['all', 'active', 'banned'], true)) $status = 'all';

    $where = [];
    $params = [];
    if ($status !== 'all') {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }
    if ($q !== '') {
        $where[] = 'fp_url ILIKE :q';
        $params['q'] = '%' . $q . '%';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare("
        SELECT id, fp_url, status, created_at, updated_at
        FROM public.fanpage_urls
        {$whereSql}
        ORDER BY id
    ");
    $stmt->execute($params);
    $counts = $db->query("
        SELECT status, COUNT(*) AS cnt
        FROM public.fanpage_urls
        GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    apiOk($stmt->fetchAll(), [
        'geos' => [],
        'status_counts' => [
            'active' => (int)($counts['active'] ?? 0),
            'banned' => (int)($counts['banned'] ?? 0),
        ],
    ]);
}

if ($method === 'POST' && $type === 'urls' && $action === 'create') {
    if ($err = validateUrlPayload($body)) apiError(400, $err);
    $stmt = $db->prepare("
        INSERT INTO public.fanpage_urls (fp_url, status)
        VALUES (:fp_url, :status)
        RETURNING id
    ");
    $stmt->execute(urlParams($body));
    apiOk(['id' => (int)$stmt->fetchColumn()]);
}

if ($method === 'POST' && $type === 'urls' && $action === 'update') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) apiError(400, 'id required');
    if ($err = validateUrlPayload($body)) apiError(400, $err);
    $params = [
        'id' => $id,
        'fp_url' => normalizeFpUrl($body['fp_url'] ?? ''),
    ];
    $stmt = $db->prepare("
        UPDATE public.fanpage_urls
        SET fp_url=:fp_url, updated_at=now()
        WHERE id=:id
        RETURNING id
    ");
    $stmt->execute($params);
    if (!$stmt->fetchColumn()) apiError(404, 'Not found');
    apiOk(['id' => $id]);
}

if ($method === 'POST' && $type === 'urls' && $action === 'set_status') {
    $id = (int)($body['id'] ?? 0);
    $status = $body['status'] ?? '';
    if (!$id) apiError(400, 'id required');
    if (!in_array($status, ['active', 'banned'], true)) apiError(400, 'status must be active or banned');
    $stmt = $db->prepare("
        UPDATE public.fanpage_urls
        SET status=:status, updated_at=now()
        WHERE id=:id
        RETURNING id
    ");
    $stmt->execute(['id' => $id, 'status' => $status]);
    if (!$stmt->fetchColumn()) apiError(404, 'Not found');
    apiOk(['id' => $id, 'status' => $status]);
}

if ($method === 'POST' && $type === 'stop_words' && $action === 'create') {
    if ($err = validateStopWordsPayload($body)) apiError(400, $err);
    $params = stopWordsParams($body);
    $exists = $db->prepare("SELECT 1 FROM public.fanpage_stop_words WHERE geo=:geo");
    $exists->execute(['geo' => $params['geo']]);
    if ($exists->fetchColumn()) apiError(409, 'A record for this geo already exists');
    $stmt = $db->prepare("
        INSERT INTO public.fanpage_stop_words (geo, stop_words)
        VALUES (:geo, :stop_words)
        RETURNING id
    ");
    $stmt->execute($params);
    apiOk(['id' => (int)$stmt->fetchColumn()]);
}

if ($method === 'POST' && $type === 'stop_words' && $action === 'update') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) apiError(400, 'id required');
    if ($err = validateStopWordsPayload($body)) apiError(400, $err);
    $params = array_merge(['id' => $id], stopWordsParams($body));
    $exists = $db->prepare("SELECT 1 FROM public.fanpage_stop_words WHERE geo=:geo AND id<>:id");
    $exists->execute(['geo' => $params['geo'], 'id' => $id]);
    if ($exists->fetchColumn()) apiError(409, 'A record for this geo already exists');
    $stmt = $db->prepare("
        UPDATE public.fanpage_stop_words
        SET geo=:geo, stop_words=:stop_words, updated_at=now()
        WHERE id=:id
        RETURNING id
    ");
    $stmt->execute($params);
    if (!$stmt->fetchColumn()) apiError(404, 'Not found');
    apiOk(['id' => $id]);
}

if ($method === 'POST' && in_array($type, ['urls', 'stop_words'], true) && $action === 'delete') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) apiError(400, 'id required');
    $table = $type === 'urls' ? 'public.fanpage_urls' : 'public.fanpage_stop_words';
    $stmt = $db->prepare("DELETE FROM {$table} WHERE id=:id RETURNING id");
    $stmt->execute(['id' => $id]);
    if (!$stmt->fetchColumn()) apiError(404, 'Not found');
    apiOk(['deleted' => $id]);
}

apiError(400, 'Unknown action');
