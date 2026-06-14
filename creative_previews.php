<?php
// creative_previews.php - creative info report and preview screenshots.
// @version 1.4.335
require __DIR__ . '/lib/DB.php';
require __DIR__ . '/lib/Auth.php';

$db = DB::getInstance();
$auth = new Auth($db);

$token = $_COOKIE['fb_ads_token'] ?? '';
if (!$token) {
    header('Location: /login.php');
    exit;
}
$me = $auth->check($token);
if (!$me) {
    setcookie('fb_ads_token', '', ['expires' => time() - 3600, 'path' => '/']);
    header('Location: /login.php');
    exit;
}

$uploadFsDir = __DIR__ . '/uploads/creative_previews';
$uploadUrlDir = '/uploads/creative_previews';
if (!is_dir($uploadFsDir)) {
    mkdir($uploadFsDir, 0775, true);
}

ensureCreativeInfoTable($db);
ensureCreativeInfoDefaults($db);

$action = $_GET['action'] ?? '';
if ($action === 'map') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => loadPreviewMap($db, $auth, $me, $uploadFsDir, $uploadUrlDir)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Admin only';
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    $creativeName = trim((string)($_POST['creative_name'] ?? ''));
    try {
        if ($creativeName === '') {
            throw new RuntimeException('Creative name is missing');
        }
        if ($postAction === 'upload') {
            if (!creativeRegisteredExists($db, $creativeName)) {
                throw new RuntimeException('Create the creative record first');
            }
            uploadPreview($uploadFsDir, $creativeName);
            $message = 'Preview saved';
        } elseif ($postAction === 'delete_preview') {
            if (!creativeRegisteredExists($db, $creativeName)) {
                throw new RuntimeException('Creative was not found');
            }
            deletePreview($uploadFsDir, $creativeName);
            $message = 'Preview deleted';
        } elseif ($postAction === 'save_info') {
            $existsBeforeSave = creativeRegisteredExists($db, $creativeName);
            saveCreativeInfo($db, $uploadFsDir, $creativeName, $_POST, (int)$me['id']);
            $message = $existsBeforeSave ? 'Info saved' : 'Creative created';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    if (($_POST['ajax'] ?? '') === '1') {
        $preview = $creativeName !== '' ? previewPayload($uploadFsDir, $uploadUrlDir, $creativeName) : null;
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $error === '',
            'message' => $message,
            'error' => $error,
            'preview' => $preview,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$onlyMissing = ($_GET['missing'] ?? '') === '1';
$rows = loadCreativeRows($db, $auth, $me, $uploadFsDir, $uploadUrlDir, $q, $onlyMissing);
$total = count($rows);

function ensureCreativeInfoTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS public.creative_info (
            creative_name varchar(255) PRIMARY KEY,
            author varchar(255) NOT NULL DEFAULT '',
            launch_date date NULL,
            approach_name varchar(255) NOT NULL DEFAULT '',
            notes text NOT NULL DEFAULT '',
            updated_by int NULL REFERENCES public.users(id) ON DELETE SET NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            updated_at timestamptz NOT NULL DEFAULT now()
        )
    ");
    $db->exec("ALTER TABLE public.creative_info ALTER COLUMN launch_date SET DEFAULT CURRENT_DATE");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_creative_info_launch_date ON public.creative_info (launch_date)");

    if ($db->query("SELECT to_regclass('public.ads')")->fetchColumn()) {
        $db->exec("
            CREATE OR REPLACE FUNCTION public.sync_creative_info_from_ads()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF COALESCE(BTRIM(NEW.name), '') <> '' AND COALESCE(NEW.status, '') <> 'DELETED' THEN
                    INSERT INTO public.creative_info (creative_name, launch_date)
                    VALUES (NEW.name, CURRENT_DATE)
                    ON CONFLICT (creative_name) DO NOTHING;
                END IF;
                RETURN NEW;
            END;
            $$;
        ");
        $db->exec("DROP TRIGGER IF EXISTS trg_ads_sync_creative_info ON public.ads");
        $db->exec("
            CREATE TRIGGER trg_ads_sync_creative_info
            AFTER INSERT OR UPDATE OF name, status ON public.ads
            FOR EACH ROW
            EXECUTE FUNCTION public.sync_creative_info_from_ads()
        ");
    }
}

function ensureCreativeInfoDefaults(PDO $db): void
{
    $yearStart = (new DateTime('first day of january this year'))->format('Y-m-d');
    $stmt = $db->prepare("UPDATE public.creative_info SET launch_date = CAST(:year_start AS date) WHERE launch_date IS NULL");
    $stmt->execute([':year_start' => $yearStart]);

    if ($db->query("SELECT to_regclass('public.ads')")->fetchColumn()) {
        $stmt = $db->prepare("
            INSERT INTO public.creative_info (creative_name, launch_date)
            SELECT src.creative_name, CAST(:year_start AS date)
            FROM (
                SELECT DISTINCT a.name AS creative_name
                FROM public.ads a
                WHERE COALESCE(BTRIM(a.name), '') <> ''
            ) src
            LEFT JOIN public.creative_info ci ON ci.creative_name = src.creative_name
            WHERE ci.creative_name IS NULL
        ");
        $stmt->execute([':year_start' => $yearStart]);
    }
}

function creativeRegisteredExists(PDO $db, string $creativeName): bool
{
    $stmt = $db->prepare("
        SELECT 1
        FROM (
            SELECT name AS creative_name FROM public.ads
            UNION
            SELECT creative_name FROM public.creative_info
        ) src
        WHERE src.creative_name = :name
        LIMIT 1
    ");
    $stmt->execute([':name' => $creativeName]);
    return (bool)$stmt->fetchColumn();
}

function creativeHasAds(PDO $db, string $creativeName): bool
{
    $stmt = $db->prepare("SELECT 1 FROM public.ads WHERE name = :name LIMIT 1");
    $stmt->execute([':name' => $creativeName]);
    return (bool)$stmt->fetchColumn();
}

function saveCreativeInfo(PDO $db, string $uploadFsDir, string $creativeName, array $body, int $userId): void
{
    if (mb_strlen($creativeName) > 255) {
        throw new RuntimeException('Creative name must be 255 characters or fewer');
    }
    $originalCreativeName = trim((string)($body['original_creative_name'] ?? $creativeName));
    if ($originalCreativeName === '') {
        $originalCreativeName = $creativeName;
    }
    $launchDate = trim((string)($body['launch_date'] ?? ''));
    if ($launchDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $launchDate)) {
        throw new RuntimeException('Launch date must be in YYYY-MM-DD format');
    }
    $author = trim((string)($body['author'] ?? ''));
    $approachName = trim((string)($body['approach_name'] ?? ''));
    $notes = trim((string)($body['notes'] ?? ''));
    $renameRequested = $originalCreativeName !== $creativeName;

    if ($renameRequested) {
        if (creativeHasAds($db, $originalCreativeName)) {
            throw new RuntimeException('Creatives linked to ads cannot be renamed');
        }
        if (!creativeRegisteredExists($db, $originalCreativeName)) {
            throw new RuntimeException('Original creative was not found');
        }
        if (creativeRegisteredExists($db, $creativeName)) {
            throw new RuntimeException('Creative name already exists');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                UPDATE public.creative_info
                SET creative_name = :creative_name,
                    author = :author,
                    launch_date = COALESCE(CAST(NULLIF(:launch_date, '') AS date), public.creative_info.launch_date, CURRENT_DATE),
                    approach_name = :approach_name,
                    notes = :notes,
                    updated_by = :updated_by,
                    updated_at = now()
                WHERE creative_name = :original_creative_name
            ");
            $stmt->execute([
                ':creative_name' => $creativeName,
                ':original_creative_name' => $originalCreativeName,
                ':author' => $author,
                ':launch_date' => $launchDate,
                ':approach_name' => $approachName,
                ':notes' => $notes,
                ':updated_by' => $userId,
            ]);
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('Original creative was not found');
            }
            renamePreviewFiles($uploadFsDir, $originalCreativeName, $creativeName);
            $db->commit();
            return;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
    $stmt = $db->prepare("
        INSERT INTO public.creative_info (creative_name, author, launch_date, approach_name, notes, updated_by)
        VALUES (
            :creative_name,
            :author,
            COALESCE(CAST(NULLIF(:launch_date, '') AS date), CURRENT_DATE),
            :approach_name,
            :notes,
            :updated_by
        )
        ON CONFLICT (creative_name) DO UPDATE SET
            author = EXCLUDED.author,
            launch_date = COALESCE(CAST(NULLIF(:launch_date, '') AS date), public.creative_info.launch_date),
            approach_name = EXCLUDED.approach_name,
            notes = EXCLUDED.notes,
            updated_by = EXCLUDED.updated_by,
            updated_at = now()
    ");
    $stmt->execute([
        ':creative_name' => $creativeName,
        ':author' => $author,
        ':launch_date' => $launchDate,
        ':approach_name' => $approachName,
        ':notes' => $notes,
        ':updated_by' => $userId,
    ]);
}

function loadPreviewMap(PDO $db, Auth $auth, array $me, string $uploadFsDir, string $uploadUrlDir): array
{
    $params = [];
    $where = "a.status != 'DELETED'";
    $allowedBmIds = array_map('strval', $auth->allowedBmIds($me));
    if (($me['role'] ?? '') !== 'admin') {
        if (!$allowedBmIds) {
            return [];
        }
        $ph = [];
        foreach ($allowedBmIds as $i => $bmId) {
            $key = ':bm' . $i;
            $ph[] = $key;
            $params[$key] = $bmId;
        }
        $where .= " AND aa.bm_id::text IN (" . implode(',', $ph) . ")";
    }
    $stmt = $db->prepare("
        SELECT DISTINCT a.name AS creative_name
        FROM public.ads a
        JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
        WHERE {$where}
        LIMIT 10000
    ");
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string)$row['creative_name'];
        $path = findPreviewPath($uploadFsDir, $uploadUrlDir, $name);
        if ($path !== null) {
            $map[$name] = $path;
        }
    }
    return $map;
}

function loadCreativeRows(PDO $db, Auth $auth, array $me, string $uploadFsDir, string $uploadUrlDir, string $q, bool $onlyMissing): array
{
    $params = [];
    $where = "a.status != 'DELETED'";
    $manualWhere = '1=1';
    $allowedBmIds = array_map('strval', $auth->allowedBmIds($me));
    if (!$allowedBmIds) {
        return [];
    }
    $bmPh = [];
    foreach ($allowedBmIds as $i => $bmId) {
        $key = ':bm' . $i;
        $bmPh[] = $key;
        $params[$key] = $bmId;
    }
    $where .= " AND aa.bm_id::text IN (" . implode(',', $bmPh) . ")";
    if ($q !== '') {
        $qGeo = strtoupper($q);
        if (preg_match('/^[A-Z]{2}$/', $qGeo)) {
            $where .= " AND (
                a.name ILIKE :q
                OR
                c.name ILIKE :geo_mid ESCAPE '\\'
                OR c.name ILIKE :geo_end ESCAPE '\\'
                OR c.name ILIKE :geo_space ESCAPE '\\'
            )";
            $manualWhere .= " AND ci.creative_name ILIKE :q";
            $params[':q'] = '%' . $q . '%';
            $params[':geo_mid'] = '%\\_' . $qGeo . '\\_%';
            $params[':geo_end'] = '%\\_' . $qGeo;
            $params[':geo_space'] = '%\\_' . $qGeo . ' %';
        } else {
            $where .= " AND a.name ILIKE :q";
            $manualWhere .= " AND ci.creative_name ILIKE :q";
            $params[':q'] = '%' . $q . '%';
        }
    }
    $stmt = $db->prepare("
        WITH creative_base AS (
            SELECT
                a.name AS creative_name,
                COUNT(*) AS ads_total,
                COUNT(*) FILTER (
                    WHERE aa.status = 1
                      AND a.status = 'ACTIVE'
                      AND a.effective_status = 'ACTIVE'
                ) AS ads_active,
                MIN(i.date) AS first_seen_at,
                MAX(a.synced_at) AS last_synced_at
            FROM public.ads a
            JOIN public.campaigns c ON c.id = a.campaign_id
            JOIN public.ad_accounts aa ON aa.id = a.ad_account_id
            LEFT JOIN public.insights_daily i ON i.ad_id = a.id
            WHERE {$where}
            GROUP BY a.name
            UNION ALL
            SELECT
                ci.creative_name AS creative_name,
                0 AS ads_total,
                0 AS ads_active,
                NULL::date AS first_seen_at,
                NULL::timestamptz AS last_synced_at
            FROM public.creative_info ci
            WHERE {$manualWhere}
              AND NOT EXISTS (
                  SELECT 1
                  FROM public.ads a2
                  WHERE a2.name = ci.creative_name
              )
        )
        SELECT
            cb.*,
            ci.author,
            ci.launch_date,
            ci.approach_name,
            ci.notes,
            ci.updated_at AS info_updated_at,
            COALESCE(u.display_name, u.username) AS updated_by_name
        FROM creative_base cb
        LEFT JOIN public.creative_info ci ON ci.creative_name = cb.creative_name
        LEFT JOIN public.users u ON u.id = ci.updated_by
        ORDER BY cb.ads_active DESC, cb.ads_total DESC, cb.creative_name
        LIMIT 1500
    ");
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $path = findPreviewPath($uploadFsDir, $uploadUrlDir, (string)$row['creative_name']);
        if ($onlyMissing && $path !== null) {
            continue;
        }
        $meta = $path ? previewMeta($uploadFsDir, $path) : ['width' => null, 'height' => null, 'updated_at' => null];
        $row['image_path'] = $path;
        $row['width'] = $meta['width'];
        $row['height'] = $meta['height'];
        $row['preview_updated_at'] = $meta['updated_at'];
        $rows[] = $row;
    }
    usort($rows, fn($a, $b) =>
        ((empty($b['image_path']) <=> empty($a['image_path'])))
        ?: ((int)$b['ads_active'] <=> (int)$a['ads_active'])
        ?: ((int)$b['ads_total'] <=> (int)$a['ads_total'])
        ?: strcmp((string)$a['creative_name'], (string)$b['creative_name'])
    );
    return $rows;
}

function uploadPreview(string $uploadFsDir, string $creativeName): void
{
    if (empty($_FILES['preview']) || !is_array($_FILES['preview'])) {
        throw new RuntimeException('No file selected');
    }
    $file = $_FILES['preview'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload error');
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('File is too large, maximum size is 8 MB');
    }
    $info = @getimagesize((string)$file['tmp_name']);
    if (!$info) {
        throw new RuntimeException('File must be an image');
    }
    [$width, $height] = $info;
    $mime = (string)($info['mime'] ?? '');
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extMap[$mime])) {
        throw new RuntimeException('Only JPG, PNG, or WEBP files are allowed');
    }
    if ($height <= 0 || $width <= 0) {
        throw new RuntimeException('Failed to detect image size');
    }
    $ratio = $width / $height;
    if (abs($ratio - (9 / 16)) > 0.05) {
        throw new RuntimeException('Preview must use the vertical Reels 9:16 format');
    }
    if (!extension_loaded('gd')) {
        throw new RuntimeException('PHP GD must be enabled to resize previews');
    }

    removePreviewFiles($uploadFsDir, $creativeName);
    $filename = creativePreviewFilename($creativeName, $extMap[$mime]);
    $target = $uploadFsDir . '/' . $filename;
    resizePreviewImage((string)$file['tmp_name'], $target, $mime, 720, 1280);
}

function resizePreviewImage(string $source, string $target, string $mime, int $targetWidth, int $targetHeight): void
{
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($source),
        'image/png' => @imagecreatefrompng($source),
        'image/webp' => @imagecreatefromwebp($source),
        default => false,
    };
    if (!$src) {
        throw new RuntimeException('Failed to read image');
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $scale = max($targetWidth / $srcW, $targetHeight / $srcH);
    $newW = (int)ceil($srcW * $scale);
    $newH = (int)ceil($srcH * $scale);
    $dstX = (int)floor(($targetWidth - $newW) / 2);
    $dstY = (int)floor(($targetHeight - $newH) / 2);

    $dst = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$dst) {
        imagedestroy($src);
        throw new RuntimeException('Failed to create preview');
    }

    imagealphablending($dst, true);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);

    if (!imagecopyresampled($dst, $src, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH)) {
        imagedestroy($src);
        imagedestroy($dst);
        throw new RuntimeException('Failed to resize preview');
    }

    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($dst, $target, 90),
        'image/png' => imagepng($dst, $target, 6),
        'image/webp' => imagewebp($dst, $target, 90),
        default => false,
    };
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        throw new RuntimeException('Failed to save file');
    }
}

function deletePreview(string $uploadFsDir, string $creativeName): void
{
    removePreviewFiles($uploadFsDir, $creativeName);
}

function removePreviewFiles(string $uploadFsDir, string $creativeName): void
{
    foreach (previewExtensions() as $ext) {
        $file = $uploadFsDir . '/' . creativePreviewFilename($creativeName, $ext);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function renamePreviewFiles(string $uploadFsDir, string $fromCreativeName, string $toCreativeName): void
{
    if ($fromCreativeName === $toCreativeName) {
        return;
    }
    foreach (previewExtensions() as $ext) {
        $fromFile = $uploadFsDir . '/' . creativePreviewFilename($fromCreativeName, $ext);
        if (!is_file($fromFile)) {
            continue;
        }
        $toFile = $uploadFsDir . '/' . creativePreviewFilename($toCreativeName, $ext);
        if (is_file($toFile)) {
            throw new RuntimeException('Preview for the new creative name already exists');
        }
        if (!@rename($fromFile, $toFile)) {
            throw new RuntimeException('Failed to rename preview file');
        }
    }
}

function creativePreviewFilename(string $creativeName, string $ext): string
{
    return hash('sha256', $creativeName) . '.' . $ext;
}

function previewExtensions(): array
{
    return ['jpg', 'png', 'webp'];
}

function findPreviewPath(string $uploadFsDir, string $uploadUrlDir, string $creativeName): ?string
{
    foreach (previewExtensions() as $ext) {
        $filename = creativePreviewFilename($creativeName, $ext);
        if (is_file($uploadFsDir . '/' . $filename)) {
            return $uploadUrlDir . '/' . $filename;
        }
    }
    return null;
}

function previewMeta(string $uploadFsDir, string $imagePath): array
{
    $file = $uploadFsDir . '/' . basename($imagePath);
    $size = is_file($file) ? @getimagesize($file) : null;
    return [
        'width' => $size ? (int)$size[0] : null,
        'height' => $size ? (int)$size[1] : null,
        'updated_at' => is_file($file) ? date('Y-m-d H:i:s', (int)filemtime($file)) : null,
    ];
}

function previewPayload(string $uploadFsDir, string $uploadUrlDir, string $creativeName): array
{
    $path = findPreviewPath($uploadFsDir, $uploadUrlDir, $creativeName);
    if ($path === null) {
        return [
            'image_path' => null,
            'width' => null,
            'height' => null,
            'preview_updated_at' => null,
            'cache_buster' => time(),
        ];
    }
    $meta = previewMeta($uploadFsDir, $path);
    return [
        'image_path' => $path,
        'width' => $meta['width'],
        'height' => $meta['height'],
        'preview_updated_at' => $meta['updated_at'],
        'cache_buster' => is_file($uploadFsDir . '/' . basename($path)) ? filemtime($uploadFsDir . '/' . basename($path)) : time(),
    ];
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Creative info - FB Ads</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f0f2f5;--surface:#fff;--border:#dddfe2;--border2:#ccd0d5;--border-light:#e4e6eb;
  --text:#1c1e21;--text2:#65676b;--text3:#8a8d91;--blue:#1877f2;--blue2:#166fe5;--blue-bg:#e7f0fd;
  --green:#31a24c;--green-bg:#e6f4ea;--red:#fa3e3e;--red-bg:#fce8e8;
  --shadow:0 1px 2px rgba(0,0,0,.08),0 1px 8px rgba(0,0,0,.05);--r:8px;--r2:6px;
}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px;min-height:100vh;display:flex;flex-direction:column}
.topbar{height:52px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;gap:12px;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.07);position:sticky;top:0;z-index:200}
.tb-logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:17px;color:var(--blue);letter-spacing:-.3px;text-decoration:none;white-space:nowrap}
.tb-logo-icon{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.tb-logo-icon svg{width:18px;height:18px;fill:#fff}
.tb-sep{width:1px;height:22px;background:var(--border2);margin:0 2px;flex-shrink:0}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.tb-user{display:flex;align-items:center;gap:6px;color:var(--text2);font-size:13px;white-space:nowrap}
.tb-avatar{width:28px;height:28px;border-radius:50%;background:var(--blue);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800;flex-shrink:0}
.tb-btn{padding:6px 12px;border:1.5px solid var(--border);border-radius:var(--r2);background:var(--surface);cursor:pointer;font-size:13px;font-family:inherit;font-weight:700;color:var(--text2);text-decoration:none;transition:all .15s;display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.tb-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.tb-btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.tb-btn.primary:hover{background:var(--blue2)}
.tb-btn.alert-red{border-color:var(--red);color:var(--red);background:var(--red-bg)}
.main{flex:1;padding:20px;display:flex;flex-direction:column;gap:16px}
.toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.toolbar h1{font-size:18px;font-weight:800;margin-right:6px}
.filter-group{display:flex;align-items:center;gap:5px}
.filter-label{font-size:12px;font-weight:800;color:var(--text3);white-space:nowrap;text-transform:uppercase;letter-spacing:.3px}
.flt{height:32px;padding:5px 10px;border:1.5px solid var(--border);border-radius:var(--r2);font:13px inherit;color:var(--text);background:var(--surface);outline:none}
.flt:focus{border-color:var(--blue)}
.ml-auto{margin-left:auto}
.note{font-size:12px;color:var(--text3);line-height:1.45}
.alert{padding:10px 12px;border-radius:var(--r2);font-size:13px;font-weight:700}
.alert.ok{background:var(--green-bg);color:var(--green)}
.alert.err{background:var(--red-bg);color:var(--red)}
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden}
.tbl-scroll{overflow:auto}
table{width:100%;border-collapse:collapse;min-width:1180px}
thead th{background:#f7f8fa;padding:9px 10px;text-align:left;font-size:11px;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.4px;border-bottom:1.5px solid var(--border);white-space:nowrap}
td{padding:10px;border-bottom:1px solid var(--border-light);vertical-align:top;font-size:13px}
tr:last-child td{border-bottom:none}
tr:hover td{background:var(--bg)}
.creative-name{font-weight:800;color:var(--text);line-height:1.35;word-break:break-word}
.dim{color:var(--text3);font-size:12px}
.num{font-variant-numeric:tabular-nums}
.preview-thumb{width:72px;height:128px;border-radius:6px;object-fit:cover;border:1px solid var(--border);background:#f7f8fa;display:block}
.preview-empty{width:72px;height:128px;border:1px dashed var(--border2);border-radius:6px;display:flex;align-items:center;justify-content:center;color:var(--text3);font-size:11px;text-align:center;background:#fafbfc}
.info-input,.info-textarea{width:100%;border:1.5px solid var(--border);border-radius:5px;background:var(--surface);font:13px inherit;color:var(--text);padding:8px 10px;outline:none}
.info-textarea{min-height:110px;resize:vertical;line-height:1.35}
.info-input:focus,.info-textarea:focus{border-color:var(--blue)}
.text-cell{max-width:340px;white-space:pre-wrap;line-height:1.35}
.preview-actions{display:flex;flex-direction:column;gap:7px;align-items:flex-start}
.upload-form{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.file-input{max-width:210px;font:12px inherit;color:var(--text2)}
.btn-sm{padding:5px 10px;border:1.5px solid var(--border);border-radius:5px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;background:var(--surface);color:var(--text2);white-space:nowrap}
.btn-sm:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
.btn-sm.primary{background:var(--blue);border-color:var(--blue);color:#fff}
.btn-sm.primary:hover{background:var(--blue2);color:#fff}
.btn-sm.danger:hover{border-color:var(--red);color:var(--red);background:var(--red-bg)}
.btn-sm:disabled{opacity:.55;cursor:wait}
.tbl-msg{text-align:center;padding:56px 20px;color:var(--text3);font-size:14px}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:flex-start;justify-content:center;padding:44px 16px;overflow:auto}
.modal-overlay.open{display:flex}
.modal-box{width:min(780px,96vw);background:var(--surface);border-radius:10px;box-shadow:0 18px 60px rgba(0,0,0,.28);overflow:hidden}
.modal-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:16px 18px;border-bottom:1px solid var(--border)}
.modal-title{font-size:16px;font-weight:800;line-height:1.35;word-break:break-word}
.modal-body{padding:18px;display:grid;grid-template-columns:130px 1fr;gap:18px}
.modal-preview{display:flex;flex-direction:column;gap:9px;align-items:flex-start}
.paste-zone{width:100%;min-height:76px;border:1.5px dashed var(--border2);border-radius:7px;background:#fafbfc;color:var(--text2);display:flex;align-items:center;justify-content:center;text-align:center;font-size:12px;font-weight:700;line-height:1.35;padding:10px;outline:none}
.paste-zone:focus{border-color:var(--blue);box-shadow:0 0 0 2px var(--blue-bg);background:#fff}
.modal-form{display:grid;grid-template-columns:1fr 150px;gap:12px}
.modal-form .wide{grid-column:1/-1}
.modal-footer{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid var(--border);background:#fafbfc}
.modal-status{margin-right:auto;font-size:12px;color:var(--text3)}
@media(max-width:900px){.ml-auto{margin-left:0}.table-wrap{border-radius:0;margin:0 -20px}}
@media(max-width:720px){.modal-body{grid-template-columns:1fr}.modal-form{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php include __DIR__ . '/_header.php'; ?>

<div class="main">
  <div class="toolbar">
    <h1>Creative info</h1>
    <form method="get" class="filter-group">
      <span class="filter-label">Search</span>
      <input class="flt" name="q" value="<?= h($q) ?>" placeholder="Creative name">
      <label class="filter-group" style="cursor:pointer">
        <input type="checkbox" name="missing" value="1" <?= $onlyMissing ? 'checked' : '' ?>>
        <span class="dim">missing preview</span>
      </label>
      <button class="tb-btn" type="submit">Show</button>
      <?php if ($q !== '' || $onlyMissing): ?><a class="tb-btn" href="/creative_previews.php">Reset</a><?php endif ?>
    </form>
    <button class="tb-btn" type="button" onclick="openNewCreativeModal()">New creative</button>
    <div class="note ml-auto">The preview stays linked to the creative name and is used in hover tooltips across reports.</div>
  </div>

  <?php if ($message): ?><div class="alert ok"><?= h($message) ?></div><?php endif ?>
  <?php if ($error): ?><div class="alert err"><?= h($error) ?></div><?php endif ?>

  <div class="table-wrap">
    <div class="tbl-scroll">
      <table>
        <thead>
          <tr>
            <th style="width:280px">Name</th>
            <th style="width:170px">Author</th>
            <th style="width:150px">Launch Date</th>
            <th style="width:120px">Preview</th>
            <th style="width:220px">Approach Name</th>
            <th>Notes</th>
            <th style="width:130px">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="tbl-msg">No creatives match the current filter</td></tr>
        <?php endif ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td>
              <div class="creative-name"><?= h($row['creative_name']) ?></div>
              <div class="dim num"><?= (int)$row['ads_active'] ?> active ads / <?= (int)$row['ads_total'] ?> total ads</div>
              <div class="dim">First seen: <?= h($row['first_seen_at'] ?: '—') ?></div>
              <div class="dim">Sync: <?= h($row['last_synced_at'] ?: '—') ?></div>
            </td>
            <td>
              <?= h($row['author'] ?: '—') ?>
            </td>
            <td>
              <?= h($row['launch_date'] ?: '—') ?>
            </td>
            <td>
              <?php if (!empty($row['image_path'])): ?>
                <img class="preview-thumb" src="<?= h($row['image_path']) ?>" alt="">
                <div class="dim"><?= (int)$row['width'] ?>x<?= (int)$row['height'] ?></div>
              <?php else: ?>
                <div class="preview-empty">no<br>preview</div>
              <?php endif ?>
            </td>
            <td>
              <?= h($row['approach_name'] ?: '—') ?>
            </td>
            <td>
              <div class="text-cell"><?= h($row['notes'] ?: '—') ?></div>
            </td>
            <td>
              <button class="btn-sm primary" type="button" onclick="openCreativeModal(<?= h(json_encode((string)$row['creative_name'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>)">Edit</button>
              <?php if (!empty($row['info_updated_at'])): ?>
                <div class="dim" style="margin-top:6px">Info: <?= h($row['info_updated_at']) ?></div>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="note">Shown: <?= $total ?>. Manual creatives are stored here even before they appear in the ads table.</div>
</div>

<div class="modal-overlay" id="creativeModal" onclick="if(event.target===this)closeCreativeModal()">
  <div class="modal-box">
    <div class="modal-hdr">
      <div>
        <div class="modal-title" id="modalCreativeName"></div>
        <div class="dim" id="modalCreativeMeta"></div>
      </div>
      <button class="btn-sm" type="button" onclick="closeCreativeModal()">Close</button>
    </div>
    <div class="modal-body">
      <div class="modal-preview">
        <div id="modalPreviewBox"></div>
        <input class="file-input" id="modalPreviewFile" type="file" accept="image/jpeg,image/png,image/webp,video/*">
        <div class="paste-zone" id="modalPasteZone" tabindex="0">Choose an image or video file<br>or press Ctrl+V to paste a preview</div>
        <button class="btn-sm danger" type="button" id="modalDeletePreviewBtn" onclick="deleteModalPreview()">Delete Preview</button>
        <div class="dim" id="modalPreviewMeta"></div>
      </div>
      <form class="modal-form" id="creativeInfoForm">
        <input type="hidden" name="action" value="save_info">
        <input type="hidden" name="original_creative_name" id="modalOriginalCreativeInput">
        <label class="wide">
          <span class="filter-label">Creative Name</span>
          <input class="info-input" name="creative_name" id="modalCreativeInput" maxlength="255" required>
        </label>
        <label>
          <span class="filter-label">Author</span>
          <input class="info-input" name="author" id="modalAuthor">
        </label>
        <label>
          <span class="filter-label">Launch Date</span>
          <input class="info-input" type="date" name="launch_date" id="modalLaunchDate">
        </label>
        <label class="wide">
          <span class="filter-label">Approach Name</span>
          <input class="info-input" name="approach_name" id="modalApproachName">
        </label>
        <label class="wide">
          <span class="filter-label">Notes</span>
          <textarea class="info-textarea" name="notes" id="modalNotes"></textarea>
        </label>
      </form>
    </div>
    <div class="modal-footer">
      <div class="modal-status" id="modalStatus"></div>
      <button class="btn-sm" type="button" onclick="closeCreativeModal()">Cancel</button>
      <button class="btn-sm primary" type="submit" form="creativeInfoForm">Save</button>
    </div>
  </div>
</div>

<script>
const CREATIVE_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const CREATIVE_BY_NAME = new Map(CREATIVE_ROWS.map(row => [row.creative_name, row]));
let currentCreativeName = '';
let isCreatingCreative = false;

function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderPreviewBox(row) {
    const box = document.getElementById('modalPreviewBox');
    const meta = document.getElementById('modalPreviewMeta');
    const deleteBtn = document.getElementById('modalDeletePreviewBtn');
    if (row?.image_path) {
        const src = row.cache_buster ? `${row.image_path}?v=${encodeURIComponent(row.cache_buster)}` : row.image_path;
        box.innerHTML = `<img class="preview-thumb" src="${escHtml(src)}" alt="">`;
        meta.textContent = `${row.width || ''}x${row.height || ''} ${row.preview_updated_at || ''}`.trim();
        deleteBtn.style.display = '';
    } else {
        box.innerHTML = '<div class="preview-empty">no<br>preview</div>';
        meta.textContent = '';
        deleteBtn.style.display = 'none';
    }
}

function syncCreativeModalMode() {
    const hasSavedCreative = !isCreatingCreative && !!currentCreativeName;
    const currentRow = currentCreativeName ? CREATIVE_BY_NAME.get(currentCreativeName) : null;
    const canRenameExisting = !!currentRow && Number(currentRow.ads_total || 0) === 0;
    document.getElementById('modalCreativeInput').readOnly = !(isCreatingCreative || canRenameExisting);
    document.getElementById('modalPreviewFile').disabled = false;
    document.getElementById('modalPasteZone').tabIndex = hasSavedCreative ? 0 : -1;
    document.getElementById('modalPasteZone').style.opacity = hasSavedCreative ? '1' : '.55';
    document.getElementById('modalDeletePreviewBtn').disabled = !hasSavedCreative;
}

function openCreativeModal(name) {
    const row = CREATIVE_BY_NAME.get(name);
    if (!row) return;
    isCreatingCreative = false;
    currentCreativeName = name;
    document.getElementById('modalCreativeName').textContent = name;
    document.getElementById('modalCreativeMeta').textContent = `${Number(row.ads_active || 0)} active ads / ${Number(row.ads_total || 0)} total ads · first seen: ${row.first_seen_at || '—'}`;
    document.getElementById('modalOriginalCreativeInput').value = name;
    document.getElementById('modalCreativeInput').value = name;
    document.getElementById('modalAuthor').value = row.author || '';
    document.getElementById('modalLaunchDate').value = row.launch_date || '';
    document.getElementById('modalApproachName').value = row.approach_name || '';
    document.getElementById('modalNotes').value = row.notes || '';
    document.getElementById('modalStatus').textContent = '';
    document.getElementById('modalPreviewFile').value = '';
    renderPreviewBox(row);
    syncCreativeModalMode();
    document.getElementById('creativeModal').classList.add('open');
}

function openNewCreativeModal() {
    isCreatingCreative = true;
    currentCreativeName = '';
    document.getElementById('modalCreativeName').textContent = 'New creative';
    document.getElementById('modalCreativeMeta').textContent = 'Create the record first. Preview upload becomes available after save.';
    document.getElementById('modalOriginalCreativeInput').value = '';
    document.getElementById('modalCreativeInput').value = '';
    document.getElementById('modalAuthor').value = '';
    document.getElementById('modalLaunchDate').value = '';
    document.getElementById('modalApproachName').value = '';
    document.getElementById('modalNotes').value = '';
    document.getElementById('modalStatus').textContent = '';
    document.getElementById('modalPreviewFile').value = '';
    renderPreviewBox(null);
    syncCreativeModalMode();
    document.getElementById('creativeModal').classList.add('open');
}

function closeCreativeModal() {
    document.getElementById('creativeModal').classList.remove('open');
}

async function postCreativeForm(fd) {
    fd.append('ajax', '1');
    const res = await fetch('/creative_previews.php', {method: 'POST', body: fd});
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'Save error');
    return json;
}

function applyPreviewResponse(json) {
    if (!json.preview || !currentCreativeName) return;
    const row = CREATIVE_BY_NAME.get(currentCreativeName);
    if (!row) return;
    row.image_path = json.preview.image_path || null;
    row.width = json.preview.width || null;
    row.height = json.preview.height || null;
    row.preview_updated_at = json.preview.preview_updated_at || null;
    row.cache_buster = json.preview.cache_buster || Date.now();
    renderPreviewBox(row);
}

function upsertCurrentCreativeRow(name) {
    const creativeName = String(name || '').trim();
    if (!creativeName) return null;
    let row = CREATIVE_BY_NAME.get(creativeName);
    if (!row) {
        row = {
            creative_name: creativeName,
            ads_total: 0,
            ads_active: 0,
            first_seen_at: '',
            last_synced_at: '',
            image_path: null,
            width: null,
            height: null,
            preview_updated_at: null,
            cache_buster: null,
        };
        CREATIVE_ROWS.unshift(row);
        CREATIVE_BY_NAME.set(creativeName, row);
    }
    row.author = document.getElementById('modalAuthor').value || '';
    row.launch_date = document.getElementById('modalLaunchDate').value || '';
    row.approach_name = document.getElementById('modalApproachName').value || '';
    row.notes = document.getElementById('modalNotes').value || '';
    return row;
}

async function ensureCreativeRecordForUpload() {
    const creativeName = String(document.getElementById('modalCreativeInput').value || '').trim();
    if (!creativeName) {
        throw new Error('Creative name is required before upload');
    }
    if (!isCreatingCreative && currentCreativeName) {
        return currentCreativeName;
    }
    const status = document.getElementById('modalStatus');
    status.textContent = 'Creating creative...';
    const fd = new FormData(document.getElementById('creativeInfoForm'));
    fd.set('creative_name', creativeName);
    fd.set('original_creative_name', '');
    await postCreativeForm(fd);
    currentCreativeName = creativeName;
    isCreatingCreative = false;
    document.getElementById('modalOriginalCreativeInput').value = creativeName;
    document.getElementById('modalCreativeName').textContent = creativeName;
    document.getElementById('modalCreativeMeta').textContent = '0 active ads / 0 total ads | first seen: -';
    upsertCurrentCreativeRow(creativeName);
    syncCreativeModalMode();
    return creativeName;
}

async function uploadPreviewBlob(blob, filename) {
    if (!currentCreativeName) return;
    const status = document.getElementById('modalStatus');
    status.textContent = 'Uploading preview...';
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('creative_name', currentCreativeName);
    fd.append('preview', blob, filename);
    const json = await postCreativeForm(fd);
    applyPreviewResponse(json);
    status.textContent = 'Preview saved';
}

function captureVideoFrameBlob(file, second = 5) {
    return new Promise((resolve, reject) => {
        const video = document.createElement('video');
        const objectUrl = URL.createObjectURL(file);
        let settled = false;
        const cleanup = () => {
            video.pause();
            video.removeAttribute('src');
            video.load();
            URL.revokeObjectURL(objectUrl);
        };
        const fail = (message) => {
            if (settled) return;
            settled = true;
            cleanup();
            reject(new Error(message));
        };
        const finish = (blob) => {
            if (settled) return;
            settled = true;
            cleanup();
            resolve(blob);
        };

        video.preload = 'metadata';
        video.muted = true;
        video.playsInline = true;
        video.crossOrigin = 'anonymous';

        video.addEventListener('error', () => fail('Failed to read video'), { once: true });
        video.addEventListener('loadedmetadata', () => {
            if (!video.videoWidth || !video.videoHeight) {
                fail('Failed to detect video size');
                return;
            }
            const duration = Number.isFinite(video.duration) ? video.duration : 0;
            const targetSecond = duration > 0 ? Math.max(0, Math.min(second, Math.max(0, duration - 0.1))) : 0;
            const seekHandler = () => {
                const targetWidth = 720;
                const targetHeight = 1280;
                const canvas = document.createElement('canvas');
                canvas.width = targetWidth;
                canvas.height = targetHeight;
                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    fail('Failed to initialize canvas');
                    return;
                }

                const srcW = video.videoWidth;
                const srcH = video.videoHeight;
                const srcRatio = srcW / srcH;
                const targetRatio = targetWidth / targetHeight;
                let cropW = srcW;
                let cropH = srcH;
                let cropX = 0;
                let cropY = 0;

                if (srcRatio > targetRatio) {
                    cropW = srcH * targetRatio;
                    cropX = (srcW - cropW) / 2;
                } else {
                    cropH = srcW / targetRatio;
                    cropY = (srcH - cropH) / 2;
                }

                ctx.drawImage(video, cropX, cropY, cropW, cropH, 0, 0, targetWidth, targetHeight);
                canvas.toBlob((blob) => {
                    if (!blob) {
                        fail('Failed to export video frame');
                        return;
                    }
                    finish(blob);
                }, 'image/jpeg', 0.92);
            };

            video.addEventListener('seeked', seekHandler, { once: true });
            try {
                video.currentTime = targetSecond;
            } catch (err) {
                fail('Failed to seek video to 5 seconds');
            }
        }, { once: true });

        video.src = objectUrl;
    });
}

function fileDateInputValue(file) {
    const ts = Number(file?.lastModified || 0);
    if (!Number.isFinite(ts) || ts <= 0) return '';
    const dt = new Date(ts);
    if (Number.isNaN(dt.getTime())) return '';
    const y = dt.getFullYear();
    const m = String(dt.getMonth() + 1).padStart(2, '0');
    const d = String(dt.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function creativeNameFromFilename(name) {
    return String(name || '').trim();
}

document.getElementById('modalPreviewFile').addEventListener('change', async e => {
    const file = e.target.files?.[0];
    if (!file) return;
    const status = document.getElementById('modalStatus');
    try {
        const inferredName = creativeNameFromFilename(file.name || 'preview');
        if (isCreatingCreative && inferredName && !String(document.getElementById('modalCreativeInput').value || '').trim()) {
            document.getElementById('modalCreativeInput').value = inferredName;
        }
        if (String(file.type || '').startsWith('video/')) {
            const launchDate = fileDateInputValue(file);
            if (launchDate) {
                document.getElementById('modalLaunchDate').value = launchDate;
            }
            await ensureCreativeRecordForUpload();
            status.textContent = 'Extracting frame at 5 seconds...';
            const blob = await captureVideoFrameBlob(file, 5);
            await uploadPreviewBlob(blob, inferredName + '.jpg');
        } else {
            await ensureCreativeRecordForUpload();
            await uploadPreviewBlob(file, file.name || 'preview.png');
        }
        e.target.value = '';
    } catch (err) {
        status.textContent = '';
        alert(err.message || String(err));
        e.target.value = '';
    }
});

document.getElementById('modalPasteZone').addEventListener('paste', async e => {
    if (!currentCreativeName) return;
    const items = Array.from(e.clipboardData?.items || []);
    const item = items.find(x => x.type && x.type.startsWith('image/'));
    if (!item) {
        document.getElementById('modalStatus').textContent = 'No image found in the clipboard';
        return;
    }
    e.preventDefault();
    const blob = item.getAsFile();
    if (!blob) return;
    const ext = item.type === 'image/jpeg' ? 'jpg' : item.type === 'image/webp' ? 'webp' : 'png';
    try {
        await uploadPreviewBlob(blob, 'clipboard.' + ext);
    } catch (err) {
        document.getElementById('modalStatus').textContent = '';
        alert(err.message || String(err));
    }
});

document.getElementById('creativeInfoForm').addEventListener('submit', async e => {
    e.preventDefault();
    const status = document.getElementById('modalStatus');
    status.textContent = 'Saving...';
    try {
        const fd = new FormData(e.currentTarget);
        currentCreativeName = String(fd.get('creative_name') || '').trim();
        await postCreativeForm(fd);
        status.textContent = 'Saved';
        window.location.reload();
    } catch (err) {
        status.textContent = '';
        alert(err.message || String(err));
    }
});

async function deleteModalPreview() {
    if (!currentCreativeName) return;
    if (!confirm('Delete the preview for this creative?')) return;
    const status = document.getElementById('modalStatus');
    status.textContent = 'Deleting preview...';
    try {
        const fd = new FormData();
        fd.append('action', 'delete_preview');
        fd.append('creative_name', currentCreativeName);
        const json = await postCreativeForm(fd);
        applyPreviewResponse(json);
        status.textContent = 'Preview deleted';
    } catch (err) {
        status.textContent = '';
        alert(err.message || String(err));
    }
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeCreativeModal();
});
</script>
</body>
</html>
