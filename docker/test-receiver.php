<?php

/*
 * Throwaway webhook receiver for verifying deliveries locally. Not part of the
 * platform; nothing in app/ references it. See README.md.
 *
 *   /ok...        -> 200
 *   /fail...      -> 500
 *   /status-NNN   -> that status code
 *   /slow...      -> sleeps past the HTTP timeout
 *
 * Requests are appended to /tmp/received.log inside the container.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');

$line = sprintf(
    "[%s] path=%s delivery=%s event=%s attempt=%s body=%s\n",
    date('H:i:s'),
    $path,
    $_SERVER['HTTP_X_WEBHOOK_DELIVERY_ID'] ?? '-',
    $_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? '-',
    $_SERVER['HTTP_X_WEBHOOK_ATTEMPT'] ?? '-',
    $body
);
file_put_contents('/tmp/received.log', $line, FILE_APPEND);

if (preg_match('#^/status-(\d{3})#', $path, $m)) {
    http_response_code((int) $m[1]);
    echo 'status '.$m[1];

    return;
}

if (str_starts_with($path, '/fail')) {
    http_response_code(500);
    echo 'boom';

    return;
}

if (str_starts_with($path, '/slow')) {
    sleep(30);
}

http_response_code(200);
echo 'ok';
