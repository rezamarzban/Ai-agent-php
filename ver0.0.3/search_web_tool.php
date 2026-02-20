<?php
// ================================================
// search_web_tool.php
// ================================================
global $TOOL_FUNCTIONS, $TOOL_SCHEMAS;

if (!isset($TOOL_FUNCTIONS)) $TOOL_FUNCTIONS = [];
if (!isset($TOOL_SCHEMAS))   $TOOL_SCHEMAS   = [];

$TOOL_FUNCTIONS['search_web'] = function($params) {
    $query = $params['query'] ?? 'No query provided';
    return [
        "results" => "🔎 Fake search results for '{$query}' (from your custom cloud AI agent)"
    ];
};

// Tool schema for this tool only
$TOOL_SCHEMAS[] = [
    "type" => "function",
    "function" => [
        "name" => "search_web",
        "description" => "Search the web for information.",
        "parameters" => [
            "type" => "object",
            "properties" => [
                "query" => [
                    "type" => "string",
                    "description" => "The search query."
                ]
            ],
            "required" => ["query"]
        ]
    ]
];
?>
