<?php

use GuzzleHttp\Client;

/**
 * Stellt serverseitig eine Aidanta-Chatbot-Hybrid-Session aus.
 *
 * Laeuft in der plenty-lib-Sandbox: Parameter kommen ueber SdkRestApi::getParam(),
 * die Rueckgabe MUSS ein Array sein. Guzzle wird ueber plugin.json -> dependencies
 * bereitgestellt.
 *
 * @return array{session_token: string|null, error?: string, status?: int, message?: string}
 */

$apiBaseUrl = (string) SdkRestApi::getParam('apiBaseUrl');
$apiKey = (string) SdkRestApi::getParam('apiKey');
$widgetToken = (string) SdkRestApi::getParam('widgetToken');
$ttlSeconds = (int) SdkRestApi::getParam('ttlSeconds');
$customerParam = SdkRestApi::getParam('customer');
$customer = is_array($customerParam) ? $customerParam : [];
$visitorIp = trim((string) SdkRestApi::getParam('visitorIp'));

$endpoint = rtrim($apiBaseUrl, '/').'/api/v1/chatbot/sessions/issue';

$payload = [
    'widget_token' => $widgetToken,
    'ttl_seconds' => $ttlSeconds > 0 ? $ttlSeconds : 3600,
];

// Echte Besucher-IP IMMER mitgeben (auch fuer Gaeste!) — ohne sie speichert
// Aidanta die Egress-IP des plenty-Servers als "Besucher" und Spam-Sperren
// treffen den falschen. Nur valide IPs senden (invalide -> 422 -> Issue tot).
if ($visitorIp !== '' && filter_var($visitorIp, FILTER_VALIDATE_IP) !== false) {
    $payload['visitor'] = ['ip' => $visitorIp];
}

// Eingeloggter Kontakt: Kundenkontext + Visitor-Basisdaten mitgeben.
// Gast (customer leer): beides weglassen -> Aidanta stellt eine anonyme
// Gast-Session aus, die beim spaeteren Login hochgestuft wird.
if (!empty($customer)) {
    $visitor = isset($payload['visitor']) ? $payload['visitor'] : [];
    $visitor['name'] = isset($customer['name']) ? $customer['name'] : null;
    $visitor['email'] = isset($customer['email']) ? $customer['email'] : null;
    $payload['visitor'] = $visitor;
    $payload['customer'] = $customer;
}

try {
    $client = new Client(['timeout' => 5, 'connect_timeout' => 5]);

    $res = $client->request('POST', $endpoint, [
        'headers' => [
            'Authorization' => 'Bearer '.$apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'http_errors' => false,
    ]);

    $status = (int) $res->getStatusCode();
    $body = json_decode((string) $res->getBody(), true);

    if ($status === 201 && is_array($body) && ! empty($body['session_token'])) {
        return ['session_token' => $body['session_token']];
    }

    return ['session_token' => null, 'error' => 'issue_failed', 'status' => $status];
} catch (\Throwable $e) {
    return ['session_token' => null, 'error' => 'exception', 'message' => $e->getMessage()];
}
