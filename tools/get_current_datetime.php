<?php
if (!function_exists('get_current_datetime')) {
    /**
     * Returns the current server date and time in ISO 8601 format.
     *
     * @return string ISO 8601 formatted date-time.
     */
    function get_current_datetime(): string {
        // Use PHP's built-in date function with the ISO 8601 format.
        return date('c');
    }
}

header('Content-Type: application/json');
echo json_encode([
    'result' => get_current_datetime(),
]);
?>
