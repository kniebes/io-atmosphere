<?php declare(strict_types=1);

namespace Kniebes\IoAtmosphere\StandardSite;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

readonly class DocumentRecord
{
    public const string TYPE = 'site.standard.document';
    public const string CONTENT_TYPE_MARKDOWN = 'site.standard.content.markdown';

    /**
     * @param string $site AT-URI des Publication-Records ("at://…") oder Publikations-URL ("https://…")
     * @param array $coverImage Blob-Referenz aus AtProtoClient::uploadBlob()
     * @param string $markdownContent Volltext als Markdown, landet in der content-Union
     * @param array $bskyPostRef strongRef ['uri' => …, 'cid' => …] auf den zugehörigen Bluesky-Post
     */
    public function __construct(
        public string $site,
        public string $title,
        public DateTimeInterface $publishedAt,
        public ?string $path = null,
        public ?string $description = null,
        public ?string $textContent = null,
        public array $tags = [],
        public ?DateTimeInterface $updatedAt = null,
        public ?array $coverImage = null,
        public ?string $markdownContent = null,
        public ?array $bskyPostRef = null,
    ) {
    }

    public function toArray(): array
    {
        $record = [
            '$type' => self::TYPE,
            'site' => $this->site,
            'title' => $this->title,
            'publishedAt' => $this->formatDateTime($this->publishedAt),
        ];

        if ($this->path !== null && $this->path !== '') {
            $record['path'] = $this->path;
        }
        if ($this->description !== null && $this->description !== '') {
            $record['description'] = $this->description;
        }
        if ($this->textContent !== null && $this->textContent !== '') {
            $record['textContent'] = $this->textContent;
        }
        if ($this->tags !== []) {
            $record['tags'] = array_values($this->tags);
        }
        if ($this->updatedAt !== null) {
            $record['updatedAt'] = $this->formatDateTime($this->updatedAt);
        }
        if ($this->coverImage !== null) {
            $record['coverImage'] = $this->coverImage;
        }
        if ($this->markdownContent !== null && $this->markdownContent !== '') {
            $record['content'] = [
                '$type' => self::CONTENT_TYPE_MARKDOWN,
                'text' => $this->markdownContent,
            ];
        }
        if ($this->bskyPostRef !== null) {
            $record['bskyPostRef'] = $this->bskyPostRef;
        }

        return $record;
    }

    private function formatDateTime(DateTimeInterface $dateTime): string
    {
        return DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
