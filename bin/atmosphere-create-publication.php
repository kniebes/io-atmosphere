#!/usr/bin/env php
<?php declare(strict_types=1);

use Kniebes\IoAtmosphere\Client\AtProtoClient;
use Kniebes\IoAtmosphere\StandardSite\PublicationRecord;
use Kniebes\IoAtmosphere\StandardSite\StandardSitePublisher;

$autoloadCandidates = [
    __DIR__.'/../../../autoload.php',      // installiert unter vendor/kniebes/io-atmosphere
    __DIR__.'/../vendor/autoload.php',     // Standalone-Checkout des Packages
];
foreach ($autoloadCandidates as $autoloadFile) {
    if (is_file($autoloadFile)) {
        require $autoloadFile;
        break;
    }
}

if ($argc < 3) {
    print('Aufruf: atmosphere-create-publication.php <url> <name> [beschreibung] [record-key]'.PHP_EOL);
    print('Beispiel: atmosphere-create-publication.php https://kniebes.com "Markus Kniebes" "Journal und Fotoblog" self'.PHP_EOL);
    print(PHP_EOL);
    print('PDS-Zugang über die Umgebungsvariablen ATMOSPHERE_PDS_URL, ATMOSPHERE_IDENTIFIER'.PHP_EOL);
    print('und ATMOSPHERE_APP_PASSWORD; fehlende Werte werden interaktiv abgefragt.'.PHP_EOL);
    exit(1);
}

$url = $argv[1];
$name = $argv[2];
$description = $argv[3] ?? null;
$recordKey = $argv[4] ?? 'self';

function readConfigValue(string $environmentName, string $prompt, ?string $default = null): string
{
    $value = $_ENV[$environmentName] ?? getenv($environmentName);
    if (!empty($value)) {
        return $value;
    }

    print($prompt);
    $input = trim((string) fgets(STDIN));
    if ($input === '' && $default !== null) {
        return $default;
    }
    if ($input === '') {
        print('Abbruch: kein Wert angegeben.'.PHP_EOL);
        exit(1);
    }

    return $input;
}

$pdsUrl = readConfigValue(
    environmentName: 'ATMOSPHERE_PDS_URL',
    prompt: 'PDS-URL [https://bsky.social]: ',
    default: 'https://bsky.social'
);
$identifier = readConfigValue(
    environmentName: 'ATMOSPHERE_IDENTIFIER',
    prompt: 'Identifier (Handle oder E-Mail): '
);
$appPassword = readConfigValue(
    environmentName: 'ATMOSPHERE_APP_PASSWORD',
    prompt: 'App-Password: '
);

$client = new AtProtoClient(pdsUrl: $pdsUrl);
$session = $client->login(identifier: $identifier, appPassword: $appPassword);
print('Angemeldet als '.$session->handle.' ('.$session->did.')'.PHP_EOL);

$publicationUri = (new StandardSitePublisher(client: $client))->publishPublication(
    recordKey: $recordKey,
    publication: new PublicationRecord(url: $url, name: $name, description: $description)
);

print('Publication angelegt: '.$publicationUri.PHP_EOL.PHP_EOL);
print('Nächste Schritte:'.PHP_EOL);
print('1. Die AT-URI als ATMOSPHERE_PUBLICATION_URI in der Anwendung hinterlegen.'.PHP_EOL);
print('2. Im Blog-Frontend '.$url.'/.well-known/site.standard.publication anlegen, Inhalt (Plaintext): '.$publicationUri.PHP_EOL);
print('3. Auf Artikelseiten das link-Tag rel="site.standard.document" mit der AT-URI des jeweiligen Documents ausgeben.'.PHP_EOL);
