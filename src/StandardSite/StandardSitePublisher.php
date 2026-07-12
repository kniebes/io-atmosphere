<?php declare(strict_types=1);

namespace Kniebes\IoAtmosphere\StandardSite;

use Kniebes\IoAtmosphere\Client\AtProtoClient;
use Kniebes\IoAtmosphere\Exception\AtProtoException;

class StandardSitePublisher
{
    public function __construct(private readonly AtProtoClient $client)
    {
    }

    /**
     * @throws AtProtoException
     */
    public function publishPublication(string $recordKey, PublicationRecord $publication): string
    {
        $this->assertValidRecordKey($recordKey);

        $result = $this->client->putRecord(
            collection: PublicationRecord::TYPE,
            recordKey: $recordKey,
            record: $publication->toArray()
        );

        return $this->extractUri($result);
    }

    /**
     * @throws AtProtoException
     */
    public function publishDocument(string $recordKey, DocumentRecord $document): string
    {
        $this->assertValidRecordKey($recordKey);

        $result = $this->client->putRecord(
            collection: DocumentRecord::TYPE,
            recordKey: $recordKey,
            record: $document->toArray()
        );

        return $this->extractUri($result);
    }

    /**
     * @throws AtProtoException
     */
    public function deleteDocument(string $recordKey): void
    {
        $this->assertValidRecordKey($recordKey);

        $this->client->deleteRecord(
            collection: DocumentRecord::TYPE,
            recordKey: $recordKey
        );
    }

    /**
     * @throws AtProtoException
     */
    private function extractUri(array $result): string
    {
        if (empty($result['uri'])) {
            throw new AtProtoException('putRecord: uri fehlt in der Antwort');
        }

        return $result['uri'];
    }

    /**
     * @throws AtProtoException
     */
    private function assertValidRecordKey(string $recordKey): void
    {
        if (in_array($recordKey, ['.', '..'], true) || !preg_match('/^[A-Za-z0-9._:~-]{1,512}$/', $recordKey)) {
            throw new AtProtoException('Ungültiger Record-Key: ' . $recordKey);
        }
    }
}
