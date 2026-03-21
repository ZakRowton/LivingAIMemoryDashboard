<?php
function run($args) {
    $dt = new DateTime("now", new DateTimeZone("America/Chicago"));
    return $dt->format('Y-m-d H:i:s T');
}

header('Content-Type: application/json');
echo json_encode([
    'result' => run([]),
]);
?>
