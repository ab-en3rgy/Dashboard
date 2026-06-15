<?php
// api/domains.php — CRUD for domains_fp (session auth)
// @version 1.0.5
//
// GET  ?action=list&bm=123&geo=AR
// POST { action: create|update|delete, ...fields }

require_once __DIR__ . '/_bootstrap.php';

$isAdmin = ($me['role'] === 'admin');

$action = $_GET['action'] ?? '';
$body   = [];
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? $action;
}

function ensureDomainStatusColumn(PDO $db): void {
    $db->exec("
        ALTER TABLE public.domains_fp
        ADD COLUMN IF NOT EXISTS status varchar(10) NOT NULL DEFAULT 'active'
    ");
    $db->exec("
        UPDATE public.domains_fp
        SET status = 'active'
        WHERE status IS NULL OR status = ''
    ");
    $db->exec("
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint
                WHERE conname = 'domains_fp_status_chk'
            ) THEN
                ALTER TABLE public.domains_fp
                ADD CONSTRAINT domains_fp_status_chk
                CHECK (status IN ('active', 'banned'));
            END IF;
        END $$;
    ");
}

function ensureDomainUserColumn(PDO $db): void {
    $db->exec("
        ALTER TABLE public.domains_fp
        ADD COLUMN IF NOT EXISTS user_id int REFERENCES public.users(id) ON DELETE SET NULL
    ");
    $db->exec("
        UPDATE public.domains_fp
        SET user_id = (
            SELECT id
            FROM public.users
            WHERE role = 'admin'
            ORDER BY id
            LIMIT 1
        )
        WHERE user_id IS NULL
          AND EXISTS (SELECT 1 FROM public.users WHERE role = 'admin')
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_dfp_user ON public.domains_fp (user_id)
    ");
}

function ensureDomainDeliveryColumns(PDO $db): void {
    $db->exec("
        ALTER TABLE public.domains_fp
        ADD COLUMN IF NOT EXISTS page_id varchar(255) NOT NULL DEFAULT ''
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
        ADD COLUMN IF NOT EXISTS pixel_id varchar(255) NOT NULL DEFAULT ''
    ");
}

function ensureDomainUsageColumns(PDO $db): void {
    $db->exec("
        ALTER TABLE public.domains_fp
        ADD COLUMN IF NOT EXISTS used_geos jsonb NOT NULL DEFAULT '[]'::jsonb
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_dfp_used_geos ON public.domains_fp USING GIN (used_geos)
    ");
}

function ensureDomainGeoAnalysisSchema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.domains_fp_geo_usage (
            id          bigserial       PRIMARY KEY,
            log_id      bigint          NOT NULL UNIQUE,
            fp_id       bigint          NOT NULL REFERENCES public.domains_fp(id) ON DELETE CASCADE,
            geo         char(2)         NOT NULL,
            bm_id       varchar(20)     NOT NULL DEFAULT '',
            account_id  text            NOT NULL DEFAULT '',
            page_id     varchar(255)    NOT NULL DEFAULT '',
            fp_name     varchar(255)    NOT NULL DEFAULT '',
            created_at  timestamptz     NOT NULL DEFAULT now()
        )
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_dfp_geo_usage_fp_geo ON public.domains_fp_geo_usage (fp_id, geo)
    ");
    $db->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_dfp_geo_usage_fp_geo_unique ON public.domains_fp_geo_usage (fp_id, geo)
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_dfp_geo_usage_log ON public.domains_fp_geo_usage (log_id DESC)
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.domains_fp_geo_scan_state (
            id smallint PRIMARY KEY,
            last_log_id bigint NOT NULL DEFAULT 0,
            last_task_id bigint NOT NULL DEFAULT 0,
            updated_at timestamptz NOT NULL DEFAULT now()
        )
    ");
    $db->exec("
        INSERT INTO public.domains_fp_geo_scan_state (id, last_log_id)
        VALUES (1, 0)
        ON CONFLICT (id) DO NOTHING
    ");
    $db->exec("
        ALTER TABLE public.domains_fp_geo_scan_state
        ADD COLUMN IF NOT EXISTS last_task_id bigint NOT NULL DEFAULT 0
    ");
}

function loadBmOptions(PDO $db, array $me, bool $isAdmin): array {
    if ($isAdmin) {
        $stmt = $db->query("
            SELECT id::text AS bm_id, name AS bm_name
            FROM public.business_managers
            WHERE is_active = TRUE
            ORDER BY name, id
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $stmt = $db->prepare("
        SELECT bm.id::text AS bm_id, bm.name AS bm_name
        FROM public.business_managers bm
        JOIN public.user_bm_accounts uba ON uba.bm_id = bm.id
        WHERE uba.user_id = :uid
          AND bm.is_active = TRUE
        ORDER BY bm.name, bm.id
    ");
    $stmt->execute(['uid' => (int)$me['id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

ensureDomainStatusColumn($db);
ensureDomainUserColumn($db);
ensureDomainDeliveryColumns($db);
ensureDomainUsageColumns($db);
ensureDomainGeoAnalysisSchema($db);

// ── Validation ────────────────────────────────────────────────
function validateDomain(array $b): ?string {
    if (mb_strlen(trim($b['bm']  ?? '')) === 0)  return 'bm is required';
    if (mb_strlen(trim($b['bm']  ?? '')) > 20)   return 'bm max 20 characters';
    $geo = strtoupper(trim($b['geo'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/', $geo))        return 'geo must be 2 letters, e.g. AR';
    if (mb_strlen($b['domain']  ?? '') > 255)     return 'domain max 255 characters';
    if (mb_strlen($b['fp_name'] ?? '') > 255)     return 'fp_name max 255 characters';
    if (mb_strlen($b['page_id'] ?? '') > 255)     return 'page_id max 255 characters';
    if (mb_strlen($b['pixel_id'] ?? '') > 255)    return 'pixel_id max 255 characters';
    if (isset($b['status']) && !in_array($b['status'], ['active', 'banned'], true)) return 'status must be active or banned';
    return null;
}

function rowParams(array $b): array {
    return [
        'bm'      => trim($b['bm']),
        'geo'     => strtoupper(trim($b['geo'])),
        'domain'  => trim($b['domain']  ?? ''),
        'fp_name' => trim($b['fp_name'] ?? ''),
        'page_id' => trim($b['page_id'] ?? ''),
        'pixel_id'=> trim($b['pixel_id'] ?? ''),
        'status'  => in_array(($b['status'] ?? 'active'), ['active', 'banned'], true) ? $b['status'] : 'active',
    ];
}

function requestedOwnerId(array $body, array $me, bool $isAdmin): int {
    if (!$isAdmin) {
        return (int)$me['id'];
    }
    $ownerId = (int)($body['user_id'] ?? 0);
    return $ownerId > 0 ? $ownerId : (int)$me['id'];
}

function ownerExists(PDO $db, int $userId): bool {
    $stmt = $db->prepare("SELECT 1 FROM public.users WHERE id = :id AND is_active = TRUE");
    $stmt->execute(['id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

// ── LIST ──────────────────────────────────────────────────────
function loadGeoRulesConfig(): array {
    $rulesPath = __DIR__ . '/../config/geo_rules.json';
    if (!is_file($rulesPath)) return [];
    $json = file_get_contents($rulesPath);
    $rules = json_decode($json, true);
    if (!is_array($rules)) {
        $rules = json_decode(preg_replace('/,\s*([}\]])/', '$1', $json), true);
    }
    return is_array($rules) ? $rules : [];
}

function fpGeoGroupForGeo(array $rules, string $geo): array {
    $geo = strtoupper(trim($geo));
    $groups = $rules['fp_geo_group'] ?? $rules['fp_geo_groups'] ?? [];
    if (!is_array($groups)) return [];

    foreach ($groups as $group) {
        if (!is_array($group)) $group = [$group];
        $out = [];
        foreach ($group as $item) {
            $item = strtoupper(trim((string)$item));
            if (preg_match('/^[A-Z]{2}$/', $item) && !in_array($item, $out, true)) $out[] = $item;
        }
        if (in_array($geo, $out, true)) return $out;
    }

    return [];
}
if ($method === 'GET' && $action === 'list') {
    $bm   = trim($_GET['bm']  ?? '');
    $geo  = strtoupper(trim($_GET['geo'] ?? ''));
    $status = $_GET['status'] ?? 'active';
    if (!in_array($status, ['active', 'banned'], true)) $status = 'active';
    $where = []; $params = [];
    $countWhere = []; $countParams = [];
    if (!$isAdmin) {
        $where[] = 'd.user_id = :current_user_id'; $params['current_user_id'] = (int)$me['id'];
        $countWhere[] = 'user_id = :current_user_id'; $countParams['current_user_id'] = (int)$me['id'];
    }
    if ($bm  !== '') {
        $where[] = 'd.bm = :bm'; $params['bm'] = $bm;
        $countWhere[] = 'bm = :bm'; $countParams['bm'] = $bm;
    }
    if ($geo !== '') {
        $where[] = "(d.geo = :geo_exact OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(COALESCE(d.used_geos, '[]'::jsonb)) AS used_geo(val) WHERE used_geo.val = :geo_used))";
        $params['geo_exact'] = $geo;
        $params['geo_used'] = $geo;
        $countWhere[] = "(geo = :geo_exact OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(COALESCE(used_geos, '[]'::jsonb)) AS used_geo(val) WHERE used_geo.val = :geo_used))";
        $countParams['geo_exact'] = $geo;
        $countParams['geo_used'] = $geo;
    }
    $where[] = 'd.status = :status';
    $params['status'] = $status;
    $whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';
    $countWhereSQL = $countWhere ? 'WHERE '.implode(' AND ', $countWhere) : '';

    $cnt = $db->prepare("SELECT COUNT(*) FROM public.domains_fp d $whereSQL");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $stmt = $db->prepare("
        SELECT d.id, d.bm AS bm_id, bm.name AS bm_name, d.geo, d.used_geos, d.domain, d.fp_name, d.page_id, d.pixel_id, d.status, d.user_id,
               u.username AS owner_username,
               COALESCE(u.display_name, u.username) AS owner_name,
               d.created_at, d.updated_at
        FROM public.domains_fp d
        LEFT JOIN public.business_managers bm ON bm.id::text = d.bm
        LEFT JOIN public.users u ON u.id = d.user_id
        $whereSQL
        ORDER BY COALESCE(bm.name, d.bm), d.geo, d.id
    ");
    foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $geoRules = loadGeoRulesConfig();
    foreach ($rows as &$row) {
        $row['fp_geo_group'] = fpGeoGroupForGeo($geoRules, (string)($row['geo'] ?? ''));
        $usedGeos = json_decode((string)($row['used_geos'] ?? '[]'), true);
        $row['used_geos'] = array_values(array_filter(array_map(
            static fn($geo): string => strtoupper(trim((string)$geo)),
            is_array($usedGeos) ? $usedGeos : []
        ), static fn(string $geo): bool => (bool)preg_match('/^[A-Z]{2}$/', $geo)));
    }
    unset($row);

    $bms = loadBmOptions($db, $me, $isAdmin);
    $geoWhere = [];
    $geoParams = [];
    if (!$isAdmin) {
        $geoWhere[] = 'd.user_id = :current_user_id';
        $geoParams['current_user_id'] = (int)$me['id'];
    }
    if ($bm !== '') {
        $geoWhere[] = 'd.bm = :bm';
        $geoParams['bm'] = $bm;
    }
    $geoWhere[] = 'd.status = :status';
    $geoParams['status'] = $status;
    $geoWhereSQL = $geoWhere ? 'WHERE ' . implode(' AND ', $geoWhere) : '';
    $geoStmt = $db->prepare("
        SELECT d.geo, d.used_geos
        FROM public.domains_fp d
        $geoWhereSQL
    ");
    $geoStmt->execute($geoParams);
    $geos = [];
    foreach ($geoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $geoRow) {
        $baseGeo = strtoupper(trim((string)($geoRow['geo'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $baseGeo) && !in_array($baseGeo, $geos, true)) {
            $geos[] = $baseGeo;
        }
        $usedGeos = json_decode((string)($geoRow['used_geos'] ?? '[]'), true);
        foreach (is_array($usedGeos) ? $usedGeos : [] as $usedGeo) {
            $usedGeo = strtoupper(trim((string)$usedGeo));
            if (preg_match('/^[A-Z]{2}$/', $usedGeo) && !in_array($usedGeo, $geos, true)) {
                $geos[] = $usedGeo;
            }
        }
    }
    sort($geos);
    $statusStmt = $db->prepare("
        SELECT status, COUNT(*) AS cnt
        FROM public.domains_fp
        $countWhereSQL
        GROUP BY status
    ");
    $statusStmt->execute($countParams);
    $statusCounts = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $users = [];
    if ($isAdmin) {
        $users = $db->query("
            SELECT id, username, COALESCE(display_name, username) AS name
            FROM public.users
            WHERE is_active = TRUE
            ORDER BY role, username
        ")->fetchAll();
    }

    apiOk($rows, [
        'total' => $total,
        'bms'   => $bms,   'geos' => $geos,
        'users' => $users,
        'status_counts' => [
            'active' => (int)($statusCounts['active'] ?? 0),
            'banned' => (int)($statusCounts['banned'] ?? 0),
        ],
    ]);
}

if ($method === 'POST' && $action === 'analyze_geo_usage') {
    if (!$isAdmin) apiError(403, 'Admin only');

    $stateStmt = $db->query("SELECT last_task_id FROM public.domains_fp_geo_scan_state WHERE id = 1 LIMIT 1");
    $lastTaskId = (int)($stateStmt ? $stateStmt->fetchColumn() : 0);

    $taskStmt = $db->prepare("
        SELECT t.id, t.created_at, t.payload, t.bm_id, t.account_id
        FROM public.tasks t
        WHERE t.id > :last_task_id
          AND t.task_type = 'create_campaign'
        ORDER BY t.id ASC
        LIMIT 5000
    ");
    $taskStmt->execute([':last_task_id' => $lastTaskId]);
    $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$tasks) {
        apiOk([
            'checked_tasks' => 0,
            'matched_tasks' => 0,
            'inserted' => 0,
            'updated_fps' => 0,
            'last_task_id' => $lastTaskId,
        ]);
    }

    $insertStmt = $db->prepare("
        INSERT INTO public.domains_fp_geo_usage
            (log_id, fp_id, geo, bm_id, account_id, page_id, fp_name, created_at)
        VALUES
            (:log_id, :fp_id, :geo, :bm_id, :account_id, :page_id, :fp_name, :created_at)
        ON CONFLICT (fp_id, geo) DO NOTHING
    ");
    $touchStmt = $db->prepare("
        UPDATE public.domains_fp d
        SET used_geos = COALESCE((
                SELECT to_jsonb(array_agg(x.geo ORDER BY x.geo))
                FROM (
                    SELECT DISTINCT g.geo
                    FROM public.domains_fp_geo_usage g
                    WHERE g.fp_id = :fp_id
                ) x
            ), '[]'::jsonb),
            updated_at = NOW()
        WHERE d.id = :fp_id
    ");
    $advanceStmt = $db->prepare("
        UPDATE public.domains_fp_geo_scan_state
        SET last_task_id = :last_task_id,
            updated_at = NOW()
        WHERE id = 1
    ");

    $matched = 0;
    $inserted = 0;
    $touchedFpIds = [];
    $maxTaskId = $lastTaskId;

    $db->beginTransaction();
    try {
        foreach ($tasks as $task) {
            $maxTaskId = max($maxTaskId, (int)$task['id']);
            $payload = json_decode((string)($task['payload'] ?? '{}'), true);
            if (!is_array($payload)) {
                continue;
            }

            $fpId = (int)($payload['fp_id'] ?? 0);
            $geo = strtoupper(trim((string)($payload['geo'] ?? '')));
            if ($fpId <= 0 || !preg_match('/^[A-Z]{2}$/', $geo)) {
                continue;
            }

            $insertStmt->execute([
                ':log_id' => (int)$task['id'],
                ':fp_id' => $fpId,
                ':geo' => $geo,
                ':bm_id' => trim((string)($payload['bm_id'] ?? $task['bm_id'] ?? '')),
                ':account_id' => trim((string)($payload['account_id'] ?? $task['account_id'] ?? '')),
                ':page_id' => trim((string)($payload['page_id'] ?? '')),
                ':fp_name' => trim((string)($payload['fp_name'] ?? $payload['fp_label'] ?? '')),
                ':created_at' => (string)($task['created_at'] ?? date('c')),
            ]);
            if ($insertStmt->rowCount() > 0) {
                $inserted++;
            }
            $matched++;
            $touchedFpIds[$fpId] = true;
        }

        foreach (array_keys($touchedFpIds) as $fpId) {
            $touchStmt->execute([':fp_id' => $fpId]);
        }

        $advanceStmt->execute([':last_task_id' => $maxTaskId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        apiError(500, 'Geo analysis failed: ' . $e->getMessage());
    }

    apiOk([
        'checked_tasks' => count($tasks),
        'matched_tasks' => $matched,
        'inserted' => $inserted,
        'updated_fps' => count($touchedFpIds),
        'last_task_id' => $maxTaskId,
    ]);
}

// ── CREATE ────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'create') {
    if (!$isAdmin) apiError(403, 'Admin only');
    $err = validateDomain($body);
    if ($err) apiError(400, $err);

    $p = rowParams($body);
    $p['user_id'] = requestedOwnerId($body, $me, $isAdmin);
    if (!ownerExists($db, $p['user_id'])) apiError(400, 'owner user not found');
    $stmt = $db->prepare("
        INSERT INTO public.domains_fp (bm, geo, domain, fp_name, page_id, pixel_id, status, user_id)
        VALUES (:bm, :geo, :domain, :fp_name, :page_id, :pixel_id, :status, :user_id)
        RETURNING id
    ");
    $stmt->execute($p);
    apiOk(['id' => (int)$stmt->fetchColumn()]);
}

// ── DUPLICATE ─────────────────────────────────────────────────
if ($method === 'POST' && $action === 'duplicate') {
    if (!$isAdmin) apiError(403, 'Admin only');
    $id = (int)($body['id'] ?? 0);
    if (!$id) apiError(400, 'id required');

    $stmt = $db->prepare("
        INSERT INTO public.domains_fp (bm, geo, domain, fp_name, page_id, pixel_id, used_geos, status, user_id)
        SELECT bm, geo, domain, fp_name, page_id, pixel_id, used_geos, status, user_id
        FROM public.domains_fp
        WHERE id = :id
        RETURNING id
    ");
    $stmt->execute(['id' => $id]);
    $newId = $stmt->fetchColumn();
    if (!$newId) apiError(404, 'Not found');
    apiOk(['id' => (int)$newId, 'source_id' => $id]);
}

// ── UPDATE ────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'update') {
    if (!$isAdmin) apiError(403, 'Admin only');
    $id = (int)($body['id'] ?? 0);
    if (!$id) apiError(400, 'id required');
    $err = validateDomain($body);
    if ($err) apiError(400, $err);

    $p = array_merge(['id' => $id], rowParams($body));
    $p['user_id'] = requestedOwnerId($body, $me, $isAdmin);
    if (!ownerExists($db, $p['user_id'])) apiError(400, 'owner user not found');
    $stmt = $db->prepare("
        UPDATE public.domains_fp
        SET bm=:bm, geo=:geo, domain=:domain, fp_name=:fp_name, page_id=:page_id, pixel_id=:pixel_id, status=:status, user_id=:user_id
        WHERE id=:id
        RETURNING id
    ");
    $stmt->execute($p);
    if (!$stmt->fetchColumn()) apiError(404, 'Not found');
    apiOk(['id' => $id]);
}

// ── TOGGLE STATUS ─────────────────────────────────────────────
if ($method === 'POST' && $action === 'set_status') {
    if (!$isAdmin) apiError(403, 'Admin only');
    $id = (int)($body['id'] ?? 0);
    $status = $body['status'] ?? '';
    if (!$id) apiError(400, 'id required');
    if (!in_array($status, ['active', 'banned'], true)) apiError(400, 'status must be active or banned');

    $stmt = $db->prepare("
        UPDATE public.domains_fp
        SET status=:status
        WHERE id=:id
        RETURNING id
    ");
    $stmt->execute(['id' => $id, 'status' => $status]);
    if (!$stmt->fetchColumn()) apiError(404, 'Not found');
    apiOk(['id' => $id, 'status' => $status]);
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    if (!$isAdmin) apiError(403, 'Admin only');
    $id = (int)($body['id'] ?? 0);
    if (!$id) apiError(400, 'id required');

    $stmt = $db->prepare("DELETE FROM public.domains_fp WHERE id=:id RETURNING id");
    $stmt->execute(['id' => $id]);
    if (!$stmt->fetchColumn()) apiError(404, 'Not found');
    apiOk(['deleted' => $id]);
}

apiError(400, 'Unknown action');
