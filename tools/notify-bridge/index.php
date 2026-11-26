<?php

declare(strict_types=1);

/**
 * Reference notification bridge — front controller.
 *
 * This file is a separate deployable from the booking application. It is NOT
 * part of the clinic web root and must never be served from it: copy this
 * directory to its own host, give it its own token, and point NOTIFY_HTTP_URL at
 * its /send path. See docs/operations/notification-bridge-contract.md.
 *
 * Environment:
 *   BRIDGE_TOKEN        shared secret; the application sends it as a bearer token
 *   BRIDGE_STATE_DIR    directory for de-duplication records (not web-accessible)
 *   BRIDGE_RECORD_FILE  file the recording gateway appends accepted messages to
 *   BRIDGE_GATEWAY      "recording" (the only gateway shipped here)
 *   BRIDGE_APP_ROOT     optional; a directory holding vendor/ and src/. Defaults
 *                       to this repository, so a copy of this directory run from
 *                       the repository works with no configuration.
 *
 * Run locally with:
 *   php -S 127.0.0.1:8099 tools/notify-bridge/index.php
 */

use App\Notifications\Bridge\NotificationBridge;
use App\Notifications\Bridge\RecordingGateway;

$appRoot = (string) getenv('BRIDGE_APP_ROOT');
if ($appRoot === '') {
    $appRoot = dirname(__DIR__, 2);
}

require rtrim($appRoot, '/\\') . '/vendor/autoload.php';

$token = (string) getenv('BRIDGE_TOKEN');
$stateDirectory = (string) getenv('BRIDGE_STATE_DIR');
$recordFile = (string) getenv('BRIDGE_RECORD_FILE');
$gatewayName = (string) getenv('BRIDGE_GATEWAY');

$respond = static function (int $status, string $json): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo $json;
};

if ($gatewayName !== 'recording') {
    $respond(503, '{"error":"no gateway is configured; this build ships only BRIDGE_GATEWAY=recording,'
        . ' which records messages and delivers nothing"}');
    return;
}

if ($recordFile === '') {
    $respond(503, '{"error":"BRIDGE_RECORD_FILE is not configured"}');
    return;
}

// getallheaders() is the reliable spelling in the built-in server; the $_SERVER
// fallback covers SAPIs that do not define it and deployments behind CGI, where
// the Authorization header is the one most often stripped without a word.
$headers = [];
if (function_exists('getallheaders')) {
    foreach ((array) getallheaders() as $name => $value) {
        $headers[(string) $name] = (string) $value;
    }
}
if (!isset($headers['Authorization']) && !isset($headers['authorization'])) {
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
            $headers[str_replace('_', '-', substr((string) $key, 5))] = $value;
        }
    }
}

try {
    $bridge = new NotificationBridge(new RecordingGateway($recordFile), $token, $stateDirectory);
    $result = $bridge->handle($headers, (string) file_get_contents('php://input'));
    $respond($result->status, $result->json());
} catch (Throwable $exception) {
    error_log('notify-bridge: ' . $exception->getMessage());
    $respond(500, '{"error":"bridge failed; see its error log"}');
}
