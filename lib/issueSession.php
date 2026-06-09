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
$customer = (array) SdkRestApi::getParam('customer');

$endpoint = rtrim($apiBaseUrl, '/').'/api/v1/chatbot/sessions/issue';

try {
    $client = new Client(['timeout' => 5, 'connect_timeout' => 5]);

    $res = $client->request('POST', $endpoint, [
        'headers' => [
            'Authorization' => 'Bearer '.$apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
        'json' => [
            'widget_token' => $widgetToken,
            'visitor' => [
                'name' => isset($customer['name']) ? $customer['name'] : null,
                'email' => isset($customer['email']) ? $customer['email'] : null,
            ],
            'customer' => $customer,
            'ttl_seconds' => $ttlSeconds > 0 ? $ttlSeconds : 3600,
        ],
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
