<?php
session_start();

// Initialize chat history with system prompt
if (!isset($_SESSION['history'])) {
    $_SESSION['history'] = [
        [
            "role" => "system",
            "content" => "You are a helpful assistant. If the user asks to search for several things, call search_web multiple times in parallel using several tool_calls at once."
        ]
    ];
}
$history = &$_SESSION['history'];

// Configuration
define('LLAMA_SERVER_URL', 'http://127.0.0.1:8080/v1/chat/completions');
define('MODEL', 'llama-3.1-8b-instruct');
define('TIMEOUT', 600);
define('MAX_RETRIES', 3);

// Tool implementations (fake, like the Python version)
$TOOL_FUNCTIONS = [
    'search_web' => function($params) {
        $query = $params['query'] ?? '';
        return ['results' => "Fake search results for '{$query}'"];
    },
    'graphic_art' => function($params) {
        $prompt = $params['prompt'] ?? '';
        return ['image_url' => "https://example.com/" . str_replace(' ', '_', $prompt) . ".png"];
    }
];

// Tool schemas (exactly as in Python)
$TOOLS_SCHEMAS = [
    [
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
    ],
    [
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
    ]
];

// Streaming model call with full accumulation (mirrors Python stream_model exactly)
function stream_model($messages) {
    global $TOOLS_SCHEMAS;

    $payload = [
        "model" => MODEL,
        "messages" => $messages,
        "tools" => $TOOLS_SCHEMAS,
        "tool_choice" => "auto",
        "stream" => true,
        "temperature" => 0.7,
        "top_p" => 0.95,
        "max_tokens" => 4096,
        "presence_penalty" => 0.0,
        "frequency_penalty" => 0.0
    ];

    $ch = curl_init(LLAMA_SERVER_URL);
    curl_setopt($ch, CURLOPT_URL, LLAMA_SERVER_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer YOUR_API_KEY_HERE'   // ← paste your key here
]);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);

    $resp_body = null;
    for ($attempt = 0; $attempt < MAX_RETRIES; $attempt++) {
        $resp_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($resp_body !== false && $http_code >= 200 && $http_code < 300) {
            break;
        }

        if ($attempt < MAX_RETRIES - 1) {
            $wait = pow(2, $attempt) * 1.5;
            sleep($wait);
        } else {
            $error = curl_error($ch) ?: 'Unknown error';
            curl_close($ch);
            return [
                "role" => "assistant",
                "content" => "[Connection error: {$error}]"
            ];
        }
    }
    curl_close($ch);

    if (!$resp_body) {
        return ["role" => "assistant", "content" => "[No response from server]"];
    }

    // Parse streamed SSE response (exact logic from Python)
    $accumulated_content = "";
    $accumulated_tool_calls = [];
    $accumulated_function_call = ["name" => "", "arguments" => ""];
    $token_count = 0;
    $start_time = null;

    $lines = explode("\n", $resp_body);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || !str_starts_with($line, "data: ")) {
            continue;
        }
        $data = trim(substr($line, 6));
        if ($data === "[DONE]") {
            break;
        }

        $chunk = json_decode($data, true);
        if (!$chunk || !isset($chunk["choices"][0]["delta"])) {
            continue;
        }

        $delta = $chunk["choices"][0]["delta"];

        // Content tokens
        if (isset($delta["content"]) && $delta["content"] !== null) {
            $token = $delta["content"];
            $accumulated_content .= $token;
            $token_count++;
            if ($start_time === null) {
                $start_time = microtime(true);
            }
        }

        // Tool calls (supports parallel + streaming deltas)
        if (isset($delta["tool_calls"]) && is_array($delta["tool_calls"])) {
            foreach ($delta["tool_calls"] as $tc_delta) {
                $idx = $tc_delta["index"] ?? 0;
                if (!isset($accumulated_tool_calls[$idx])) {
                    $accumulated_tool_calls[$idx] = [
                        "id" => "",
                        "type" => "function",
                        "function" => ["name" => "", "arguments" => ""]
                    ];
                }
                $tc = &$accumulated_tool_calls[$idx];
                if (isset($tc_delta["id"])) {
                    $tc["id"] .= $tc_delta["id"];
                }
                if (isset($tc_delta["function"])) {
                    $f = $tc_delta["function"];
                    if (isset($f["name"])) {
                        $tc["function"]["name"] .= $f["name"];
                    }
                    if (isset($f["arguments"])) {
                        $tc["function"]["arguments"] .= $f["arguments"];
                    }
                }
            }
        }

        // Legacy function_call support
        if (isset($delta["function_call"])) {
            $fc = $delta["function_call"];
            if (isset($fc["name"])) {
                $accumulated_function_call["name"] .= $fc["name"];
            }
            if (isset($fc["arguments"])) {
                $accumulated_function_call["arguments"] .= $fc["arguments"];
            }
        }
    }

    // Clean weird characters
    $accumulated_content = trim(str_replace(["ð", "�"], "", $accumulated_content));

    $msg = ["role" => "assistant", "content" => $accumulated_content ?: null];

    if (!empty($accumulated_tool_calls)) {
        $clean = [];
        foreach ($accumulated_tool_calls as $tc) {
            if (!empty($tc["function"]["name"])) {
                $clean[] = [
                    "id" => $tc["id"] ?: null,
                    "type" => "function",
                    "function" => [
                        "name" => $tc["function"]["name"],
                        "arguments" => $tc["function"]["arguments"]
                    ]
                ];
            }
        }
        if (!empty($clean)) {
            $msg["tool_calls"] = $clean;
        }
    } elseif (!empty($accumulated_function_call["name"])) {
        $msg["function_call"] = $accumulated_function_call;
    }

    return $msg;
}

// ====================== HANDLE POST ======================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Clear chat
    if (isset($_POST["clear"])) {
        unset($_SESSION["history"]);
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    // New user message
    if (!empty($_POST["user_input"])) {
        $user = trim($_POST["user_input"]);
        if ($user !== "") {
            $history[] = ["role" => "user", "content" => $user];

            $step = 0;
            $max_steps = 10;

            while ($step < $max_steps) {
                $step++;
                $assistant_msg = stream_model($history);
                $history[] = $assistant_msg;

                $tool_calls = $assistant_msg["tool_calls"] ?? [];
                $function_call = $assistant_msg["function_call"] ?? null;

                if (empty($tool_calls) && !$function_call) {
                    break;
                }

                // Execute tools (supports parallel tool_calls)
                if (!empty($tool_calls)) {
                    foreach ($tool_calls as $tc) {
                        $fname = $tc["function"]["name"];
                        $args_str = $tc["function"]["arguments"] ?? "{}";
                        $args = json_decode($args_str, true) ?: [];
                        $result = $TOOL_FUNCTIONS[$fname]($args) ?? ["error" => "Unknown tool"];
                        $history[] = [
                            "role" => "tool",
                            "tool_call_id" => $tc["id"] ?? "call_1",
                            "name" => $fname,
                            "content" => json_encode($result, JSON_UNESCAPED_SLASHES)
                        ];
                    }
                } elseif ($function_call) {
                    $fname = $function_call["name"];
                    $args_str = $function_call["arguments"] ?? "{}";
                    $args = json_decode($args_str, true) ?: [];
                    $result = $TOOL_FUNCTIONS[$fname]($args) ?? ["error" => "Unknown tool"];
                    $history[] = [
                        "role" => "tool",
                        "name" => $fname,
                        "content" => json_encode($result, JSON_UNESCAPED_SLASHES)
                    ];
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP AI Agent • Llama + Tools</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; background: #f8f9fa; }
        h1 { text-align: center; color: #333; }
        #chat { 
            border: 1px solid #ddd; 
            background: white; 
            border-radius: 12px; 
            padding: 20px; 
            height: 65vh; 
            overflow-y: auto; 
            margin-bottom: 20px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .message { 
            margin-bottom: 18px; 
            padding: 14px 18px; 
            border-radius: 12px; 
            line-height: 1.5;
        }
        .user { background: #e3f2fd; text-align: right; margin-left: 60px; }
        .assistant { background: #f1f8e9; margin-right: 60px; }
        .tool { 
            background: #fff3e0; 
            font-family: ui-monospace, monospace; 
            font-size: 0.92em; 
            margin-right: 60px; 
        }
        textarea { 
            width: 100%; 
            height: 80px; 
            padding: 12px; 
            border: 1px solid #ccc; 
            border-radius: 8px; 
            font-size: 1rem;
            resize: vertical;
        }
        button { 
            padding: 12px 24px; 
            font-size: 1rem; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer;
        }
        .send-btn { background: #1976d2; color: white; }
        .clear-btn { background: #f44336; color: white; }
        img { max-width: 320px; border-radius: 8px; border: 1px solid #ddd; margin-top: 8px; }
        .footer { text-align: center; margin-top: 20px; color: #666; font-size: 0.9em; }
    </style>
</head>
<body>
    <h1>🦙 PHP AI Agent</h1>
    <p style="text-align:center;color:#555;">Local Llama server + tool calling (search_web + graphic_art)</p>

    <div id="chat">
        <?php foreach ($history as $msg): ?>
            <div class="message <?= htmlspecialchars($msg['role']) ?>">
                <strong><?= ucfirst(htmlspecialchars($msg['role'])) ?>:</strong><br>
                
                <?php if ($msg['role'] === 'assistant'): ?>
                    <?php if (!empty($msg['content'])): ?>
                        <?= nl2br(htmlspecialchars($msg['content'])) ?><br>
                    <?php endif; ?>

                    <?php if (!empty($msg['tool_calls'])): ?>
                        <strong style="color:#d32f2f;">🛠️ Tool Calls:</strong><br>
                        <ul style="margin:8px 0 0 20px;padding:0;">
                            <?php foreach ($msg['tool_calls'] as $tc): ?>
                                <li><code><?= htmlspecialchars($tc['function']['name']) ?>(<?= htmlspecialchars($tc['function']['arguments']) ?>)</code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($msg['function_call'])): ?>
                        <strong style="color:#d32f2f;">🛠️ Function Call:</strong> 
                        <?= htmlspecialchars($msg['function_call']['name']) ?>(<?= htmlspecialchars($msg['function_call']['arguments']) ?>)
                    <?php endif; ?>

                <?php elseif ($msg['role'] === 'tool'): ?>
                    🔧 <strong><?= htmlspecialchars($msg['name'] ?? 'tool') ?></strong> 
                    → <?= htmlspecialchars($msg['content']) ?>

                    <?php 
                    // Nice visual for graphic_art
                    if (($msg['name'] ?? '') === 'graphic_art') {
                        $res = json_decode($msg['content'], true);
                        if (!empty($res['image_url'])) {
                            echo '<br><img src="' . htmlspecialchars($res['image_url']) . '" alt="Generated art">';
                        }
                    }
                    ?>
                <?php else: // user or system ?>
                    <?= nl2br(htmlspecialchars($msg['content'] ?? '')) ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="post">
        <textarea name="user_input" placeholder="Ask anything... (e.g. 'Search latest AI news' or 'Generate graphic art of a cyberpunk cat')"></textarea>
        <br><br>
        <button type="submit" class="send-btn">Send Message</button>
    </form>

    <form method="post" style="margin-top:10px;">
        <input type="hidden" name="clear" value="1">
        <button type="submit" class="clear-btn">Clear Chat History</button>
    </form>

    <div class="footer">
        PHP conversion of the original Python agent • Full streaming parsing + parallel tools + session history • 
        Prompt input + full response displayed on the page
    </div>
</body>
</html>
