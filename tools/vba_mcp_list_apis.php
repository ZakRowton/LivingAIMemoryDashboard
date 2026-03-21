<?php
// Return a static list of API names for Vba MCP
header('Content-Type: application/json');
$apis = [
    'UserAPI',
    'PayrollAPI',
    'ReportingAPI',
    'AnalyticsAPI'
];
echo json_encode(['apis' => $apis]);
?>