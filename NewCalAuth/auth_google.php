<?php
/**
 * Standalone-Skript zur Google Kalender Authentifizierung
 * 
 * @author       Sonne & Cie. GmbH & Co. KG
 * @copyright    Copyright (c) 2026 
 */

require __DIR__ . '/../google-api-php-client/vendor/autoload.php';

use Google\Client;
use Google\Service\Calendar;

mb_internal_encoding("UTF-8");

echo "=========================================================================\n";
echo " Google Kalender - Autorisierungs-Assistent\n";
echo "=========================================================================\n\n";

$credentialsPath = __DIR__ . '/credentials.json';
if (!file_exists($credentialsPath)) {
    die("Fehler: 'credentials.json' wurde nicht gefunden.\n");
}

$client = new Client();
$client->setApplicationName('Wohnungsbesichtigung Automatisierung');
$client->setScopes(Calendar::CALENDAR_EVENTS);
$client->setAuthConfig($credentialsPath);
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

$authUrl = $client->createAuthUrl();

echo "1. Der Autorisierungslink wird im Browser geoeffnet...\n";
echo "   Falls der Browser nicht automatisch startet, oeffnen Sie folgenden Link:\n";
echo "   " . $authUrl . "\n\n";

if (PHP_OS_FAMILY === 'Windows') {
    $escapedUrl = str_replace(['^', '&'], ['^^', '^&'], $authUrl);
    pclose(popen("start \"\" \"{$escapedUrl}\"", "r"));
}

echo "2. Melden Sie sich in Google an und gewaehren Sie den Zugriff auf den Kalender.\n";
echo "3. Nach der Bestaetigung leitet Google auf eine 'http://localhost/?code=...'-Adresse weiter.\n";
echo "4. Kopieren Sie die gesamte URL aus der Adresszeile (oder den Code) und fuegen Sie sie hier ein.\n\n";

echo "Eingabe (URL oder Code): ";
$rawInput = trim(fgets(STDIN));

$rawInput = trim($rawInput, " \t\n\r\0\x0B\"'");
$authCode = $rawInput;

if (strpos($rawInput, 'http://') === 0 || strpos($rawInput, 'https://') === 0 || strpos($rawInput, '?') !== false) {
    $parts = parse_url($rawInput);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        if (!empty($query['code'])) {
            $authCode = trim($query['code']);
        }
    }
} elseif (preg_match('/code=([^&]+)/', $rawInput, $m)) {
    $authCode = trim(urldecode($m[1]));
}

if (empty($authCode)) {
    die("\nFehler: Kein Autorisierungscode angegeben.\n");
}

try {
    $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
    if (is_array($accessToken) && isset($accessToken['error'])) {
        $errMsg = $accessToken['error_description'] ?? $accessToken['error'];
        die("\nFehler bei der Authentifizierung: {$errMsg}\n");
    }
    
    $tokenPath = __DIR__ . '/token.json';
    file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    
    // Schnelltest: Kalender abfragen
    $service = new Calendar($client);
    $cal = $service->calendars->get('primary');
    
    echo "\n-------------------------------------------------------------------------\n";
    echo "ERFOLG! Google Kalender erfolgreich verbunden: " . $cal->getSummary() . "\n";
    echo "Das neue Token wurde in 'token.json' gespeichert.\n";
    echo "-------------------------------------------------------------------------\n";
} catch (Exception $e) {
    echo "\nFehler: " . $e->getMessage() . "\n";
}
