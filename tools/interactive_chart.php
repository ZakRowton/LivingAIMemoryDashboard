<?php
header('Content-Type: application/json');
$input = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? 'bar';
$labels = $input['labels'] ?? [];
$values = $input['values'] ?? [];
$chartId = 'chart_' . uniqid();
$html = "<canvas id=\"$chartId\"></canvas>\n<script src=\"https://cdn.jsdelivr.net/npm/chart.js\"></script>\n<script>\nvar ctx = document.getElementById('\"$chartId\"').getContext('2d');\nnew Chart(ctx, {\n    type: '\"$type\"',\n    data: {\n        labels: " . json_encode($labels) . ",\n        datasets: [{\n            label: 'Dataset',\n            data: " . json_encode($values) . ",\n            backgroundColor: 'rgba(75, 192, 192, 0.2)',\n            borderColor: 'rgba(75, 192, 192, 1)',\n            borderWidth: 1\n        }]\n    },\n    options: {}\n});\n</script>";
echo json_encode(['result' => $html]);
?>