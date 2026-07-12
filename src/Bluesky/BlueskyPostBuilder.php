<?php declare(strict_types=1);

namespace Kniebes\IoAtmosphere\Bluesky;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Kniebes\IoAtmosphere\Exception\AtProtoException;

/**
 * Baut einen app.bsky.feed.post-Record. Links und Hashtags werden als Facets
 * mit korrekten UTF-8-Byte-Offsets angehängt, deshalb den Text ausschließlich
 * über addText/addLink/addTag aufbauen.
 */
class BlueskyPostBuilder
{
    public const string TYPE = 'app.bsky.feed.post';
    public const int MAX_GRAPHEMES = 300;

    private string $text = '';
    private array $facets = [];
    private ?array $embed = null;
    private array $langs = [];
    private ?DateTimeInterface $createdAt = null;

    public function addText(string $text): self
    {
        $this->text .= $text;

        return $this;
    }

    public function addLink(string $url, ?string $label = null): self
    {
        $this->addFacet(
            visibleText: $label ?? $url,
            feature: ['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]
        );

        return $this;
    }

    /**
     * @param string $tag Hashtag ohne führendes "#"
     */
    public function addTag(string $tag): self
    {
        $this->addFacet(
            visibleText: '#' . $tag,
            feature: ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag]
        );

        return $this;
    }

    public function setExternalEmbed(string $uri, string $title, string $description = '', ?array $thumb = null): self
    {
        $external = [
            'uri' => $uri,
            'title' => $title,
            'description' => $description,
        ];
        if ($thumb !== null) {
            $external['thumb'] = $thumb;
        }

        $this->embed = [
            '$type' => 'app.bsky.embed.external',
            'external' => $external,
        ];

        return $this;
    }

    /**
     * @param array $images Liste aus ['blob' => Blob-Referenz, 'alt' => string], max. 4
     */
    public function setImagesEmbed(array $images): self
    {
        $imageList = [];
        foreach (array_slice($images, 0, 4) as $image) {
            $imageList[] = [
                'image' => $image['blob'],
                'alt' => $image['alt'] ?? '',
            ];
        }

        $this->embed = [
            '$type' => 'app.bsky.embed.images',
            'images' => $imageList,
        ];

        return $this;
    }

    public function setLangs(array $langs): self
    {
        $this->langs = $langs;

        return $this;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getGraphemeLength(): int
    {
        return self::countGraphemes($this->text);
    }

    public function fits(string $additionalText): bool
    {
        return $this->getGraphemeLength() + self::countGraphemes($additionalText) <= self::MAX_GRAPHEMES;
    }

    /**
     * @throws AtProtoException
     */
    public function toArray(): array
    {
        if ($this->getGraphemeLength() > self::MAX_GRAPHEMES) {
            throw new AtProtoException(sprintf(
                'Post-Text zu lang: %d Grapheme, erlaubt sind %d',
                $this->getGraphemeLength(),
                self::MAX_GRAPHEMES
            ));
        }

        $createdAt = $this->createdAt ?? new DateTimeImmutable();
        $record = [
            '$type' => self::TYPE,
            'text' => $this->text,
            'createdAt' => DateTimeImmutable::createFromInterface($createdAt)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($this->facets !== []) {
            $record['facets'] = $this->facets;
        }
        if ($this->embed !== null) {
            $record['embed'] = $this->embed;
        }
        if ($this->langs !== []) {
            $record['langs'] = $this->langs;
        }

        return $record;
    }

    public static function countGraphemes(string $text): int
    {
        if (function_exists('grapheme_strlen')) {
            return (int) grapheme_strlen($text);
        }

        return mb_strlen($text);
    }

    private function addFacet(string $visibleText, array $feature): void
    {
        $byteStart = strlen($this->text);
        $this->text .= $visibleText;

        $this->facets[] = [
            'index' => [
                'byteStart' => $byteStart,
                'byteEnd' => $byteStart + strlen($visibleText),
            ],
            'features' => [$feature],
        ];
    }
}
