<?php

declare(strict_types=1);

/*
 * One "request" for NativeSessionTest in a fresh PHP process (session ini settings cannot change once a
 * session or output exists). Usage: session-worker.php <storage> <scenario-json>
 *   scenario: {"url": "...", "cookie": "<id>"|null, "server": {...}, "actions": [["set","k","v"], ["get","k"],
 *              ["remove","k"], ["regenerate"], ["invalidate"]]}
 * Prints one JSON line: effective ini values, results of "get", started flag, ID, Set-Cookie and
 * Cache-Control of the committed response, and any PHP warning or deprecation raised.
 */

use Nolviko\Core\Session\NativeSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $storage, $json] = $argv;
$scenario = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
$errors = [];
set_error_handler(static function (int $level, string $message) use (&$errors): bool {
    $errors[] = $level . ': ' . $message;

    return true;
});

$cookies = $scenario['cookie'] === null ? [] : [NativeSession::COOKIE => $scenario['cookie']];
$request = Request::create($scenario['url'], 'GET', [], $cookies, [], $scenario['server'] ?? []);
$session = new NativeSession($storage);
$session->begin($request);
$results = [];

foreach ($scenario['actions'] as $action) {
    match ($action[0]) {
        'set' => $session->set($action[1], $action[2]),
        'get' => $results[] = $session->get($action[1], 'default'),
        'remove' => $session->remove($action[1]),
        'regenerate' => $session->regenerate(),
        'invalidate' => $session->invalidate(),
    };
}

$started = $session->isStarted();
$id = session_id();
$ini = [];

foreach (array_keys(NativeSession::settings($request, '')) as $name) {
    $ini[$name] = ini_get($name);
}

$ini['session.sid_length'] = ini_get('session.sid_length');
$ini['session.sid_bits_per_character'] = ini_get('session.sid_bits_per_character');
$response = $session->commit(new Response('page'));

echo json_encode([
    'ini' => $ini,
    'results' => $results,
    'started' => $started,
    'id' => $started ? $id : null,
    'cookies' => $response->headers->all('set-cookie'),
    'cache_control' => $response->headers->get('Cache-Control'),
    'errors' => $errors,
], JSON_THROW_ON_ERROR), "\n";
