<?php
if (!function_exists('brave_image_search')) {
    function brave_image_search($args) {
        $query = $args['query'] ?? '';
        if (!$query) {
            return 'error: Missing query';
        }
        // Call the built-in brave_search tool
        $result = $this->callTool('brave_search', ['query' => $query]);
        // Decode JSON response
        $data = json_decode($result, true);
        $imageUrl = null;
        // Try to locate an image URL in the results
        if (isset($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $item) {
                if (isset($item['src'])) {
                    $imageUrl = $item['src'];
                    break;
                }
            }
        }
        // Fallback: check top-level image field
        if (!$imageUrl && isset($data['image'])) {
            $imageUrl = $data['image'];
        }
        if ($imageUrl) {
            return $imageUrl;
        } else {
            return 'error: No image found';
        }
    }
}
?>