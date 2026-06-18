<?php

namespace AidantaChatbotConnector\Controllers;

use Plenty\Modules\Account\Contact\Contracts\ContactRepositoryContract;
use Plenty\Modules\Account\Contact\Models\Contact;
use Plenty\Modules\Frontend\Services\AccountService;
use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
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

    /**
     * Aidanta-Portal-URL (fix). Bewusst hartkodiert statt konfigurierbar:
     * fuer Haendler immer identisch, ein editierbares Feld waere nur ein Stolperstein.
     */
    public const API_BASE_URL = 'https://portal.aidanta.de';

    public function issue(
        AccountService $accountService,
        ContactRepositoryContract $contactRepository,
        ConfigRepository $config,
        LibraryCallContract $libCall,
        Request $request,
        Response $response
    ) {
        // Diagnose-Modus: ?debug=1 gibt KEINEN Session-Token aus, sondern eine
        // PII-freie Statusansicht (was der Handshake serverseitig sieht/berechnet).
        // Zum Debuggen im Live-Shop eingeloggt /aidanta-chatbot/handshake?debug=1 aufrufen.
        $debug = (string) $request->get('debug') === '1';

        $contactId = (int) $accountService->getAccountContactId();

        // Gast / nicht eingeloggt -> kein Kundenkontext.
        if ($contactId <= 0) {
            if ($debug) {
                return $response->json([
                    'debug' => true,
                    'logged_in' => false,
                    'contact_id' => 0,
                    'note' => 'Kein eingeloggter Kontakt in der plenty-Session (getAccountContactId() <= 0).',
                ]);
            }

            return $response->json(['session_token' => null]);
        }

        $widgetToken = trim((string) $config->get('AidantaChatbotConnector.widgetToken'));
        $apiKey = trim((string) $config->get('AidantaChatbotConnector.apiKey'));
        $ttlSeconds = (int) $config->get('AidantaChatbotConnector.ttlSeconds', 3600);

        if ($widgetToken === '' || $apiKey === '') {
            $this->getLogger(__METHOD__)
                ->error('AidantaChatbotConnector: Plugin-Konfiguration unvollstaendig (Widget-Token oder API-Key fehlt).');

            if ($debug) {
                return $response->json([
                    'debug' => true,
                    'logged_in' => true,
                    'contact_id' => $contactId,
                    'config_complete' => false,
                    'has_widget_token' => $widgetToken !== '',
                    'has_api_key' => $apiKey !== '',
                    'note' => 'Plugin-Konfiguration unvollstaendig (Widget-Token oder API-Key fehlt).',
                ]);
            }

            return $response->json(['session_token' => null]);
        }

        $customer = $this->buildCustomerPayload($contactRepository, $contactId);

        if ($debug) {
            $identity = (is_array($customer) && isset($customer['identities'][0]) && is_array($customer['identities'][0]))
                ? $customer['identities'][0]
                : [];

            return $response->json([
                'debug' => true,
                'logged_in' => true,
                'contact_id' => $contactId,
                'config_complete' => true,
                'ttl_seconds' => $ttlSeconds > 0 ? $ttlSeconds : 3600,
                'payload_built' => $customer !== null,
                'has_name' => is_array($customer) ? (($customer['name'] ?? null) !== null) : false,
                'has_email' => is_array($customer) ? (($customer['email'] ?? null) !== null) : false,
                'identity_provider' => isset($identity['provider']) ? $identity['provider'] : null,
                'identity_contact_id' => isset($identity['contact_id']) ? $identity['contact_id'] : null,
                'has_customer_number' => isset($identity['customer_number']) && $identity['customer_number'] !== null,
                'has_identity_email' => isset($identity['email']) && $identity['email'] !== null,
                'note' => 'Kundenkontext serverseitig berechnet. Im Debug-Modus wird KEIN Session-Token ausgestellt.',
            ]);
        }

        if ($customer === null) {
            return $response->json(['session_token' => null]);
        }

        $result = $libCall->call('AidantaChatbotConnector::issueSession', [
            'apiBaseUrl' => self::API_BASE_URL,
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
     * Bot strikt an die PlentyOne-Tools genau dieser Kennung. identities[].contact_id
     * ist die EINDEUTIGE Kontakt-ID — Aidanta scopt Bestell-Auskuenfte primaer darueber
     * (eine E-Mail kann an mehreren Kontakten haengen, die Kontakt-ID nie).
     *
     * @return array<string, mixed>|null  null, wenn der Kontakt nicht ladbar ist
     */
    private function buildCustomerPayload(ContactRepositoryContract $contactRepository, int $contactId): ?array
    {
        // Robustheit: findContactById darf den Handshake NIE mit einer uncaught Exception
        // abbrechen (sonst HTTP 500 statt sauberem session_token:null → Login schlaegt still
        // fehl). Erst mit ['options'] (E-Mail-Fallback) versuchen, bei Fehler ohne; im
        // Zweifel Gast.
        $contact = null;

        try {
            /** @var Contact|null $contact */
            $contact = $contactRepository->findContactById($contactId, ['options']);
        } catch (\Throwable $e) {
            try {
                /** @var Contact|null $contact */
                $contact = $contactRepository->findContactById($contactId);
            } catch (\Throwable $e2) {
                $this->getLogger(__METHOD__)
                    ->warning('AidantaChatbotConnector: Kontakt konnte nicht geladen werden.', [
                        'contactId' => $contactId,
                        'error' => $e2->getMessage(),
                    ]);

                return null;
            }
        }

        if ($contact === null) {
            return null;
        }

        $email = $this->resolveContactEmail($contact);
        $customerNumber = $this->resolveCustomerNumber($contact);
        $name = trim(((string) ($contact->firstName ?? '')).' '.((string) ($contact->lastName ?? '')));

        // Die plenty-Kontakt-ID ist die eindeutige, IMMER vorhandene Kennung — der
        // Payload wird darum immer gebaut (auch ohne Kundennummer/E-Mail).
        return [
            'name' => $name !== '' ? $name : null,
            'email' => $email !== '' ? $email : null,
            'identities' => [
                [
                    'provider' => 'plentyone',
                    'contact_id' => $contactId,
                    'customer_number' => $customerNumber !== '' ? $customerNumber : null,
                    'email' => $email !== '' ? $email : null,
                ],
            ],
        ];
    }

    /**
     * E-Mail des Kontakts. plenty befuellt $contact->email nicht zuverlaessig (die
     * Adresse liegt oft nur in den Kontakt-Options, typeId 2) -> dort als Fallback lesen.
     */
    private function resolveContactEmail(Contact $contact): string
    {
        $email = isset($contact->email) ? trim((string) $contact->email) : '';
        if ($email !== '') {
            return $email;
        }

        // array/Traversable explizit prüfen (manche Typ-Helper sind in der plenty-Sandbox gesperrt).
        $options = $contact->options ?? null;
        if (is_array($options) || $options instanceof \Traversable) {
            foreach ($options as $option) {
                $typeId = is_object($option) ? ($option->typeId ?? null) : (is_array($option) ? ($option['typeId'] ?? null) : null);
                if ((int) $typeId !== 2) {
                    continue;
                }
                foreach ($this->optionValues($option) as $value) {
                    if (strpos($value, '@') !== false) {
                        return $value;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Kundennummer; der String "null" und Leerwerte werden verworfen, Fallback auf
     * externalId (manche Importe befuellen die Kundennummer dort).
     */
    private function resolveCustomerNumber(Contact $contact): string
    {
        $number = isset($contact->number) ? trim((string) $contact->number) : '';

        if ($number === '' || strtolower($number) === 'null') {
            $number = isset($contact->externalId) ? trim((string) $contact->externalId) : '';
        }

        return strtolower($number) === 'null' ? '' : $number;
    }

    /**
     * Skalare Werte einer Kontakt-Option (Struktur variiert je plenty-Version:
     * ->value bzw. ->values[].value).
     *
     * @param  mixed  $option
     * @return array<int, string>
     */
    private function optionValues($option): array
    {
        $out = [];

        $direct = is_object($option) ? ($option->value ?? null) : (is_array($option) ? ($option['value'] ?? null) : null);
        if (is_string($direct) && trim($direct) !== '') {
            $out[] = trim($direct);
        }

        $values = is_object($option) ? ($option->values ?? null) : (is_array($option) ? ($option['values'] ?? null) : null);
        if (is_array($values) || $values instanceof \Traversable) {
            foreach ($values as $v) {
                $vv = is_object($v) ? ($v->value ?? null) : (is_array($v) ? ($v['value'] ?? null) : null);
                if (is_string($vv) && trim($vv) !== '') {
                    $out[] = trim($vv);
                }
            }
        }

        return $out;
    }
}
