<?php declare(strict_types=1);

/**
 * Beispiel-Integration für ein Blog.
 *
 * Zeigt den kompletten Syndication-Ablauf mit diesem Package:
 *  - Publish: Cover hochladen, beim ersten Mal einen Bluesky-Post erstellen,
 *    Document mit bskyPostRef publizieren (stabiler Record-Key = Post-ID,
 *    erneutes Publizieren wird dadurch zum Update)
 *  - Depublish: Document und Bluesky-Post löschen
 *
 * Die Klasse wird nach jedem Speichern eines Posts aufgerufen (z. B. aus einem
 * Event-Listener heraus): (new BlogSyndication(...))->syncPost($post);
 *
 * App-spezifisch bleibt nur die Persistenz der Referenzen, dafür das
 * SyndicationStateStorage-Interface implementieren (z. B. eine kleine
 * Key-Value-Tabelle pro Post). Die Referenzen sind das Gedächtnis der
 * Syndication: Sie existieren genau dann, wenn der Post draußen ist.
 */

namespace Kniebes\IoAtmosphere\Examples;

use finfo;
use Kniebes\IoAtmosphere\Bluesky\BlueskyPostBuilder;
use Kniebes\IoAtmosphere\Client\AtProtoClient;
use Kniebes\IoAtmosphere\StandardSite\DocumentRecord;
use Kniebes\IoAtmosphere\StandardSite\StandardSitePublisher;
use DateTimeInterface;
use Throwable;

/**
 * Persistenz der Syndication-Referenzen pro Post, z. B. als Datenbank-Tabelle.
 */
interface SyndicationStateStorage
{
    public function loadDocumentUri(string $postId): ?string;

    public function storeDocumentUri(string $postId, string $documentUri): void;

    public function deleteDocumentUri(string $postId): void;

    /** @return array{uri: string, cid: string}|null */
    public function loadBlueskyPostRef(string $postId): ?array;

    /** @param array{uri: string, cid: string} $postRef */
    public function storeBlueskyPostRef(string $postId, array $postRef): void;

    public function deleteBlueskyPostRef(string $postId): void;
}

/**
 * Der zu syndizierende Blog-Post, befüllt aus der eigenen Datenhaltung.
 */
readonly class BlogPost
{
    public function __construct(
        public string $id,
        public bool $isPublished,
        public string $title,
        public string $path,                        // z. B. /2026/7/12/mein-post.html
        public DateTimeInterface $publishedAt,
        public ?DateTimeInterface $updatedAt = null,
        public ?string $summary = null,             // Teaser als Plaintext
        public ?string $textContent = null,         // Volltext als Plaintext
        public ?string $markdown = null,            // Volltext als Markdown
        public array $tags = [],
        public ?string $coverImagePath = null,      // lokaler Pfad oder URL, max. 1 MB
    ) {
    }
}

class BlogSyndication
{
    private const int COVER_IMAGE_MAX_BYTES = 1_000_000;
    private const int BLUESKY_MAX_TAGS = 4;

    private ?AtProtoClient $client = null;
    private ?StandardSitePublisher $publisher = null;

    public function __construct(
        private readonly string $pdsUrl,            // z. B. https://bsky.social
        private readonly string $identifier,        // Handle oder E-Mail
        private readonly string $appPassword,
        private readonly string $publicationUri,    // at://…/site.standard.publication/self
        private readonly string $blogUrl,           // z. B. https://example.com
        private readonly SyndicationStateStorage $storage,
    ) {
    }

    public function syncPost(BlogPost $post): void
    {
        if (!$post->isPublished) {
            $this->retractPost($post->id);

            return;
        }

        $coverImage = $this->uploadCoverImage($post);
        $bskyPostRef = $this->ensureBlueskyPost(post: $post, coverImage: $coverImage);

        $documentUri = $this->getPublisher()->publishDocument(
            recordKey: $post->id,
            document: new DocumentRecord(
                site: $this->publicationUri,
                title: $post->title,
                publishedAt: $post->publishedAt,
                path: $post->path,
                description: $post->summary,
                textContent: $post->textContent,
                tags: $post->tags,
                updatedAt: $post->updatedAt,
                coverImage: $coverImage,
                markdownContent: $post->markdown,
                bskyPostRef: $bskyPostRef
            )
        );
        $this->storage->storeDocumentUri($post->id, $documentUri);
    }

    public function retractPost(string $postId): void
    {
        if ($this->storage->loadDocumentUri($postId) !== null) {
            $this->getPublisher()->deleteDocument(recordKey: $postId);
            $this->storage->deleteDocumentUri($postId);
        }

        $postRef = $this->storage->loadBlueskyPostRef($postId);
        if ($postRef !== null) {
            $uriParts = explode('/', $postRef['uri']);
            $this->getClient()->deleteRecord(
                collection: BlueskyPostBuilder::TYPE,
                recordKey: (string) end($uriParts)
            );
            $this->storage->deleteBlueskyPostRef($postId);
        }
    }

    /**
     * Bluesky-Post nur beim ersten Publish erstellen; Posts sind auf Bluesky
     * nicht editierbar, spätere Saves verwenden die gespeicherte Referenz.
     *
     * @return array{uri: string, cid: string}|null
     */
    private function ensureBlueskyPost(BlogPost $post, ?array $coverImage): ?array
    {
        $storedPostRef = $this->storage->loadBlueskyPostRef($post->id);
        if ($storedPostRef !== null) {
            return $storedPostRef;
        }

        $builder = (new BlueskyPostBuilder())
            ->addText($post->title)
            ->setExternalEmbed(
                uri: rtrim($this->blogUrl, '/') . $post->path,
                title: $post->title,
                description: $post->summary ?? '',
                thumb: $coverImage
            );

        $isFirstTag = true;
        foreach (array_slice($post->tags, 0, self::BLUESKY_MAX_TAGS) as $term) {
            $hashtag = (string) preg_replace('/[^a-zA-Z0-9]+/', '', $term);
            if ($hashtag === '') {
                continue;
            }
            $separator = $isFirstTag ? "\n\n" : ' ';
            if (!$builder->fits($separator . '#' . $hashtag)) {
                break;
            }
            $builder->addText($separator)->addTag($hashtag);
            $isFirstTag = false;
        }

        try {
            $result = $this->getClient()->createRecord(
                collection: BlueskyPostBuilder::TYPE,
                record: $builder->toArray()
            );
        } catch (Throwable $throwable) {
            // Ein fehlgeschlagener Bluesky-Post soll das Document nicht verhindern.
            error_log('Bluesky-Post fehlgeschlagen: ' . $throwable->getMessage());

            return null;
        }

        if (empty($result['uri']) || empty($result['cid'])) {
            return null;
        }

        $postRef = ['uri' => $result['uri'], 'cid' => $result['cid']];
        $this->storage->storeBlueskyPostRef($post->id, $postRef);

        return $postRef;
    }

    private function uploadCoverImage(BlogPost $post): ?array
    {
        if ($post->coverImagePath === null) {
            return null;
        }

        $imageBytes = @file_get_contents($post->coverImagePath);
        if ($imageBytes === false || strlen($imageBytes) > self::COVER_IMAGE_MAX_BYTES) {
            return null;
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($imageBytes) ?: 'image/jpeg';

        return $this->getClient()->uploadBlob(bytes: $imageBytes, mimeType: $mimeType);
    }

    private function getClient(): AtProtoClient
    {
        if ($this->client === null) {
            $this->client = new AtProtoClient(pdsUrl: $this->pdsUrl);
            $this->client->login(identifier: $this->identifier, appPassword: $this->appPassword);
        }

        return $this->client;
    }

    private function getPublisher(): StandardSitePublisher
    {
        if ($this->publisher === null) {
            $this->publisher = new StandardSitePublisher(client: $this->getClient());
        }

        return $this->publisher;
    }
}
