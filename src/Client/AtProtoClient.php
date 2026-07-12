<?php declare(strict_types=1);

namespace Kniebes\IoAtmosphere\Client;

use Kniebes\IoAtmosphere\Exception\AtProtoException;

class AtProtoClient
{
    private ?Session $session = null;

    public function __construct(private readonly string $pdsUrl)
    {
    }

    /**
     * @throws AtProtoException
     */
    public function login(string $identifier, string $appPassword): Session
    {
        $data = $this->sendRequest(
            method: 'POST',
            nsid: 'com.atproto.server.createSession',
            body: json_encode(['identifier' => $identifier, 'password' => $appPassword], JSON_UNESCAPED_SLASHES),
            contentType: 'application/json',
            useSession: false
        );

        if (empty($data['did']) || empty($data['accessJwt'])) {
            throw new AtProtoException('createSession: did oder accessJwt fehlt in der Antwort');
        }

        $this->session = new Session(
            did: $data['did'],
            handle: $data['handle'] ?? '',
            accessJwt: $data['accessJwt'],
            refreshJwt: $data['refreshJwt'] ?? ''
        );

        return $this->session;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    /**
     * @throws AtProtoException
     */
    public function putRecord(string $collection, string $recordKey, array $record): array
    {
        $session = $this->requireSession();

        return $this->sendRequest(
            method: 'POST',
            nsid: 'com.atproto.repo.putRecord',
            body: json_encode(
                [
                    'repo' => $session->did,
                    'collection' => $collection,
                    'rkey' => $recordKey,
                    'record' => $record,
                ],
                JSON_UNESCAPED_SLASHES
            ),
            contentType: 'application/json'
        );
    }

    /**
     * @throws AtProtoException
     */
    public function getRecord(string $collection, string $recordKey, ?string $repo = null): array
    {
        return $this->sendRequest(
            method: 'GET',
            nsid: 'com.atproto.repo.getRecord',
            queryParameters: [
                'repo' => $repo ?? $this->requireSession()->did,
                'collection' => $collection,
                'rkey' => $recordKey,
            ]
        );
    }

    /**
     * @throws AtProtoException
     */
    public function deleteRecord(string $collection, string $recordKey): void
    {
        $session = $this->requireSession();

        $this->sendRequest(
            method: 'POST',
            nsid: 'com.atproto.repo.deleteRecord',
            body: json_encode(
                [
                    'repo' => $session->did,
                    'collection' => $collection,
                    'rkey' => $recordKey,
                ],
                JSON_UNESCAPED_SLASHES
            ),
            contentType: 'application/json'
        );
    }

    /**
     * @throws AtProtoException
     */
    public function uploadBlob(string $bytes, string $mimeType): array
    {
        $data = $this->sendRequest(
            method: 'POST',
            nsid: 'com.atproto.repo.uploadBlob',
            body: $bytes,
            contentType: $mimeType
        );

        if (empty($data['blob'])) {
            throw new AtProtoException('uploadBlob: blob fehlt in der Antwort');
        }

        return $data['blob'];
    }

    /**
     * @throws AtProtoException
     */
    private function requireSession(): Session
    {
        if ($this->session === null) {
            throw new AtProtoException('Keine Session vorhanden, zuerst login() aufrufen');
        }

        return $this->session;
    }

    /**
     * @throws AtProtoException
     */
    private function sendRequest(
        string $method,
        string $nsid,
        ?string $body = null,
        ?string $contentType = null,
        array $queryParameters = [],
        bool $useSession = true
    ): array {
        $url = rtrim($this->pdsUrl, '/') . '/xrpc/' . $nsid;
        if ($queryParameters !== []) {
            $url .= '?' . http_build_query($queryParameters);
        }

        $headers = ['Accept: application/json'];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        if ($useSession && $this->session !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->session->accessJwt;
        }

        $curlHandle = curl_init();
        curl_setopt_array($curlHandle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => $method === 'POST',
        ]);
        if ($body !== null) {
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curlHandle);
        $curlError = curl_error($curlHandle);
        $httpStatus = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        curl_close($curlHandle);

        if ($response === false) {
            throw new AtProtoException($nsid . ': ' . $curlError);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($httpStatus >= 400) {
            throw new AtProtoException(sprintf(
                '%s: HTTP %d - %s: %s',
                $nsid,
                $httpStatus,
                $data['error'] ?? 'unbekannter Fehler',
                $data['message'] ?? $response
            ));
        }

        return $data;
    }
}
