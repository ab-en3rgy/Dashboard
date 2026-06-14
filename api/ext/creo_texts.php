<?php
// api/ext/creo_texts.php — External API (auth via extension_secret)
//
// POST /api/ext/creo_texts.php
// Body JSON:
// {
//   "secret":   "<extension_secret from config.php>",
//   "geo":      "AR",
//   "approach": "pain",   // optional
//   "count":    3         // how many random records to take from each table
// }
//
// Response:
// {
//   "ok": true,
//   "headlines": { "total": 12, "count": 3, "data": [ {id, geo, approach, title}, ... ] },
//   "bodies":    { "total": 8,  "count": 3, "data": [ {id, geo, approach, desc1, desc2}, ... ] }
// }

require __DIR__ . '/_bootstrap.php';  // checks secret and provides $db

// ── Params ────────────────────────────────────────────────────
$geo      = strtoupper(trim($body['geo'] ?? ''));
$approach = trim($body['approach'] ?? '');
$count    = max(1, min(100, (int)($body['count'] ?? 1)));

if (!$geo)                             extError(400, 'geo is required');
if (!preg_match('/^[A-Z]{2}$/', $geo)) extError(400, 'geo must be exactly 2 uppercase letters');

// ── Helper ────────────────────────────────────────────────────
function queryRandom(PDO $db, string $tbl, string $cols, string $geo, string $approach, int $count): array {
    $where  = ['geo = :geo'];
    $params = ['geo' => $geo];
    if ($approach !== '') {
        $where[]            = 'approach = :approach';
        $params['approach'] = $approach;
    }
    $whereSQL = implode(' AND ', $where);

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM public.$tbl WHERE $whereSQL");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    if ($total === 0) return ['total' => 0, 'count' => 0, 'data' => []];

    $stmt = $db->prepare("SELECT $cols FROM public.$tbl WHERE $whereSQL ORDER BY RANDOM() LIMIT :cnt");
    foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
    $stmt->bindValue(':cnt', $count, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'total' => $total,
        'count' => min($count, $total),
        'data'  => $stmt->fetchAll(),
    ];
}

// ── Fetch both tables in parallel (sequential, but one DB conn) ──
$headlines = queryRandom($db, 'creo_headlines', 'id, geo, approach, title',            $geo, $approach, $count);
$bodies    = queryRandom($db, 'creo_bodies',    'id, geo, approach, desc1, desc2',     $geo, $approach, $count);

extOk([
    'headlines' => $headlines,
    'bodies'    => $bodies,
]);
