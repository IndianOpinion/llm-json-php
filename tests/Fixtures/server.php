<?php

/**
 * Router for PHP's built-in server, used by the transport integration tests.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$completion = static function (string $content): string {
    return (string) json_encode([
        'id' => 'chatcmpl-local',
        'model' => 'local-model',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ]);
};

switch ($path) {
    case '/ok/chat/completions':
        header('Content-Type: application/json');
        header('X-Request-Id: req_local_1');
        echo $completion('{"title":"Local","paragraphs":["It works."],"tags":[]}');
        break;
    case '/echo/chat/completions':
        header('Content-Type: application/json');
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        echo $completion((string) json_encode([
            'title' => (string) ($headers['User-Agent'] ?? ''),
            'paragraphs' => [(string) file_get_contents('php://input')],
            'tags' => [(string) ($headers['Authorization'] ?? '')],
        ]));
        break;
    case '/redirect/chat/completions':
        header('Location: https://example.org/elsewhere', true, 301);
        echo 'moved';
        break;
    case '/big/chat/completions':
        header('Content-Type: application/json');
        echo str_repeat('x', 2 * 1024 * 1024);
        break;
    case '/slow/chat/completions':
        sleep(3);
        echo $completion('{}');
        break;
    default:
        http_response_code(404);
        echo '{"error":{"message":"not found"}}';
}
