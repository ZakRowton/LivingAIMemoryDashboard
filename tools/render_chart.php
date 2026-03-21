<?php
/**
 * Renders a chart as a styled <img> for QuickChart or any https chart image URL.
 * Tool output is HTML; chat wraps it in { result: "..." } for the model.
 */
$arguments = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$arguments = is_array($arguments) ? $arguments : [];

$url = trim((string) ($arguments['chart_url'] ?? $arguments['url'] ?? ''));
$config = $arguments['chart_config'] ?? $arguments['config'] ?? null;
if (is_string($config) && $config !== '') {
    $decodedCfg = json_decode($config, true);
    $config = is_array($decodedCfg) ? $decodedCfg : $config;
}

if ($url === '' && $config !== null) {
    $json = is_string($config) ? $config : json_encode($config, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        echo json_encode(['error' => 'Invalid chart_config (could not encode JSON).']);
        return;
    }
    $url = 'https://quickchart.io/chart?c=' . rawurlencode($json);
}

if ($url === '') {
    echo json_encode(['error' => 'Provide chart_url (https image) or chart_config (QuickChart / Chart.js config object).']);
    return;
}

if (!preg_match('#^https://#i', $url)) {
    echo json_encode(['error' => 'Chart URL must start with https://']);
    return;
}

$html = '<div class="mg-chart-embed" style="margin:18px 0;text-align:center">'
    . '<img src="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="Chart" '
    . 'style="max-width:100%;height:auto;border-radius:10px;border:1px solid rgba(212,175,55,0.28);'
    . 'box-shadow:0 4px 28px rgba(0,0,0,0.35)" loading="lazy" referrerpolicy="no-referrer" />'
    . '</div>';

echo $html;
