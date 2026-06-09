<?php

namespace AidantaChatbotConnector\Controllers;

use Plenty\Modules\Account\Contact\Contracts\ContactRepositoryContract;
use Plenty\Modules\Account\Contact\Models\Contact;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\Log\Loggable;

/**
 * Serverseitiger Handshake fuer die Aidanta-Chatbot-Einbindung.
 *
 * Ablauf (same-origin, mit plenty-Session-Cookie):
 *  1. Eingeloggten Kontakt aus der Session bestimmen (Status).
 *  2. Kundennummer + E-Mail laden und zum Aidanta-Kundenkontext aufbereiten.
 *  3. Serverseitig (lib/issueSession.php, Guzzle) ein Aidanta-Hybrid-Session-Token
 *     ausstellen lassen (API-Key) und dem Browser NUR dieses Token zurueckgeben.
 *
 * Der Browser self-asserted nie eine Identitaet -> kein Faelschungsrisiko.
 */
class HandshakeController extends Controller
{
    use Loggable;

    public function issue(
        AccountService $accountService,
        ContactRepositoryContract $contactRepository,
        ConfigRepository $config,
        LibraryCallContract $libCall,
        Response $response
    ) {
        $contactId = (int) $accountService->getAccountContactId();

        // Gast / nicht eingeloggt -> kein Kundenkontext.
        if ($contactId <= 0) {
            return $response->json(['session_token' => null]);
        }

        $apiBaseUrl = trim((string) $config->get('AidantaChatbotConnector.apiBaseUrl'));
        $widgetToken = trim((string) $config->get('AidantaChatbotConnector.widgetToken'));
        $apiKey = trim((string) $config->get('AidantaChatbotConnector.apiKey'));
        $ttlSeconds = (int) $config->get('AidantaChatbotConnector.ttlSeconds', 3600);

        if ($apiBaseUrl === '' || $widgetToken === '' || $apiKey === '') {
            $this->getLogger(__METHOD__)
                ->error('AidantaChatbotConnector: Plugin-Konfiguration unvollstaendig (Base-URL, Widget-Token oder API-Key fehlt).');

            return $response->json(['session_token' => null]);
        }

        $customer = $this->buildCustomerPayload($contactRepository, $contactId);

        if ($customer === null) {
            return $response->json(['session_token' => null]);
        }

        $result = $libCall->call('AidantaChatbotConnector::issueSession', [
            'apiBaseUrl' => $apiBaseUrl,
            'apiKey' => $apiKey,
            'widgetToken' => $widgetToken,
            'ttlSeconds' => $ttlSeconds > 0 ? $ttlSeconds : 3600,
            'customer' => $customer,
        ]);

        $sessionToken = is_array($result) ? ($result['session_token'] ?? null) : null;

        if ($sessionToken === null) {
            $this->getLogger(__METHOD__)
                ->warning('AidantaChatbotConnector: Kein Session-Token von Aidanta erhalten.', [
                    'result' => $result,
                ]);
        }

        return $response->json(['session_token' => $sessionToken]);
    }

    /**
     * Baut den Aidanta-Kundenkontext aus dem eingeloggten Plenty-Kontakt.
     *
     * identities[].provider='plentyone' bindet die personenbezogene Auskunft im
     * Bot strikt an die PlentyOne-Tools genau dieser Kennung.
     *
     * @return array<string, mixed>|null  null, wenn weder E-Mail noch Kundennummer vorhanden
     */
    private function buildCustomerPayload(ContactRepositoryContract $contactRepository, int $contactId): ?array
    {
        /** @var Contact $contact */
        $contact = $contactRepository->findContactById($contactId);

        $email = isset($contact->email) ? trim((string) $contact->email) : '';
        // $contact->number = Kundennummer. Hinweis: einzelne Importe befuellen
        // stattdessen $contact->externalId -> im Zweifel per dd($contact) pruefen.
        $customerNumber = isset($contact->number) ? trim((string) $contact->number) : '';

        if ($email === '' && $customerNumber === '') {
            return null;
        }

        $name = trim(((string) ($contact->firstName ?? '')).' '.((string) ($contact->lastName ?? '')));

        return [
            'name' => $name !== '' ? $name : null,
            'email' => $email !== '' ? $email : null,
            'identities' => [
                [
                    'provider' => 'plentyone',
                    'customer_number' => $customerNumber !== '' ? $customerNumber : null,
                    'email' => $email !== '' ? $email : null,
                ],
            ],
        ];
    }
}
