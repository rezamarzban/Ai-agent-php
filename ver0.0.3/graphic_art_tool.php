<?php
// ================================================
// graphic_art_tool.php
// ================================================
global $TOOL_FUNCTIONS, $TOOL_SCHEMAS;

if (!isset($TOOL_FUNCTIONS)) $TOOL_FUNCTIONS = [];
if (!isset($TOOL_SCHEMAS))   $TOOL_SCHEMAS   = [];

$TOOL_FUNCTIONS['graphic_art'] = function($params) {
    $prompt = $params['prompt'] ?? 'beautiful landscape';
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $prompt);
    return [
        "image_url" => "https://picsum.photos/id/" . rand(100, 300) . "/800/600"
    ];
};

// Tool schema for this tool only
$TOOL_SCHEMAS[] = [
    "type" => "function",
    "function" => [
        "name" => "graphic_art",
        "description" => "Generate graphic art based on a prompt.",
        "parameters" => [
            "type" => "object",
            "properties" => [
                "prompt" => [
                    "type" => "string",
                    "description" => "The prompt describing the desired graphic art."
                ]
            ],
            "required" => ["prompt"]
        ]
    ]
];
?>
