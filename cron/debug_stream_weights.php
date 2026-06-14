<?php
// cron/debug_stream_weights.php
// Debug helper: analyze stream offers and calculate weights across three buckets.
// Usage: php debug_stream_weights.php --stream=1158

declare(strict_types=1);

$cfg    = require __DIR__ . '/../config/config.php';
$KT_URL = rtrim($cfg['keitaro']['url'], '/');
$KT_KEY = $cfg['keitaro']['key'];
$TZ     = 'Europe/Chisinau';

const OFFER_GROUP_ID = 8;
const W_3D  = 0.8;
const W_30D = 0.2;
const PCT_A = 0.10;
const PCT_B = 0.10;
const PCT_C = 0.80;

function ktGet(string $baseUrl, string $key, string $endpoint): array {
    $ch = curl_init($baseUrl . '/admin_api/v1/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json', "Api-Key: {$key}"],
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        throw new RuntimeException("cURL: {$err}");
    }
    $data = json_decode($res, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON: " . substr($res, 0, 200));
    }
    return $data;
}

function ktPost(string $baseUrl, string $key, string $endpoint, array $body): array {
    $ch = curl_init($baseUrl . '/admin_api/v1/' . ltrim($endpoint, '/'));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', "Api-Key: {$key}"],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) {
        throw new RuntimeException("cURL: {$err}");
    }
    $data = json_decode($res, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON: " . substr($res, 0, 200));
    }
    if (isset($data['error'])) {
        throw new RuntimeException("KT API error: " . json_encode($data));
    }
    return $data;
}

function log_(string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
}

function fmt(float $v): string {
    return number_format($v, 4);
}

function fetchStats(string $baseUrl, string $key, string $tz, string $from, string $to): array {
    $data = ktPost($baseUrl, $key, 'report/build', [
        'range'      => ['interval'=>'custom','from'=>$from,'to'=>$to,'timezone'=>$tz],
        'measures'   => ['campaign_unique_clicks','uepc','ucpc'],
        'dimensions' => ['offer_id','offer'],
        'filters'    => ['AND'=>[['name'=>'offer_group_id','operator'=>'EQUALS','expression'=>OFFER_GROUP_ID]]],
        'sort'       => [['name'=>'campaign_unique_clicks','order'=>'desc']],
        'summary'    => false,
        'limit'      => 1000,
        'offset'     => 0,
    ]);
    $result = [];
    foreach ($data['rows'] ?? [] as $row) {
        $id = (int)($row['offer_id'] ?? 0);
        if (!$id) {
            continue;
        }
        $result[$id] = [
            'name'   => $row['offer'] ?? "Offer #{$id}",
            'uepc'   => (float)($row['uepc']  ?? 0),
            'ucpc'   => (float)($row['ucpc']  ?? 0),
            'clicks' => (int)($row['campaign_unique_clicks'] ?? 0),
        ];
    }
    return $result;
}

$args = getopt('', ['stream:']);
if (!isset($args['stream'])) {
    echo "Usage: php debug_stream_weights.php --stream=1158\n";
    exit(0);
}
$targetStreamId = (int)$args['stream'];
if (!$targetStreamId) {
    echo "Error: --stream must be a numeric ID\n";
    exit(1);
}

log_("=== STREAM DEBUG #{$targetStreamId} ===");

$now    = new DateTime('now', new DateTimeZone($TZ));
$from3  = (clone $now)->modify('-3 days midnight')->format('Y-m-d H:i');
$from30 = (clone $now)->modify('-30 days midnight')->format('Y-m-d H:i');
$to     = $now->format('Y-m-d H:i');

log_("Step 1: 3d stats ({$from3} -> {$to})...");
$stat3 = fetchStats($KT_URL, $KT_KEY, $TZ, $from3, $to);
log_('Offers in 3d stats: ' . count($stat3));

log_("Step 2: 30d stats ({$from30} -> {$to})...");
$stat30 = fetchStats($KT_URL, $KT_KEY, $TZ, $from30, $to);
log_('Offers in 30d stats: ' . count($stat30));

$allStatIds = array_unique(array_merge(array_keys($stat3), array_keys($stat30)));
$blended = [];
foreach ($allStatIds as $id) {
    $u3  = $stat3[$id]['uepc']  ?? 0;
    $u30 = $stat30[$id]['uepc'] ?? 0;
    $c3  = $stat3[$id]['ucpc']  ?? 0;
    $c30 = $stat30[$id]['ucpc'] ?? 0;
    $blended[$id] = [
        'name'         => $stat3[$id]['name'] ?? $stat30[$id]['name'] ?? "Offer #{$id}",
        'uepc_3'       => $u3,
        'uepc_30'      => $u30,
        'ucpc_3'       => $c3,
        'ucpc_30'      => $c30,
        'clicks_3'     => $stat3[$id]['clicks']  ?? 0,
        'clicks_30'    => $stat30[$id]['clicks'] ?? 0,
        'blended_uepc' => $u3 * W_3D + $u30 * W_30D,
        'blended_ucpc' => $c3 * W_3D + $c30 * W_30D,
    ];
}

log_("Step 3: finding stream #{$targetStreamId}...");
$allCampaigns = ktGet($KT_URL, $KT_KEY, 'campaigns');
$foundStream = $foundCamp = null;
foreach ($allCampaigns as $camp) {
    $campId = (int)($camp['id'] ?? 0);
    if (!$campId) {
        continue;
    }
    $campStreams = ktGet($KT_URL, $KT_KEY, "campaigns/{$campId}/streams");
    foreach ($campStreams as $stream) {
        if ((int)($stream['id'] ?? 0) === $targetStreamId) {
            $foundStream = $stream;
            $foundCamp = $camp;
            break 2;
        }
    }
}
if (!$foundStream) {
    log_("STREAM #{$targetStreamId} NOT FOUND");
    exit(1);
}

echo PHP_EOL;
echo str_repeat('=', 64)."\n";
echo "  Campaign : {$foundCamp['name']} [id={$foundCamp['id']}, state={$foundCamp['state']}]\n";
echo "  Stream   : {$foundStream['name']} [id={$targetStreamId}, state=" . ($foundStream['state'] ?? '?') . "]\n";
echo str_repeat('=', 64)."\n\n";

$rawOffers = $foundStream['offers'] ?? [];
log_("Offers in stream: " . count($rawOffers));

$bucketA = [];
$bucketB = [];
$bucketC = [];
$ignored = [];

foreach ($rawOffers as $link) {
    $offerId = (int)($link['offer_id'] ?? 0);
    $share   = (int)($link['share'] ?? 0);
    $state   = $link['state'] ?? '';

    if ($state === 'deleted' || $share === 0) {
        $ignored[$offerId] = ['share'=>$share, 'reason'=>$state === 'deleted' ? 'deleted' : 'share=0'];
        continue;
    }
    $b = $blended[$offerId] ?? null;
    if ($b === null) {
        $bucketA[$offerId] = ['share'=>$share];
    } elseif ($b['blended_uepc'] <= 0) {
        if ($b['blended_ucpc'] > 0) {
            $bucketB[$offerId] = ['share'=>$share,'blended_ucpc'=>$b['blended_ucpc']];
        } else {
            $bucketA[$offerId] = ['share'=>$share];
        }
    } else {
        $bucketC[$offerId] = ['share'=>$share,'blended_uepc'=>$b['blended_uepc']];
    }
}

$newWeights = [];

if (!empty($bucketA)) {
    $each = max(1, (int)round(100 * PCT_A / count($bucketA)));
    foreach ($bucketA as $id => $_) {
        $newWeights[$id] = $each;
    }
}

if (!empty($bucketB)) {
    $scores = [];
    foreach ($bucketB as $id => $d) {
        $scores[$id] = $d['blended_ucpc'] > 0 ? 1.0 / $d['blended_ucpc'] : 0;
    }
    $sumScores = array_sum($scores);
    $budget = 100 * PCT_B;
    foreach ($bucketB as $id => $_) {
        $newWeights[$id] = max(1, (int)round($sumScores > 0 ? $budget * $scores[$id] / $sumScores : 1));
    }
}

if (!empty($bucketC)) {
    $sumUepc = array_sum(array_column($bucketC, 'blended_uepc'));
    $budget = 100 * PCT_C;
    foreach ($bucketC as $id => $d) {
        $newWeights[$id] = max(1, (int)round($sumUepc > 0 ? $budget * $d['blended_uepc'] / $sumUepc : 1));
    }
}

$separator = str_repeat('-', 64);

$printOffer = function (int $id, string $bucket, string $bucketDesc) use ($blended, $newWeights, $bucketA, $bucketB, $bucketC, $ignored, $separator): void {
    $b    = $blended[$id] ?? null;
    $name = $b['name'] ?? "Offer #{$id}";
    $old  = ($bucketA[$id] ?? $bucketB[$id] ?? $bucketC[$id] ?? $ignored[$id] ?? [])['share'] ?? 0;
    $new  = $newWeights[$id] ?? null;
    $arrow = $new !== null ? ($new > $old ? 'up' : ($new < $old ? 'down' : '=')) : '';

    echo "  |- [{$bucket}] {$name} (id={$id})\n";
    echo "  |  Bucket    : {$bucketDesc}\n";
    echo "  |  Old weight: {$old}\n";

    if ($b) {
        echo "  |\n";
        echo "  |  Stats:\n";
        echo "  |    UEPC  3d  = " . fmt($b['uepc_3'])  . "  (clicks: {$b['clicks_3']})\n";
        echo "  |    UEPC  30d = " . fmt($b['uepc_30']) . "  (clicks: {$b['clicks_30']})\n";
        echo "  |    uCPC  3d  = " . fmt($b['ucpc_3'])  . "\n";
        echo "  |    uCPC  30d = " . fmt($b['ucpc_30']) . "\n";
        echo "  |\n";
        echo "  |  Formula:\n";
        echo "  |    blended UEPC = " . fmt($b['uepc_3']) . " * 0.8 + " . fmt($b['uepc_30']) . " * 0.2 = " . fmt($b['blended_uepc']) . "\n";
        echo "  |    blended uCPC = " . fmt($b['ucpc_3']) . " * 0.8 + " . fmt($b['ucpc_30']) . " * 0.2 = " . fmt($b['blended_ucpc']) . "\n";

        if ($bucket === 'B' && $b['blended_ucpc'] > 0) {
            $score = 1.0 / $b['blended_ucpc'];
            echo "  |    score (1/uCPC) = 1 / " . fmt($b['blended_ucpc']) . " = " . fmt($score) . "\n";
        }
        if ($bucket === 'C') {
            echo "  |    weight = 80 * blended_uepc / total_blended_uepc\n";
        }
    } else {
        echo "  |  Stats: none (new offer)\n";
        echo "  |  Formula: equal weight inside bucket A\n";
    }

    echo "  |\n";
    if ($new !== null) {
        echo "  |  New weight: {$new} {$arrow}\n";
    } else {
        echo "  |  New weight: not calculated (ignored)\n";
    }
    echo "  +{$separator}\n\n";
};

if (!empty($bucketC)) {
    $sumUepc = array_sum(array_column($bucketC, 'blended_uepc'));
    echo "\n== BUCKET C - blended UEPC > 0 [80% budget, {$sumUepc} total UEPC, " . count($bucketC) . " offers]\n\n";
    foreach (array_keys($bucketC) as $id) {
        $printOffer($id, 'C', 'Has blended UEPC -> weight is proportional to UEPC');
    }
}

if (!empty($bucketB)) {
    echo "\n== BUCKET B - no UEPC, has uCPC [10% budget, " . count($bucketB) . " offers]\n\n";
    foreach (array_keys($bucketB) as $id) {
        $printOffer($id, 'B', 'UEPC=0 but uCPC>0 -> weight is inverse to uCPC');
    }
}

if (!empty($bucketA)) {
    $each = $newWeights[array_key_first($bucketA)] ?? '?';
    echo "\n== BUCKET A - no stats [10% budget, equal by {$each}, " . count($bucketA) . " offers]\n\n";
    foreach (array_keys($bucketA) as $id) {
        $printOffer($id, 'A', 'No stats -> equal weight');
    }
}

if (!empty($ignored)) {
    echo "\n== IGNORED [" . count($ignored) . " offers]\n\n";
    foreach ($ignored as $id => $d) {
        $name = $blended[$id]['name'] ?? "Offer #{$id}";
        echo "  |- [ ] {$name} (id={$id})\n";
        echo "  |  Reason    : {$d['reason']}\n";
        echo "  |  Old weight: {$d['share']}\n";
        echo "  |  New weight: unchanged\n";
        echo "  +{$separator}\n\n";
    }
}

$sumA = array_sum(array_intersect_key($newWeights, $bucketA));
$sumB = array_sum(array_intersect_key($newWeights, $bucketB));
$sumC = array_sum(array_intersect_key($newWeights, $bucketC));
echo "\n";
log_("== SUMMARY =====================================");
log_("Total offers in stream : " . count($rawOffers));
log_("Ignored                : " . count($ignored) . " (share=0 or deleted)");
log_("Bucket A (new)         : " . count($bucketA) . " offers -> weight sum {$sumA} (target ~10)");
log_("Bucket B (uCPC only)   : " . count($bucketB) . " offers -> weight sum {$sumB} (target ~10)");
log_("Bucket C (has UEPC)    : " . count($bucketC) . " offers -> weight sum {$sumC} (target ~80)");
log_("Total weight sum       : " . ($sumA + $sumB + $sumC));
