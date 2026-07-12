<?php declare(strict_types=1);

namespace Kniebes\IoAtmosphere\StandardSite;

readonly class PublicationRecord
{
    public const string TYPE = 'site.standard.publication';

    public function __construct(
        public string $url,
        public string $name,
        public ?string $description = null,
    ) {
    }

    public function toArray(): array
    {
        $record = [
            '$type' => self::TYPE,
            'url' => $this->url,
            'name' => $this->name,
        ];

        if ($this->description !== null && $this->description !== '') {
            $record['description'] = $this->description;
        }

        return $record;
    }
}
