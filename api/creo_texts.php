<?php
// api/creo_texts.php — CRUD for creo_headlines + creo_bodies (session auth)
//
// GET  ?action=list&table=headlines&geo=AR&approach=pain&page=1&per=50
// GET  ?action=list&table=bodies&...
// POST { action: create|update|delete, table, ...fields }

require_once __DIR__ . '/_bootstrap.php';

$isAdmin = ($me['role'] === 'admin');

$action = $_GET['action'] ?? '';
$body   = [];
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? $action;
}

$tableParam = strtolower(trim(($method === 'GET' ? $_GET['table'] : $body['table']) ?? 'headlines'));
if (!in_array($tableParam, ['headlines', 'bodies'])) apiError(400, 'table must be headlines or bodies');
$tbl = $tableParam === 'headlines' ? 'creo_headlines' : 'creo_bodies';

// ── Validation ────────────────────────────────────────────────
function sanitizeGeo(string $v): string { return strtoupper(trim($v)); }

function validateRow(array $b, string $table): ?string {
    if (!preg_match('/^[A-Z]{2}$/', sanitizeGeo($b['geo'] ?? ''))) return 'geo must be 2 uppercase letters';
    if (mb_strlen($b['approach'] ?? '') > 100) return 'approach max 100 chars';
    if ($table === 'headlines') {
        if (mb_strlen($b['title'] ?? '') > 250) return 'title max 250 chars';
    } else {
        if (mb_strlen($b['desc1'] ?? '') > 250) return 'desc1 max 250 chars';
    }
    return null;
}

// ── LIST ──────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $geo      = strtoupper(trim($_GET['geo']      ?? ''));
    $approach = trim($_GET['approach'] ?? '');
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per      = min(200, max(1, (int)($_GET['per'] ?? 50)));
    $offset   = ($page - 1) * $per;

    $where = []; $params = [];
    if ($geo !== '')      { $where[] = 'geo = :geo';               $params['geo'] = $geo; }
    if ($approach !== '') { $where[] = 'approach ILIKE :approach'; $params['approach'] = '%'.$approach.'%'; }
    $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

    $cnt = $db->prepare("SELECT COUNT(*) FROM public.$tbl $whereSQL");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $cols = $tableParam === 'headlines'
        ? 'id, geo, approach, title, created_at, updated_at'
        : 'id, geo, approach, desc1, desc2, created_at, updated_at';

    $stmt = $db->prepare("SELECT $cols FROM public.$tbl $whereSQL ORDER BY id DESC LIMIT :lim OFFSET :off");
    $stmt->bindValue(':lim', $per,    PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
    $stmt->execute();

    $geos       = $db->query("SELECT DISTINCT geo      FROM public.$tbl ORDER BY geo")->fetchAll(PDO::FETCH_COLUMN);
    $approaches = $db->query("SELECT DISTINCT approach FROM public.$tbl WHERE approach<>'' ORDER BY approach")->fetchAll(PDO::FETCH_COLUMN);

    apiOk($stmt->fetchAll(), ['total' => $total, 'page' => $page, 'per' => $per, 'geos' => $geos, 'approaches' => $approaches]);
}

// ── CREATE ────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    if (!$isAdmin) apiError(403, 'Admin only');
    $err = validateRow($body, $tableParam);
    if ($err) apiError(400, $err);

    if ($tableParam === 'headlines') {
        $stmt = $db->prepare("INSERT INTO public.creo_headlines (geo,approach,title) VALUES (:geo,:approach,:title) RETURNING id");
        $stmt->execute(['geo' => sanitizeGeo($body['geo']), 'approach' => trim($body['approach'] ?? ''), 'title' => trim($body['title'] ?? '')]);
    } else {
        $stmt = $db->prepare("INSERT INTO public.creo_bodies (geo,approach,desc1,desc2) VALUES (:geo,:approach,:desc1,:desc2) RETURNING id");
        $stmt->execute(['geo' => sanitizeGeo($body['geo']), 'approach' => trim($body['approach'] ?? ''), 'desc1' => trim($body['desc1'] ?? ''), 'desc2' => trim($body['desc2'] ?? '')]);
    }
    apiOk(['id' => (int)$stmt->fetchColumn()]);
}

// ── UPDATE ────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'update') {
    if (!$isAdmin) apiError(403, 'Admin only');
    $id = (int)($body['id'] ?? 0);
    if (!$id) apiError(400, 'id required');
    $err = validateRow($body, $tableParam);
    if ($err) apiError(400, $err);

    if ($tableParam === 'headlines') {
        $stmt = $db->prepare("UPDATE public.creo_headlines SET geo=:geo,approach=:approach,title=:title WHERE id=:id RETURNING id");
        $stmt->execute(['id' => $id, 'geo' => sanitizeGeo($body['geo']), 'approach' => trim($body['approach'] ?? ''), 'title' => trim($body['title'] ?? '')]);
    } else {
        $stmt = $db->prepare("UPDATE public.creo_bodies SET geo=:geo,approach=:approach,desc1=:desc1,desc2=:desc2 WHERE id=:id RETURNING id");
        $stmt->execute(['id' => $id, 'geo' => sanitizeGeo($body['geo']), 'approach' => trim($body['approach'] ?? ''), 'desc1' => trim($body['desc1'] ?? ''), 'desc2' => trim($body['desc2'] ?? '')]);
    }
    if (!$stmt->fetchColumn()) apiError(404, 'Not found');
    apiOk(['id' => $id]);
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    if (!$isAdmin) apiError(403, 'Admin only');
    $id = (int)($body['id'] ?? 0);
    if (!$id) apiError(400, 'id required');
    $stmt = $db->prepare("DELETE FROM public.$tbl WHERE id=:id RETURNING id");
    $stmt->execute(['id' => $id]);
    if (!$stmt->fetchColumn()) apiError(404, 'Not found');
    apiOk(['deleted' => $id]);
}

apiError(400, 'Unknown action');
