<?php
// ================================================
// config.php
// ================================================
define('LLM_API_URL',   'https://api.example.com/v1/chat/completions');   // ← CHANGE TO YOUR PROVIDER
define('LLM_API_KEY',   'sk-XXXXXXXXXXXXXXXXXXXXXXXX');                    // ← YOUR API KEY
define('MODEL',         'your-model-name-here');                           // ← e.g. llama-3.3-70b, mixtral-8x22b, etc.

define('TIMEOUT', 600);
define('MAX_RETRIES', 3);

// Optional: change if your provider uses different header name
define('AUTH_HEADER', 'Authorization: Bearer ' . LLM_API_KEY);
?>
