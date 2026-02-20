<?php
// ================================================
// search_web_tool.php
// ================================================
global $TOOL_FUNCTIONS;
if (!isset($TOOL_FUNCTIONS)) $TOOL_FUNCTIONS = [];

$TOOL_FUNCTIONS['search_web'] = function($params) {
    $query = $params['query'] ?? 'No query provided';
    return [
        "results" => "🔎 Fake search results for '{$query}' (from your custom cloud AI agent)"
    ];
};
?>
