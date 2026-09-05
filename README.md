# io-atmosphere

Plattformunabhängiger AT-Protocol-Client mit [standard.site](https://standard.site/)-Records, um Blog-Inhalte im Atmosphere-Netzwerk (z. B. [standard-reader.app](https://standard-reader.app/)) sichtbar zu machen. Keine Abhängigkeiten außer `ext-curl` und `ext-json`.

## Bausteine

- `Client\AtProtoClient`: XRPC-Client für einen PDS (`login` per App-Password, `putRecord`, `createRecord`, `getRecord`, `deleteRecord`, `uploadBlob`)
- `StandardSite\PublicationRecord`: `site.standard.publication` (einmalig pro Blog)
- `StandardSite\DocumentRecord`: `site.standard.document` (pro Artikel), optional mit Markdown-Volltext (`markdownContent`) und Verweis auf einen Bluesky-Post (`bskyPostRef`)
- `StandardSite\StandardSitePublisher`: legt Records an, aktualisiert und löscht sie; der Record-Key eines Documents muss eine TID sein, und wer sie pro Artikel festhält, macht erneutes Publizieren zum Update
- `Client\Tid`: erzeugt und prüft TIDs (base32-sortable, 13 Zeichen), `Tid::fromTimestamp()` leitet sie aus dem Veröffentlichungsdatum ab
- `Bluesky\BlueskyPostBuilder`: baut `app.bsky.feed.post`-Records; Links und Hashtags werden als Facets mit korrekten UTF-8-Byte-Offsets angehängt, Link-Card oder Bilder als Embed, max. 300 Grapheme

```php
use Kniebes\IoAtmosphere\Bluesky\BlueskyPostBuilder;

$post = (new BlueskyPostBuilder())
    ->setLangs(['de'])
    ->addText('Neuer Blogpost!')
    ->addText("\n\n")
    ->addTag('PHP')
    ->setExternalEmbed(
        uri: 'https://example.com/2026/7/12/neuer-blogpost.html',
        title: 'Neuer Blogpost',
        description: 'Teaser…',
        thumb: $blob
    );
$result = $client->createRecord(collection: BlueskyPostBuilder::TYPE, record: $post->toArray());
// $result['uri'] + $result['cid'] als bskyPostRef ins Document
```

## Verwendung

```php
use Kniebes\IoAtmosphere\Client\AtProtoClient;
use Kniebes\IoAtmosphere\Client\Tid;
use Kniebes\IoAtmosphere\StandardSite\DocumentRecord;
use Kniebes\IoAtmosphere\StandardSite\PublicationRecord;
use Kniebes\IoAtmosphere\StandardSite\StandardSitePublisher;

$client = new AtProtoClient(pdsUrl: 'https://bsky.social');
$client->login(identifier: 'example.com', appPassword: 'xxxx-xxxx-xxxx-xxxx');

$publisher = new StandardSitePublisher(client: $client);

// Einmalig: Publication anlegen
$publicationUri = $publisher->publishPublication(
    recordKey: 'self',
    publication: new PublicationRecord(url: 'https://example.com', name: 'Mein Blog')
);

// Pro Artikel: Document anlegen oder aktualisieren
$documentUri = $publisher->publishDocument(
    recordKey: Tid::fromTimestamp(strtotime('2026-07-12 10:00:00')),
    document: new DocumentRecord(
        site: $publicationUri,
        title: 'Hallo Atmosphere',
        publishedAt: new DateTimeImmutable('2026-07-12 10:00:00'),
        path: '/2026/7/12/hallo-atmosphere.html',
        markdownContent: 'Volltext als **Markdown**, landet als site.standard.content.markdown in der content-Union.'
    )
);
```

## Vollständiges Beispiel

[`examples/BlogSyndication.php`](examples/BlogSyndication.php) zeigt die komplette Integration in ein Blog: Publish mit Cover-Upload, Bluesky-Post nur beim ersten Veröffentlichen (mit `bskyPostRef` im Document, darüber können Reader Bluesky-Antworten als Kommentare anzeigen), Update über den aus der gespeicherten Document-URI wiederverwendeten TID-Record-Key und Depublizieren, das Document und Bluesky-Post wieder entfernt. App-spezifisch ist nur das kleine `SyndicationStateStorage`-Interface für die Persistenz der Referenzen.

## CLI: Publication anlegen

```
vendor/bin/atmosphere-create-publication.php <url> <name> [beschreibung] [record-key]
```

Der PDS-Zugang kommt aus den Umgebungsvariablen `ATMOSPHERE_PDS_URL`, `ATMOSPHERE_IDENTIFIER` und `ATMOSPHERE_APP_PASSWORD`; fehlende Werte fragt das Skript interaktiv ab. Der `record-key` ist per Default `self`, ein erneuter Aufruf mit gleichem Key aktualisiert den Record.

## Verifikation auf der Website

Damit Reader die Inhalte der Domain zuordnen, braucht die Website zwei Dinge (siehe [Verification-Doku](https://standard.site/docs/verification)):

1. `/.well-known/site.standard.publication` liefert als Plaintext die AT-URI des Publication-Records, z. B. `at://did:plc:abc123/site.standard.publication/self`
2. Jede Artikelseite trägt im Head: `<link rel="site.standard.document" href="at://did:plc:abc123/site.standard.document/123" />`
