<?php

namespace AidantaChatbotConnector\Containers;

use AidantaChatbotConnector\Controllers\HandshakeController;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Templates\Twig;

/**
 * Liefert das Bootstrap-<script> fuer einen Ceres-Container.
 *
 * Der Inhalt ist NICHT personalisiert (nur Base-URL + Widget-Token) und damit
 * cache-sicher. Die personalisierte Identitaet kommt erst spaeter ueber den
 * same-origin Handshake (/aidanta-chatbot/handshake).
 */
class WidgetBootstrapContainer
{
    public function call(Twig $twig, ConfigRepository $config): string
    {
        return $twig->render('AidantaChatbotConnector::content.WidgetBootstrap', [
            'apiBaseUrl' => HandshakeController::API_BASE_URL,
            'widgetToken' => trim((string) $config->get('AidantaChatbotConnector.widgetToken')),
        ]);
    }
}
