<?php
// api/ext/pexels_photo.php — Pexels photo picker for FP setup
// POST { secret }
// Response: { ok, query, keywords, image_url, photo_url, photographer, photographer_url, alt }

require __DIR__ . '/_bootstrap.php';

$apiKey = trim((string)(getenv('PEXELS_API_KEY') ?: ($cfg['pexels']['api_key'] ?? '')));
if ($apiKey === '') {
    extError(500, 'Pexels API key not configured');
}

$keywords = [
    'casino','jackpot','roulette','poker','blackjack','slots','slot machine','gaming','wager','betting',
    'online casino','live casino','casino bonus','spin','reels','cards','chips','dice','croupier','dealer',
    'winner','winning','prize','luck','lucky','fortune','gold','neon','nightlife','entertainment',
    'luxury','vip','premium','reward','bonus','cashback','free spins','mega win','big win','progressive jackpot',
    'table games','card game','royal flush','ace','king','queen','red black','green felt','casino table','gaming floor',
    'sparkle','glamour','las vegas','monte carlo','resort','lounge','bar','celebration','confetti','money',
    'cash','coins','tokens','treasure','wealth','riches','diamond','chrome','lights','spotlight',
    'mobile gaming','smartphone','online play','digital game','app gaming','arcade','strategy','chance','risk','thrill',
    'excitement','adrenaline','mystery','magic','golden','red velvet','black gold','club','night club','casino night',
    'wheel','roulette wheel','poker chips','playing cards','slot reels','777','bell','horseshoe','stars','win screen'
];

shuffle($keywords);
$selected = array_slice($keywords, 0, random_int(2, 3));
$query = implode(' ', $selected);
$page = random_int(1, 5);
$params = http_build_query([
    'query' => $query,
    'orientation' => 'landscape',
    'size' => 'large',
    'locale' => 'en-US',
    'page' => $page,
    'per_page' => 30,
]);
$url = 'https://api.pexels.com/v1/search?' . $params;

$headers = [
    'Authorization: ' . $apiKey,
    'Accept: application/json',
    'User-Agent: mlrMetaAdsCreator/1.0',
];

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        extError(502, 'Pexels request failed: ' . ($err ?: ('HTTP ' . $status)));
    }
} else {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($url, false, $ctx);
    $statusLine = $http_response_header[0] ?? '';
    if ($raw === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
        extError(502, 'Pexels request failed: ' . ($statusLine ?: 'no response'));
    }
}

$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    extError(502, 'Invalid Pexels response');
}

$photos = $data['photos'] ?? [];
if (!is_array($photos) || !$photos) {
    extError(404, 'No Pexels photos found for query: ' . $query);
}

$photo = $photos[array_rand($photos)];
$src = is_array($photo['src'] ?? null) ? $photo['src'] : [];
$imageUrl = (string)($src['large2x'] ?? $src['large'] ?? $src['landscape'] ?? $src['original'] ?? '');
if ($imageUrl === '') {
    extError(502, 'Pexels photo has no usable image URL');
}

extOk([
    'query' => $query,
    'keywords' => $selected,
    'image_url' => $imageUrl,
    'photo_url' => (string)($photo['url'] ?? ''),
    'photographer' => (string)($photo['photographer'] ?? ''),
    'photographer_url' => (string)($photo['photographer_url'] ?? ''),
    'alt' => (string)($photo['alt'] ?? ''),
]);