<?php
session_start();

// Functie om invoer te valideren
function valideerInvoer($data) {
    $data = trim($data ?? '');
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Functie om berichten te loggen
function logBericht($bericht) {
    $logbestand = 'contactformulier.log';
    $tijdstip = date('Y-m-d H:i:s');
    $logregel = "[$tijdstip] $bericht\n";
    file_put_contents($logbestand, $logregel, FILE_APPEND);
}

// Functie om IP-adressen te blokkeren
function isGeblokkeerd($ip) {
    $blokkeerbestand = 'geblokkeerde_ips.txt';
    if (!file_exists($blokkeerbestand)) {
        return false;
    }
    $geblokeerde_ips = file($blokkeerbestand, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($geblokeerde_ips as $index => $geblokkeerd_ip) {
        list($geblokkeerd_ip, $tijdstempel) = explode('|', $geblokkeerd_ip);
        if ($ip === $geblokkeerd_ip && (time() - $tijdstempel) < 86400) { // 24 uur in seconden
            return true;
        }
    }
    return false;
}

// Functie om IP-adressen te blokkeren
function blokkeerIP($ip) {
    $blokkeerbestand = 'geblokkeerde_ips.txt';
    $tijdstempel = time();
    file_put_contents("$blokkeerbestand", "$ip|$tijdstempel\n", FILE_APPEND);
}

// Functie om oude blokkades te verwijderen
function verwijderOudeBlokkades() {
    $blokkeerbestand = 'geblokkeerde_ips.txt';
    if (!file_exists($blokkeerbestand)) {
        return;
    }
    $geblokkeerde_ips = file($blokkeerbestand, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $nieuwe_geblokkeerde_ips = array();
    foreach ($geblokkeerde_ips as $geblokkeerd_ip) {
        list($ip, $tijdstempel) = explode('|', $geblokkeerd_ip);
        if ((time() - $tijdstempel) < 86400) { // 24 uur in seconden
            $nieuwe_geblokkeerde_ips[] = $geblokkeerd_ip;
        }
    }
    file_put_contents($blokkeerbestand, implode("\n", $nieuwe_geblokkeerde_ips) . "\n");
}

// Verwijder oude blokkades bij elke aanvraag
verwijderOudeBlokkades();

// Controleer of het IP-adres geblokkeerd is
$ip = $_SERVER['REMOTE_ADDR'];
if (isGeblokkeerd($ip)) {
    logBericht("Geblokkeerd IP-adres probeerde toegang te krijgen: $ip");
    echo "Je IP-adres is geblokkeerd vanwege verdacht gedrag. Neem contact op met de beheerder voor meer informatie.";
    exit;
}

// Rate limiting
$limiet = 5; // Maximaal aantal verzendingen per uur
$tijdsduur = 3600; // 1 uur in seconden
$tijdstempel_sleutel = 'laatste_verzending_' . $ip;

if (isset($_SESSION[$tijdstempel_sleutel]) && !empty($_SESSION[$tijdstempel_sleutel])) {
    $aantal_verzendingen = count($_SESSION[$tijdstempel_sleutel]);
    $eerste_verzending_tijd = isset($_SESSION[$tijdstempel_sleutel][0]) ? $_SESSION[$tijdstempel_sleutel][0] : time();

    // Verwijder verzendingen die ouder zijn dan 1 uur
    if (time() - $eerste_verzending_tijd > $tijdsduur) {
        array_shift($_SESSION[$tijdstempel_sleutel]);
        $aantal_verzendingen = count($_SESSION[$tijdstempel_sleutel]);
    }

    if ($aantal_verzendingen >= $limiet) {
        logBericht("Rate limit overschreden door IP: $ip");
        blokkeerIP($ip);
        echo "Je hebt het maximale aantal verzendingen per uur bereikt. Je IP-adres is nu geblokkeerd.";
        exit;
    }
} else {
    $_SESSION[$tijdstempel_sleutel] = array();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Check if required fields are present
    if (!isset($_POST['naam']) || !isset($_POST['email']) || !isset($_POST['bericht'])) {
        logBericht("Ontbrekende velden in formulier van IP: $ip");
        echo "Alle velden zijn verplicht.";
        exit;
    }

    // Valideer en sanitize de invoer
    $naam = isset($_POST['naam']) ? valideerInvoer($_POST['naam']) : '';
    $email = isset($_POST['email']) ? valideerInvoer($_POST['email']) : '';
    $onderwerp = isset($_POST['onderwerp']) ? valideerInvoer($_POST['onderwerp']) : '';
    $bericht = isset($_POST['bericht']) ? valideerInvoer($_POST['bericht']) : '';

    // Extra validatie voor e-mailadres
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logBericht("Ongeldig e-mailadres ingevoerd door IP: $ip");
        echo "Ongeldig e-mailadres.";
        exit;
    }

    // Extra validatie voor naam
    if (strlen($naam) < 2) {
        logBericht("Te korte naam ingevoerd door IP: $ip");
        echo "Naam moet minimaal 2 karakters lang zijn.";
        exit;
    }

    // Extra validatie voor bericht
    if (strlen($bericht) < 10) {
        logBericht("Te kort bericht ingevoerd door IP: $ip");
        echo "Bericht moet minimaal 10 karakters lang zijn.";
        exit;
    }

    // E-mail versturen
    $ontvanger = "farid@bouchdak.com"; // Vervang dit door je eigen e-mailadres
    $onderwerp = "Nieuw contactformulier bericht van $naam, over: $onderwerp";
    $inhoud = "Naam: $naam\nE-mail: $email\nBericht:\n$bericht";
    $headers = "From: $email";

    $apiKey = getenv('aap');
    $resend = Resend::client($apiKey);

    if (
    $resend->emails->send([
      'from' => 'onboarding@resend.dev',
      'to' => $ontvanger,
      'subject' => $onderwerp,
      'html' => $inhoud
    ])
    ) {
    // if (mail($ontvanger, $onderwerp, $inhoud, $headers)) {
        logBericht("Bericht succesvol verzonden door IP: $ip");

        // Voeg tijdstempel toe aan sessie voor rate limiting
        $_SESSION[$tijdstempel_sleutel][] = time();

        // Doorverwijzen naar de bedankt-pagina
        header("Location: ../bedankt.html");
        exit;
    } else {
        logBericht("Fout bij verzenden van bericht door IP: $ip");
        echo "Er is een fout opgetreden bij het verzenden van je bericht. Probeer het later opnieuw.";
    }
}
?>
