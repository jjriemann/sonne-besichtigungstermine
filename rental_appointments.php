<?php

/**
 * Erstellt eMail Einladungen fuer Besichtigungstermine
 *
 * @author       Sonne & Cie. GmbH & Co. KG
 * @copyright    Copyright (c) 2026 
 *
 */

// --- GOOGLE API CLIENT AUTOLOAD ---
require __DIR__ . '/../google-api-php-client/vendor/autoload.php'; 
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;

// Stelle sicher, dass die Konsole/Skript UTF-8 verwendet
mb_internal_encoding("UTF-8");
date_default_timezone_set('Europe/Berlin');
ini_set('com.typelib_char_set', 'UTF-8');

// --- KONFIGURATION AUS CONFIG.JSON LADEN ---
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    die("Fehler: Die Konfigurationsdatei 'config.json' existiert nicht. Bitte ueber 'run_renapp.bat' starten.\n");
}

$rawJson = file_get_contents($configFile);
// UTF-8 BOM (\xEF\xBB\xBF) entfernen, falls vorhanden
$rawJson = preg_replace('/^\xEF\xBB\xBF/', '', $rawJson);

$configData = json_decode($rawJson, true);
if (!$configData) {
    die("Fehler: Die Konfigurationsdatei 'config.json' konnte nicht verarbeitet werden (JSON-Fehler: " . json_last_error_msg() . ").\n");
}

// 1. Ordnerpfad verarbeiten (String in Array umwandeln)
$rawOrdner = $configData['ordnerPfad'] ?? '';
if (is_array($rawOrdner)) {
    $ordnerPfad = $rawOrdner;
} else {
    $normalizedOrdner = str_replace('/', '\\', trim($rawOrdner));
    $ordnerPfad = array_values(array_filter(array_map('trim', explode('\\', $normalizedOrdner)), 'strlen'));
}

if (empty($ordnerPfad)) {
    die("Fehler: Es wurde kein gueltiger Outlook-Ordnerpfad angegeben.\n");
}

// 2. Datum & Uhrzeiten festlegen
$datumIso = trim($configData['datumIso'] ?? date('Y-m-d'));

$rawTime = trim($configData['startUhrzeit'] ?? '10:00');
if (preg_match('/(\d{1,2})[:\.](\d{2})/', $rawTime, $tm)) {
    $startUhrzeit = sprintf('%02d:%02d', $tm[1], $tm[2]);
} else {
    $startUhrzeit = '10:00';
}

if (!empty($configData['besichtigungstag'])) {
    $besichtigungstag = $configData['besichtigungstag'];
} else {
    $dt = new DateTime($datumIso);
    $fmt = new IntlDateFormatter('de_DE', IntlDateFormatter::FULL, IntlDateFormatter::NONE);
    $fmt->setPattern("EEEE, 'den' d. MMMM yyyy");
    $besichtigungstag = $fmt->format($dt);
}

define('BESICHTIGUNGSTAG', $besichtigungstag);
define('START_UHRZEIT', $startUhrzeit);
define('INTERVALL_MINUTEN', max(1, intval($configData['intervallMinuten'] ?? 5)));
define('SOFORT_SENDEN', !empty($configData['sofortSenden']));

// 3. Anhaeenge festlegen
$rawAnhaenge = [];
if (!empty($configData['exposeePfad'])) {
    $rawAnhaenge[] = trim($configData['exposeePfad']);
}
if (!empty($configData['selbstauskunftPfad'])) {
    $rawAnhaenge[] = trim($configData['selbstauskunftPfad']);
}

/**
 * Konvertiert Strings sicher in UTF-8
 */
function toUtf8(?string $str): string {
    if ($str === null || $str === '') {
        return '';
    }
    if (!mb_check_encoding($str, 'UTF-8')) {
        return mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
    }
    return $str;
}

/**
 * Extrahiert den echten Namen des Interessenten (z.B. aus Immowelt / Immonet / Direct Mail)
 */
function extractApplicantName(string $senderName, string $senderEmail, string $subject, string $body): string {
    $senderName  = toUtf8($senderName);
    $senderEmail = toUtf8($senderEmail);
    $subject     = toUtf8($subject);
    $body        = toUtf8($body);
    
    // Pruefen, ob der Absendername generisch ist (Portal/System)
    $isGeneric = preg_match('/(?:immowelt|immonet|immobilienscout|immoscout|kontakt|anfrage|noreply|no-reply|portal)/i', $senderName . ' ' . $senderEmail);
    if (!$isGeneric && !empty(trim($senderName))) {
        return trim($senderName);
    }
    
    // Pattern 1: Immowelt Betreff ('Ref.-Nr. 699: Herr Mehdi Bamansor wuencht Kontakt')
    if (preg_match('/Ref\.-Nr\.[^:]+:\s*(?:Herr|Frau|Herrn)?\s*([A-ZÄÖÜa-zäöüß\-\s]{3,50}?)\s*(?:wünscht|wuenscht|w.nscht)\s+Kontakt/iu', $subject, $m)) {
        return trim(preg_replace('/^(?:Herr|Frau|Herrn)\s+/iu', '', $m[1]));
    }
    
    // Pattern 2: Immonet / Generischer Betreff ('Anfrage von Herr Max Mustermann...')
    if (preg_match('/(?:Anfrage|Kontaktanfrage)[^:]*(?:von|für)\s+(?:Herr|Frau|Herrn)?\s*([A-ZÄÖÜa-zäöüß\-\s]{3,50})/iu', $subject, $m)) {
        $res = trim(preg_replace('/^(?:Herr|Frau|Herrn)\s+/iu', '', $m[1]));
        if (preg_match('/^[A-ZÄÖÜa-zäöüß\-\s]{3,50}$/u', $res) && !preg_match('/(?:Immobilie|Objekt|Wohnung)/i', $res)) {
            return $res;
        }
    }
    
    // Pattern 3: Immowelt Body ('Herr Mehdi Bamansor hat am 23.12.2024...')
    if (preg_match('/(?:Herr|Frau|Herrn)\s+([A-ZÄÖÜa-zäöüß\-\s]{3,50}?)\s+hat\s+am\s+\d{2}\.\d{2}\.\d{4}/iu', $body, $m)) {
        return trim($m[1]);
    }
    
    // Pattern 4: ImmoScout24 Body ('Vorname: ... \n Nachname: ...')
    if (preg_match('/Vorname\s*:\s*([^\r\n]+)\s*\r?\n\s*Nachname\s*:\s*([^\r\n]+)/iu', $body, $m)) {
        return trim($m[1] . ' ' . $m[2]);
    }
    
    // Pattern 5: Body Label ('Name: ...', 'Interessent: ...')
    if (preg_match('/(?:^|\n)\s*(?:Name|Absender|Interessent|Kontaktperson)\s*:\s*(?:Herr|Frau|Herrn)?\s*([A-ZÄÖÜa-zäöüß\-\s]{3,50})/iu', $body, $m)) {
        return trim(preg_replace('/^(?:Herr|Frau|Herrn)\s+/iu', '', $m[1]));
    }
    
    return !empty(trim($senderName)) ? trim($senderName) : "Interessent";
}

/**
 * Extrahiert eine Telefonnummer (Mobil / Festnetz) aus dem Mail-Text
 */
function extractPhoneNumber(string $body): string {
    $body = toUtf8($body);
    
    // Pattern 1: Expliziter Tel Link (z.B. tel://015167147165 oder tel:%2F%2F015167147165)
    if (preg_match('/tel:(?:%2F%2F|\/\/)?([0-9\+\s\-\(\)]{6,25})/i', $body, $m)) {
        $num = trim(urldecode($m[1]));
        if (strlen(preg_replace('/[^0-9\+]/', '', $num)) >= 6) {
            return $num;
        }
    }
    
    // Pattern 2: Beschriftung 'Telefon:', 'Mobil:', 'Handy:', 'Tel:' gefolgt von Nummer
    if (preg_match('/(?:Telefon|Tel|Mobil|Handy|Rufnummer|Phone|Mobile)\s*:\s*\n?\s*([0-9\+\s\/\-\(\)]{6,25})/i', $body, $m)) {
        $num = trim($m[1]);
        if (strlen(preg_replace('/[^0-9\+]/', '', $num)) >= 6) {
            return $num;
        }
    }
    
    // Pattern 3: Generische deutsche Telefonnummer (+49... oder 0151... oder 040...)
    if (preg_match('/(?:\+49|0)\s*[1-9]\d{1,4}\s*[\/\-\s]?\s*\d{3,8}[\s\-\/]?\d*/i', $body, $m)) {
        $num = trim($m[0]);
        $digits = preg_replace('/[^0-9]/', '', $num);
        if (strlen($digits) >= 7 && strlen($digits) <= 15) {
            return $num;
        }
    }
    
    return '';
}

/**
 * Hilfsfunktion zum Vorbereiten von Dateipfaden fuer Outlook COM (Umlaut-Konvertierung UTF-8 -> Windows-1252)
 */
function getOutlookFilePath(string $path): string {
    $path = trim($path);
    if (empty($path)) return '';
    
    $decoded = utf8_decode($path);
    if ($decoded !== $path && file_exists($decoded)) {
        return $decoded;
    }
    
    if (file_exists($path)) {
        return $path;
    }
    
    return $decoded;
}

// Vorab pruefen & Pfade fuer Outlook vorbereiten
$outlookAnhaenge = [];
foreach ($rawAnhaenge as $dateipfad) {
    if (empty($dateipfad)) continue;
    $preparedPath = getOutlookFilePath($dateipfad);
    if (!file_exists($preparedPath) && !file_exists($dateipfad)) {
        die("Fehler: Die Datei '{$dateipfad}' wurde nicht gefunden. Bitte Pfad in der Maske pruefen.\n");
    }
    $outlookAnhaenge[] = $preparedPath;
}

/**
 * Extrahiert den Autorisierungscode aus einer vom Benutzer eingegebenen Zeichenkette oder Redirect-URL
 */
function parseAuthCode(string $input): string {
    $input = trim($input);
    if (str_starts_with($input, 'http') || str_contains($input, 'code=')) {
        $parsed = parse_url($input);
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            if (!empty($queryParams['code'])) {
                return trim($queryParams['code']);
            }
        }
        if (preg_match('/code=([^&]+)/', $input, $matches)) {
            return urldecode(trim($matches[1]));
        }
    }
    return $input;
}

/**
 * Oeffnet eine URL im Standard-Webbrowser des Betriebssystems
 */
function openUrlInBrowser(string $url): void {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen('start "" "' . str_replace('"', '', $url) . '"', 'r'));
    } elseif (PHP_OS === 'Darwin') {
        pclose(popen('open "' . str_replace('"', '', $url) . '"', 'r'));
    } else {
        pclose(popen('xdg-open "' . str_replace('"', '', $url) . '"', 'r'));
    }
}

// --- GOOGLE CALENDAR CLIENT INITIALISIERUNG ---
function getGoogleCalendarService(): Calendar {
    $credentialsPath = __DIR__ . '/credentials.json';
    if (!file_exists($credentialsPath)) {
        throw new Exception("Fehler: 'credentials.json' nicht gefunden.");
    }

    $client = new Client();
    $client->setApplicationName('Wohnungsbesichtigung Automatisierung');
    $client->setScopes(Calendar::CALENDAR_EVENTS);
    $client->setAuthConfig($credentialsPath);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setIncludeGrantedScopes(true);

    $tokenPath = __DIR__ . '/token.json';
    $accessToken = null;
    $refreshToken = null;

    if (file_exists($tokenPath)) {
        $tokenData = json_decode(file_get_contents($tokenPath), true);
        if (is_array($tokenData) && empty($tokenData['error'])) {
            $accessToken = $tokenData;
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $client->setAccessToken($accessToken);
        }
    }

    $tokenNeedsRefresh = !$accessToken || $client->isAccessTokenExpired();

    if ($tokenNeedsRefresh) {
        $refreshSuccess = false;

        // Versuche Token mit Refresh-Token zu erneuern falls vorhanden
        $currentRefreshToken = $client->getRefreshToken() ?: $refreshToken;
        if (!empty($currentRefreshToken)) {
            try {
                $newToken = $client->fetchAccessTokenWithRefreshToken($currentRefreshToken);
                if (is_array($newToken) && empty($newToken['error'])) {
                    // Refresh-Token beibehalten, falls Google in der Antwort keinen neuen mitsendet
                    if (empty($newToken['refresh_token']) && !empty($currentRefreshToken)) {
                        $newToken['refresh_token'] = $currentRefreshToken;
                    }
                    $client->setAccessToken($newToken);
                    file_put_contents($tokenPath, json_encode($client->getAccessToken(), JSON_PRETTY_PRINT));
                    $refreshSuccess = true;
                } else {
                    $errorDesc = $newToken['error_description'] ?? ($newToken['error'] ?? 'unbekannt');
                    echo "Hinweis: Google-Zugriffstoken ist abgelaufen oder ungueltig ({$errorDesc}).\n";
                    echo "Erneute Google-Anmeldung erforderlich...\n\n";
                }
            } catch (Exception $e) {
                echo "Hinweis: Token-Aktualisierung fehlgeschlagen ({$e->getMessage()}).\n";
                echo "Erneute Google-Anmeldung erforderlich...\n\n";
            }
        }

        // Falls kein Refresh-Token vorhanden war oder Refresh fehlschlug (z.B. invalid_grant)
        if (!$refreshSuccess) {
            if (file_exists($tokenPath)) {
                @unlink($tokenPath);
            }

            $authUrl = $client->createAuthUrl();
            echo "========================================================================\n";
            echo " GOOGLE KALENDER AUTORISIERUNG ERFORDERLICH\n";
            echo "========================================================================\n";
            echo "1. Der folgende Autorisierungs-Link wird im Browser geoeffnet:\n\n";
            echo "   " . $authUrl . "\n\n";
            echo "2. Melde dich bei Google an und bestaetige den Zugriff fuer den Kalender.\n";
            echo "3. Nach der Bestaetigung leitet Google auf localhost weiter.\n";
            echo "   Kopiere die vollstaendige URL aus der Adresszeile des Browsers\n";
            echo "   (oder den Bestaetigungscode) und fuege sie hier ein:\n";
            echo "========================================================================\n\n";

            openUrlInBrowser($authUrl);

            print "Code oder Weiterleitungs-URL eingeben: ";
            $authInput = trim(fgets(STDIN));
            $authCode = parseAuthCode($authInput);

            if (empty($authCode)) {
                throw new Exception("Kein Autorisierungscode eingegeben. Kalender-Verbindung abgebrochen.");
            }

            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            if (!empty($accessToken['error'])) {
                $err = $accessToken['error_description'] ?? $accessToken['error'];
                throw new Exception("Fehler bei der Google-Autorisierung: {$err}");
            }

            $client->setAccessToken($accessToken);
            file_put_contents($tokenPath, json_encode($client->getAccessToken(), JSON_PRETTY_PRINT));
            echo "Google Kalender erfolgreich autorisiert und Token gespeichert!\n\n";
        }
    }

    return new Calendar($client);
}

/**
 * Hilfsfunktion zum Suchen von Outlook-Unterordnern mit flexiblem Encoding-Vergleich (UTF-8 / Windows-1252)
 */
function getOutlookSubfolder($parentFolder, string $targetName) {
    $targetClean = trim($targetName);
    
    // 1. Direktes Auslesen versuchen
    try {
        $folder = $parentFolder->Folders->Item($targetClean);
        if ($folder) return $folder;
    } catch (Exception $e) {}

    try {
        $folder = $parentFolder->Folders->Item(utf8_decode($targetClean));
        if ($folder) return $folder;
    } catch (Exception $e) {}
    
    // 2. Durch Ordner iterieren und Encodings vergleichen
    foreach ($parentFolder->Folders as $folder) {
        $fName = $folder->Name;
        
        if ($fName === $targetClean) return $folder;
        if (utf8_decode($targetClean) === $fName) return $folder;
        if (utf8_encode($fName) === $targetClean) return $folder;
        if (mb_strtolower(mb_convert_encoding($fName, 'UTF-8', 'Windows-1252'), 'UTF-8') === mb_strtolower($targetClean, 'UTF-8')) return $folder;
    }
    
    // 3. Ausfuehrliche Fehlermeldung mit verfuegbaren Ordnern
    $available = [];
    foreach ($parentFolder->Folders as $f) {
        $available[] = $f->Name;
    }
    $availableStr = empty($available) ? 'keine Unterordner vorhanden' : implode(", ", $available);
    
    die("Fehler: Der Unterordner '{$targetName}' konnte in '{$parentFolder->Name}' nicht gefunden werden.\nVerfuegbare Ordner auf dieser Ebene: {$availableStr}\n");
}
    
// --- HAUPTABLAUF ---
try {
    // 1. Google Calendar vorbereiten
    $calendarService = getGoogleCalendarService();

    // 2. Verbindung zu Outlook herstellen
    $outlook   = new COM("Outlook.Application");
    $namespace = $outlook->GetNamespace("MAPI");
    
    // 3. Haupt-Posteingang oeffnen (Folder 6 = Standard-Posteingang)
    $aktuellerOrdner = $namespace->GetDefaultFolder(6);
    
    // Unterordner schrittweise ansteuern
    foreach ($ordnerPfad as $ebene) {
        $aktuellerOrdner = getOutlookSubfolder($aktuellerOrdner, $ebene);
    }
    
    echo "Ordner erfolgreich geladen: " . implode(" -> ", $ordnerPfad) . "\n\n";

    // 4. Mails im Zielordner abrufen
    $items = $aktuellerOrdner->Items;
    $items->Sort("[ReceivedTime]", false); // Aelteste Mails zuerst
    
    // Mails in ein festes PHP-Array umkopieren, um COM-Collection-Index-Verschiebungen zu vermeiden
    $mailArray = [];
    foreach ($items as $mailItem) {
        $mailArray[] = $mailItem;
    }
    
    $counter          = 0;
    $intervallMinuten = INTERVALL_MINUTEN;
    
    foreach ($mailArray as $mail) {
        $senderName = "Unbekannt";
        try {
            // Start & End DateTime fuer Google Calendar und Mail berechnen
            $startDt = new DateTime("{$datumIso} " . START_UHRZEIT . ":00", new DateTimeZone('Europe/Berlin'));
            if ($counter > 0) {
                $minutesToAdd = $counter * $intervallMinuten;
                $startDt->modify("+{$minutesToAdd} minutes");
            }
            
            $endDt = clone $startDt;
            $endDt->modify("+{$intervallMinuten} minutes");

            $uhrzeitStr = $startDt->format("H:i");
            $startIso   = $startDt->format(DateTime::ATOM);
            $endIso     = $endDt->format(DateTime::ATOM);
            
            $senderName  = toUtf8($mail->SenderName);
            $senderEmail = toUtf8($mail->SenderEmailAddress);
            $subject     = toUtf8($mail->Subject);
            $body        = toUtf8($mail->Body);
            
            // Echten Namen und Telefonnummer des Interessenten ermitteln
            $applicantName = extractApplicantName($senderName, $senderEmail, $subject, $body);
            $phone         = extractPhoneNumber($body);
            
            // Antwort-Mail erstellen (behaelt Betreff und Verlauf bei)
            $replyMail = $mail->Reply();
            
            // Mail-Text definieren mit echtem Namen des Interessenten
            $mailBody = "Hallo " . $applicantName . ",\n\n"
                      . "vielen Dank fuer Ihr Interesse an der Wohnung.\n\n"
                      . "Wir freuen uns, Ihnen folgenden Besichtigungstermin anbieten zu koennen:\n"
                      . "Tag: " . BESICHTIGUNGSTAG . "\n"
                      . "Uhrzeit: " . $uhrzeitStr . " Uhr\n\n"
                      . "Bitte antworten Sie kurz auf diese E-Mail, um den Termin zu bestaetigen.\n\n"
                      . "Mit freundlichen Gruessen,\n"
                      . "[Dein Name]\n\n";
            
            // Neuen Text vor die alte Nachricht setzen
            $replyMail->Body = $mailBody . $replyMail->Body;

            // Dokumente als Anhang hinzufuegen
            foreach ($outlookAnhaenge as $dateipfad) {
                if (!empty($dateipfad)) {
                    $replyMail->Attachments->Add($dateipfad);
                }
            }
            
            // Nachricht als gelesen markieren & speichern
            $mail->UnRead = false;
            $mail->Save();

            // 4. Termin in Google Kalender eintragen
            $eventSummary = toUtf8("Besichtigung: {$applicantName}" . (!empty($phone) ? " ({$phone})" : ""));
            $eventDesc    = toUtf8("Interessent: {$applicantName}\n"
                          . (!empty($phone) ? "Telefon: {$phone}\n" : "")
                          . "E-Mail: {$senderEmail}\n"
                          . "Absender: {$senderName} <{$senderEmail}>");

            $startEventDateTime = new EventDateTime();
            $startEventDateTime->setDateTime($startIso);
            $startEventDateTime->setTimeZone('Europe/Berlin');

            $endEventDateTime = new EventDateTime();
            $endEventDateTime->setDateTime($endIso);
            $endEventDateTime->setTimeZone('Europe/Berlin');

            $event = new Event();
            $event->setSummary($eventSummary);
            $event->setDescription($eventDesc);
            $event->setStart($startEventDateTime);
            $event->setEnd($endEventDateTime);

            $calStatus = "";
            try {
                $calendarService->events->insert('primary', $event);
                $calStatus = " [Kalender: OK]";
            } catch (Exception $calEx) {
                $calStatus = " [Kalender-Fehler: " . $calEx->getMessage() . "]";
            }
            
            $logPhoneStr = !empty($phone) ? ", Tel: {$phone}" : "";
            echo "Termin {$uhrzeitStr} Uhr zugewiesen an: {$applicantName} (E-Mail: {$senderEmail}{$logPhoneStr}){$calStatus}\n";
            
            // Senden oder Fenster zum Ueberpruefen oeffnen
            if (SOFORT_SENDEN) {
                $replyMail->Send();
            } else {
                $replyMail->Display(); // Oeffnet die Mail in Outlook
            }
            
            $counter++;
        } catch (Exception $mailEx) {
            echo "Fehler bei Verarbeitung ({$senderName}): " . $mailEx->getMessage() . "\n";
        }
    }
    
    if ($counter === 0) {
        echo "Keine ungelesenen E-Mails im Zielordner gefunden.\n";
    } else {
        echo "\nWorkflow erfolgreich ausgefuehrt! {$counter} Termin(e) vergeben.\n";
    }

} catch (Exception $e) {
    echo "Kritischer Fehler: " . $e->getMessage() . "\n";
}

exit();
?>
