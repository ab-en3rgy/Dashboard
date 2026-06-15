<?php
// api/ext/domains_fp.php
// @version 1.0.3
// External API for Domains & FP configuration sync.
// POST { secret, bm, geo, domain, fp_name, page_id, pixel_id, status }
// POST { secret, items: [ ...same records... ] }

require __DIR__ . '/_bootstrap.php';

ensureDomainsFpSchema($db);

$items = normalizeDomainsFpItems($body);
if (!$items) {
    extError(400, 'items array or record payload required');
}

$ownerId = resolveDefaultDomainsFpOwnerId($db);
$stats = [
    'upserted' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => [],
];

foreach ($items as $index => $item) {
    $record = normalizeDomainsFpRecord($item);
    $recordErrors = validateDomainsFpRecord($record);
    if ($recordErrors) {
        $stats['skipped']++;
        $stats['errors'][] = [
            'index' => $index,
            'bm' => $record['bm'],
            'geo' => $record['geo'],
            'error' => implode('; ', $recordErrors),
        ];
        continue;
    }

    try {
        $result = upsertDomainsFpRecord($db, $record, $ownerId);
        $stats['upserted']++;
        if ($result['updated']) {
            $stats['updated']++;
        }
    } catch (Throwable $e) {
        error_log('ext/domains_fp: index=' . $index . ' err=' . $e->getMessage());
        $stats['errors'][] = [
            'index' => $index,
            'bm' => $record['bm'],
            'geo' => $record['geo'],
            'error' => 'Database error while saving record',
        ];
    }
}

extOk([
    'upserted' => $stats['upserted'],
    'updated' => $stats['updated'],
    'skipped' => $stats['skipped'],
    'errors' => $stats['errors'],
    'total' => count($items),
]);

function ensureDomainsFpSchema(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.domains_fp (
            id          bigserial       PRIMARY KEY,
            user_id     int             REFERENCES public.users(id) ON DELETE SET NULL,
            bm          varchar(20)     NOT NULL DEFAULT '',
            geo         char(2)         NOT NULL DEFAULT '',
            domain      varchar(255)    NOT NULL DEFAULT '',
            fp_name     varchar(255)    NOT NULL DEFAULT '',
            page_id     varchar(255)    NOT NULL DEFAULT '',
            pixel_id    varchar(255)    NOT NULL DEFAULT '',
            used_geos   jsonb           NOT NULL DEFAULT '[]'::jsonb,
            fp_url      varchar(2048)   NOT NULL DEFAULT '',
            status      varchar(10)     NOT NULL DEFAULT 'active',
            created_at  timestamptz     NOT NULL DEFAULT now(),
            updated_at  timestamptz     NOT NULL DEFAULT now()
        )
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS user_id int REFERENCES public.users(id) ON DELETE SET NULL
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS bm varchar(20) NOT NULL DEFAULT ''
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS geo char(2) NOT NULL DEFAULT ''
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS domain varchar(255) NOT NULL DEFAULT ''
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS fp_name varchar(255) NOT NULL DEFAULT ''
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS page_id varchar(255) NOT NULL DEFAULT ''
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS pixel_id varchar(255) NOT NULL DEFAULT ''
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS used_geos jsonb NOT NULL DEFAULT '[]'::jsonb
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS fp_url varchar(2048) NOT NULL DEFAULT ''
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS status varchar(10) NOT NULL DEFAULT 'active'
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS created_at timestamptz NOT NULL DEFAULT now()
    ");
    $db->exec("
        ALTER TABLE public.domains_fp
            ADD COLUMN IF NOT EXISTS updated_at timestamptz NOT NULL DEFAULT now()
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
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_dfp_lookup_sync
            ON public.domains_fp (bm, geo, domain, fp_name, page_id, pixel_id)
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_dfp_used_geos
            ON public.domains_fp USING GIN (used_geos)
    ");
}

function normalizeDomainsFpItems(array $body): array
{
    $defaults = normalizeDomainsFpDefaults($body);
    foreach (['items', 'configs', 'pages', 'data', 'rows', 'records'] as $key) {
        if (isset($body[$key]) && is_array($body[$key])) {
            return array_values(array_map(
                static fn(array $item) => array_merge($defaults, $item),
                array_values(array_filter($body[$key], static fn($item) => is_array($item)))
            ));
        }
    }

    return [$defaults];
}

function normalizeDomainsFpDefaults(array $body): array
{
    $defaults = $body;
    unset($defaults['secret'], $defaults['items'], $defaults['configs'], $defaults['pages'], $defaults['data'], $defaults['rows'], $defaults['records']);
    return is_array($defaults) ? $defaults : [];
}

function normalizeDomainsFpRecord(array $row): array
{
    $status = 'active';
    if (array_key_exists('is_active', $row)) {
        $isActive = $row['is_active'];
        if (is_bool($isActive)) {
            $status = $isActive ? 'active' : 'banned';
        } elseif (is_numeric($isActive)) {
            $status = ((int)$isActive === 1) ? 'active' : 'banned';
        } else {
            $status = in_array(strtolower(trim((string)$isActive)), ['1', 'true', 'yes', 'on', 'active'], true) ? 'active' : 'banned';
        }
    } else {
        $statusRaw = firstValue($row, ['status', 'state'], 'active');
        $status = strtolower(trim((string)$statusRaw));
    }

    if (!in_array($status, ['active', 'banned'], true)) {
        if (in_array($status, ['1', 'true', 'yes', 'on', 'active'], true)) {
            $status = 'active';
        } elseif (in_array($status, ['0', 'false', 'no', 'off', 'inactive', 'disabled', 'archived', 'deleted'], true)) {
            $status = 'banned';
        } else {
            $status = 'active';
        }
    }

    $bm = firstValue($row, ['bm', 'bm_id', 'bmId', 'business_manager_id', 'businessManagerId', 'business_manager', 'bm_name'], '');
    $geo = firstValue($row, ['geo', 'country', 'country_code', 'countryCode', 'geo_code', 'geoCode', 'market_geo', 'marketGeo'], '');
    $domain = firstValue($row, ['domain', 'website', 'landing_url', 'landingUrl', 'url', 'page_url', 'pageUrl', 'fp_url', 'fpUrl'], '');
    $fpName = firstValue($row, ['fp_name', 'fpName', 'page_name', 'pageName', 'name', 'title'], '');
    $pageId = firstValue($row, ['page_id', 'pageId', 'facebook_page_id', 'facebookPageId', 'fp_page_id', 'fpPageId', 'pageid'], '');
    $pixelId = firstValue($row, ['pixel_id', 'pixelId', 'facebook_pixel_id', 'facebookPixelId', 'pixelid', 'pixel'], '');

    if ($domain === '' && isset($row['fp_url'])) {
        $domain = trim((string)$row['fp_url']);
    }
    if ($domain === '' && isset($row['fpUrl'])) {
        $domain = trim((string)$row['fpUrl']);
    }
    if ($fpName === '' && isset($row['page_title'])) {
        $fpName = trim((string)$row['page_title']);
    }

    if ($bm === '' && isset($row['businessManagerName'])) {
        $bm = trim((string)$row['businessManagerName']);
    }
    if ($geo === '' && isset($row['countryCode'])) {
        $geo = trim((string)$row['countryCode']);
    }
    if ($pageId === '' && isset($row['pageID'])) {
        $pageId = trim((string)$row['pageID']);
    }
    if ($pixelId === '' && isset($row['pixelID'])) {
        $pixelId = trim((string)$row['pixelID']);
    }

    return [
        'bm' => trim((string)$bm),
        'geo' => normalizeDomainsFpGeo($geo),
        'domain' => trim((string)$domain),
        'fp_name' => trim((string)$fpName),
        'page_id' => trim((string)$pageId),
        'pixel_id' => trim((string)$pixelId),
        'status' => $status,
    ];
}

function validateDomainsFpRecord(array $row): array
{
    $errors = [];
    if ($row['bm'] === '') {
        $errors[] = 'bm is required';
    } elseif (mb_strlen($row['bm']) > 20) {
        $errors[] = 'bm max 20 characters';
    }

    if (!preg_match('/^[A-Z]{2}$/', $row['geo'])) {
        $errors[] = 'geo must be 2 letters';
    }

    if (mb_strlen($row['domain']) > 255) {
        $errors[] = 'domain max 255 characters';
    }
    if (mb_strlen($row['fp_name']) > 255) {
        $errors[] = 'fp_name max 255 characters';
    }
    if (mb_strlen($row['page_id']) > 255) {
        $errors[] = 'page_id max 255 characters';
    }
    if (mb_strlen($row['pixel_id']) > 255) {
        $errors[] = 'pixel_id max 255 characters';
    }

    return $errors;
}

function firstValue(array $row, array $keys, mixed $default = ''): mixed
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $value = $row[$key];
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    return $default;
}

function normalizeDomainsFpGeo(mixed $geo): string
{
    $geo = strtoupper(trim((string)$geo));
    return preg_match('/^[A-Z]{2}$/', $geo) ? $geo : 'XX';
}

function resolveDefaultDomainsFpOwnerId(PDO $db): ?int
{
    $stmt = $db->query("
        SELECT id
        FROM public.users
        WHERE role = 'admin' AND is_active = TRUE
        ORDER BY id
        LIMIT 1
    ");
    $id = $stmt ? $stmt->fetchColumn() : false;
    return $id !== false ? (int)$id : null;
}

function upsertDomainsFpRecord(PDO $db, array $row, ?int $ownerId): array
{
    $lookup = $db->prepare("
        SELECT id
        FROM public.domains_fp
        WHERE bm = :bm
          AND geo = :geo
          AND domain = :domain
          AND fp_name = :fp_name
          AND page_id = :page_id
          AND pixel_id = :pixel_id
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $lookup->execute([
        'bm' => $row['bm'],
        'geo' => $row['geo'],
        'domain' => $row['domain'],
        'fp_name' => $row['fp_name'],
        'page_id' => $row['page_id'],
        'pixel_id' => $row['pixel_id'],
    ]);
    $existingId = $lookup->fetchColumn();

    if ($existingId === false && $row['geo'] !== 'XX') {
        $lookupFallback = $db->prepare("
            SELECT id
            FROM public.domains_fp
            WHERE bm = :bm
              AND domain = :domain
              AND fp_name = :fp_name
              AND page_id = :page_id
              AND pixel_id = :pixel_id
              AND (TRIM(geo) = '' OR geo = 'XX')
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $lookupFallback->execute([
            'bm' => $row['bm'],
            'domain' => $row['domain'],
            'fp_name' => $row['fp_name'],
            'page_id' => $row['page_id'],
            'pixel_id' => $row['pixel_id'],
        ]);
        $existingId = $lookupFallback->fetchColumn();
    }

    if ($existingId !== false) {
        $stmt = $db->prepare("
            UPDATE public.domains_fp
            SET bm = :bm,
                geo = :geo,
                domain = :domain,
                fp_name = :fp_name,
                page_id = :page_id,
                pixel_id = :pixel_id,
                status = :status,
                user_id = COALESCE(user_id, :user_id),
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => (int)$existingId,
            'bm' => $row['bm'],
            'geo' => $row['geo'],
            'domain' => $row['domain'],
            'fp_name' => $row['fp_name'],
            'page_id' => $row['page_id'],
            'pixel_id' => $row['pixel_id'],
            'status' => $row['status'],
            'user_id' => $ownerId,
        ]);
        return ['updated' => true, 'id' => (int)$existingId];
    }

    $stmt = $db->prepare("
        INSERT INTO public.domains_fp
            (bm, geo, domain, fp_name, page_id, pixel_id, status, user_id)
        VALUES
            (:bm, :geo, :domain, :fp_name, :page_id, :pixel_id, :status, :user_id)
        RETURNING id
    ");
    $stmt->execute([
        'bm' => $row['bm'],
        'geo' => $row['geo'],
        'domain' => $row['domain'],
        'fp_name' => $row['fp_name'],
        'page_id' => $row['page_id'],
        'pixel_id' => $row['pixel_id'],
        'status' => $row['status'],
        'user_id' => $ownerId,
    ]);

    return ['updated' => false, 'id' => (int)$stmt->fetchColumn()];
}
