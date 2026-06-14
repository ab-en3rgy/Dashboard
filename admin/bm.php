<?php
$query = $_GET;
$query['tab'] = $query['tab'] ?? 'bms';
$target = '/admin/accounts.php';
if ($query) {
    $target .= '?' . http_build_query($query);
}
header('Location: ' . $target, true, 302);
exit;
