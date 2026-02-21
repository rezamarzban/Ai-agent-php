<?php
// ================================================
// api.php   (FIXED - Clear chat now works perfectly)
// ================================================
session_start();

require_once 'config.php';
require_once 'search_web_tool.php';
require_once 'graphic_art_tool.php';

global $TOOL_SCHEMAS;
$TOOLS_SCHEMAS = $TOOL_SCHEMAS ?? [];

// Improved smart system prompt
if (!isset($_SESSION['history'])) {
    $_SESSION['history'] = [
        [
            "role" => "system",
            "content" => "You are a helpful, friendly AI assistant.

- For greetings, introductions, self-explanation, or general knowledge questions: answer directly from your knowledge. Do NOT call any tools.
- Use the 'search_web' tool ONLY when the user explicitly asks to search the web or needs current/external information.
- Use the 'graphic_art' tool ONLY when the user asks to generate graphic art or an image.
- Never call tools for normal conversation.
- When you decide to use a tool, output ONLY the tool_calls in the correct format - no extra text.
- After receiving tool results, give a clear final answer based on them."
        ]
    ];
}

// Fallback parser
function extract_tool_calls_from_text($content) {
    if (empty($content)) return null;
    $calls = [];
    if (preg_match_all('/\{\s*"name"\s*:\s*"([^"]+)"[^}]*"arguments"\s*:\s*(\{.*?\})\s*\}/s', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $name = trim($match[1]);
            $args_json = trim($match[2]);
            $args = json_decode($args_json, true) ?: [];
            $calls[] = [
                "id" => "call_" . uniqid(),
                "type" => "function",
                "function" => [
                    "name" => $name,
                    "arguments" => json_encode($args)
                ]
            ];
        }
    }
    return $calls ?: null;
}

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
        "max_tokens" => 4096
    ];

    $accumulated_content = "";
    $accumulated_tool_calls = [];
    $accumulated_function_call = ["name" => "", "arguments" => ""];

    $ch = curl_init(LLM_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        AUTH_HEADER
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, TIMEOUT);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$accumulated_content, &$accumulated_tool_calls, &$accumulated_function_call) {
        static $buffer = '';
        $buffer .= $data;

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            $line = trim($line);

            if (empty($line) || !str_starts_with($line, "data: ")) continue;
            $data_str = trim(substr($line, 6));
            if ($data_str === "[DONE]") continue;

            $chunk = json_decode($data_str, true);
            if (!$chunk || !isset($chunk["choices"][0]["delta"])) continue;

            $delta = $chunk["choices"][0]["delta"];

            if (isset($delta["content"]) && $delta["content"] !== null) {
                $accumulated_content .= $delta["content"];
            }

            if (isset($delta["tool_calls"]) && is_array($delta["tool_calls"])) {
                foreach ($delta["tool_calls"] as $tc_delta) {
                    $idx = $tc_delta["index"] ?? 0;
                    if (!isset($accumulated_tool_calls[$idx])) {
                        $accumulated_tool_calls[$idx] = ["id" => "", "type" => "function", "function" => ["name" => "", "arguments" => ""]];
                    }
                    $tc = &$accumulated_tool_calls[$idx];
                    if (isset($tc_delta["id"])) $tc["id"] .= $tc_delta["id"];
                    if (isset($tc_delta["function"])) {
                        $f = $tc_delta["function"];
                        if (isset($f["name"])) $tc["function"]["name"] .= $f["name"];
                        if (isset($f["arguments"])) $tc["function"]["arguments"] .= $f["arguments"];
                    }
                }
            }

            if (isset($delta["function_call"])) {
                $fc = $delta["function_call"];
                if (isset($fc["name"])) $accumulated_function_call["name"] .= $fc["name"];
                if (isset($fc["arguments"])) $accumulated_function_call["arguments"] .= $fc["arguments"];
            }
        }
        return strlen($data);
    });

    $success = false;
    for ($i = 0; $i < MAX_RETRIES; $i++) {
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 200 && $code < 300) { $success = true; break; }
        if ($i < MAX_RETRIES - 1) sleep(pow(2, $i) * 1.5);
    }
    curl_close($ch);

    if (!$success) {
        return ["role" => "assistant", "content" => "[Connection error]"];
    }

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
        if (!empty($clean)) $msg["tool_calls"] = $clean;
    } elseif (!empty($accumulated_function_call["name"])) {
        $msg["function_call"] = $accumulated_function_call;
    }

    return $msg;
}

// ====================== HTTP Handler ======================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode($_SESSION['history'] ?? []);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    // ← CLEAR CHECK FIRST (this was the bug)
    if (!empty($input['clear'])) {
        unset($_SESSION['history']);
        header('Content-Type: application/json');
        echo json_encode(["status" => "cleared"]);
        exit;
    }

    $user = trim($input['message'] ?? '');

    if (empty($user)) {
        http_response_code(400);
        echo json_encode(["error" => "Empty message"]);
        exit;
    }

    $_SESSION['history'][] = ["role" => "user", "content" => $user];

    $step = 0;
    $max_steps = 8;

    while ($step < $max_steps) {
        $step++;

        $assistant_msg = stream_model($_SESSION['history']);

        $_SESSION['history'][] = $assistant_msg;

        $tool_calls = $assistant_msg['tool_calls'] ?? [];
        $function_call = $assistant_msg['function_call'] ?? null;

        if (empty($tool_calls) && !empty($assistant_msg['content'])) {
            $extracted = extract_tool_calls_from_text($assistant_msg['content']);
            if ($extracted) {
                $tool_calls = $extracted;
                $assistant_msg['tool_calls'] = $tool_calls;
                $assistant_msg['content'] = null;
                $_SESSION['history'][array_key_last($_SESSION['history'])] = $assistant_msg;
            }
        }

        if (empty($tool_calls) && !$function_call) {
            break;
        }

        if (!empty($tool_calls)) {
            foreach ($tool_calls as $tc) {
                $fname = $tc['function']['name'];
                $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
                $result = $TOOL_FUNCTIONS[$fname]($args) ?? ["error" => "Unknown tool"];

                $tool_msg = [
                    "role" => "tool",
                    "tool_call_id" => $tc['id'] ?? "call_1",
                    "name" => $fname,
                    "content" => json_encode($result, JSON_UNESCAPED_SLASHES)
                ];
                $_SESSION['history'][] = $tool_msg;
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode($_SESSION['history']);
    exit;
}
?>
