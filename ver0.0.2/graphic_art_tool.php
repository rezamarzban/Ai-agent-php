<?php
// ================================================
// graphic_art_tool.php
// ================================================
global $TOOL_FUNCTIONS;
if (!isset($TOOL_FUNCTIONS)) $TOOL_FUNCTIONS = [];

$TOOL_FUNCTIONS['graphic_art'] = function($params) {
    $prompt = $params['prompt'] ?? 'beautiful landscape';
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prompt);
    return [
        "image_url" => "https://picsum.photos/id/" . rand(100, 300) . "/800/600"
    ];
};
?>
