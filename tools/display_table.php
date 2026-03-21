<?php
// This tool generates a styled HTML table with dark mode default styling.
$arguments = $GLOBALS['MEMORY_GRAPH_TOOL_INPUT'] ?? [];
$arguments = is_array($arguments) ? $arguments : [];
$headers = isset($arguments['headers']) && is_array($arguments['headers']) ? $arguments['headers'] : [];
$rows = isset($arguments['rows']) && is_array($arguments['rows']) ? $arguments['rows'] : [];
$style = isset($arguments['style']) ? (string) $arguments['style'] : '';
$caption = isset($arguments['caption']) ? (string) $arguments['caption'] : '';

// Default CSS – dark mode styling if none provided
if (empty($style)) {
    $style = "
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
        font-size: 14px;
        color: #e0e0e0;
        background-color: #1e1e1e;
        margin: 20px 0;
        box-shadow: 0 2px 5px rgba(0,0,0,0.5);
        border-radius: 6px;
        overflow: hidden;
    ";
}
$cellStyle = "padding: 12px 15px; border-bottom: 1px solid #333;";
$headerStyle = $cellStyle . ' background-color: #2c2c2c; font-weight: 600;';

$html = "<table style=\"{$style}\">";
if (!empty($caption)) {
    $html .= "<caption style=\"font-weight: bold; margin-bottom: 8px; font-size: 1.2em; text-align: left; color: #e0e0e0;\">" . htmlspecialchars($caption) . "</caption>";
}

// Header row
if (!empty($headers)) {
    $html .= "<thead><tr>";
    foreach ($headers as $header) {
        $html .= "<th style=\"{$headerStyle}\">" . htmlspecialchars($header) . "</th>";
    }
    $html .= "</tr></thead>";
}

// Body rows with zebra striping and hover effect
$html .= "<tbody>";
$rowIndex = 0;
foreach ($rows as $row) {
    $bg = ($rowIndex % 2 == 0) ? '#1e1e1e' : '#262626';
    $html .= "<tr style=\"background-color: {$bg};" . ($rowIndex % 2 == 0 ? '' : '') . "\" onmouseover=\"this.style.backgroundColor='#3a3a3a'\" onmouseout=\"this.style.backgroundColor='{$bg}'\">";
    foreach ($row as $cell) {
        $html .= "<td style=\"{$cellStyle}\">" . htmlspecialchars((string)$cell) . "</td>";
    }
    $html .= "</tr>";
    $rowIndex++;
}
$html .= "</tbody>";

$html .= "</table>";
echo $html;
?>