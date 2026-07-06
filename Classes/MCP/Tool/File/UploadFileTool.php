<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RedirectMiddleware;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Service\SiteInformationService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Resource\Exception\OnlineMediaAlreadyExistsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for uploading new files into TYPO3 file storages (FAL).
 *
 * Three modes:
 *  - url:      the server downloads the file (YouTube/Vimeo URLs become online media assets)
 *  - content:  raw text is stored as a file (svg, csv, vtt, ...)
 *  - neither:  a pre-signed, single-use upload URL is returned so the client
 *              can HTTP-PUT a local file directly to the TYPO3 instance
 *
 * Files are create-only; see FileUploadService for the rename/dedupe rules.
 */
class UploadFileTool extends AbstractRecordTool
{
    protected const MAX_REDIRECTS = 5;

    /**
     * Fallback extension per content type for URLs whose path carries no
     * usable file name (e.g. "https://example.com/image?id=4").
     */
    protected const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'video/mp4' => 'mp4',
        'audio/mpeg' => 'mp3',
    ];

    public function getSchema(): array
    {
        $uploadService = GeneralUtility::makeInstance(FileUploadService::class);

        $targetFolderProperty = [
            'type' => 'string',
            'description' => 'Folder path inside the storage, e.g. "/user_upload/campaign/" or "2:/some/folder/" to address '
                . 'a specific storage. Missing folders are created.',
        ];
        $defaultFolder = $uploadService->getDefaultUploadFolderIdentifier();
        if ($defaultFolder !== null) {
            $targetFolderProperty['default'] = $defaultFolder;
        }

        return [
            'description' => 'Upload a new file into the TYPO3 file storage (e.g. fileadmin). '
                . 'Provide "url" (the server downloads the file; YouTube/Vimeo URLs create an online media asset) '
                . 'or "content" (raw text for text-based formats like svg, csv or vtt). '
                . 'With NEITHER of them, a pre-signed single-use upload URL is returned to which a local file '
                . 'can be sent directly via HTTP PUT (e.g. with curl) - use this to upload files from the local machine. '
                . 'This tool never overwrites anything: name conflicts are resolved by renaming (image.jpg -> image_01.jpg) '
                . 'and re-uploading identical content returns the already existing file. '
                . 'The file is stored immediately (files are not workspace-versioned), but it is not visible on the website '
                . 'until a record references it - create the reference with WriteTable (e.g. tt_content.assets = [{"uid_local": <uid>}]).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'HTTP(S) URL to download the file from. '
                            . 'YouTube and Vimeo video URLs are recognized and stored as TYPO3 online media assets '
                            . '(a small placeholder file referencing the video - the video itself is not downloaded).',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Raw text content to store as a file. Only for text-based formats (svg, txt, csv, vtt, ...). '
                            . 'Binary formats cannot be uploaded this way - use "url" or the pre-signed upload URL instead.',
                    ],
                    'fileName' => [
                        'type' => 'string',
                        'description' => 'Target file name including extension (e.g. "team-photo.jpg"). '
                            . 'Required with "content"; optional otherwise (derived from the URL, Content-Disposition header, '
                            . 'or provided during the pre-signed upload).',
                    ],
                    'targetFolder' => $targetFolderProperty,
                ],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $url = trim((string)($params['url'] ?? ''));
        $content = $params['content'] ?? null;
        $hasContent = is_string($content) && $content !== '';
        $requestedFileName = trim((string)($params['fileName'] ?? ''));
        $uploadService = GeneralUtility::makeInstance(FileUploadService::class);

        if ($url !== '' && $hasContent) {
            return $this->createErrorResult('Provide only one of "url" or "content" (or neither for a pre-signed upload URL).');
        }

        try {
            $folder = $uploadService->resolveTargetFolder(trim((string)($params['targetFolder'] ?? '')));
        } catch (InsufficientFolderAccessPermissionsException | InsufficientFolderWritePermissionsException $e) {
            return $this->createErrorResult('No permission for the target folder: ' . $e->getMessage());
        }

        // No source at all: hand out a pre-signed upload URL instead
        if ($url === '' && !$hasContent) {
            return $this->createPresignedUploadResult($uploadService, $folder, $requestedFileName);
        }

        if ($url !== '') {
            // Online media (YouTube/Vimeo) never hits the download path: the URL is
            // recognized by its pattern and stored as a placeholder file via the core API.
            $onlineMediaResult = $this->tryCreateOnlineMedia($url, $folder, $uploadService);
            if ($onlineMediaResult !== null) {
                return $onlineMediaResult;
            }
            [$tempPath, $fileName] = $this->downloadFile($url, $requestedFileName, $uploadService->getMaxFileBytes());
        } else {
            if ($requestedFileName === '') {
                return $this->createErrorResult('"fileName" is required when uploading "content".');
            }
            $tempPath = GeneralUtility::tempnam('mcp_upload_');
            if (@file_put_contents($tempPath, $content) === false) {
                return $this->createErrorResult('Failed to buffer the uploaded content on the server.');
            }
            $fileName = $requestedFileName;
        }

        try {
            try {
                $stored = $uploadService->storeFile($tempPath, $fileName, $folder);
            } catch (InsufficientFolderWritePermissionsException $e) {
                return $this->createErrorResult('No permission to add files to this folder: ' . $e->getMessage());
            }
            return $this->buildResult($uploadService, $stored['file'], $fileName, $stored['deduplicated']);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Create a single-use upload URL the MCP client can PUT a local file to.
     */
    protected function createPresignedUploadResult(FileUploadService $uploadService, Folder $folder, string $fileName): CallToolResult
    {
        $tokenData = $uploadService->createUploadToken($folder, $fileName);
        $siteInformation = GeneralUtility::makeInstance(SiteInformationService::class);
        $uploadUrl = $siteInformation->makeAbsoluteUrl('/mcp_upload?token=' . $tokenData['token']);

        $curlFileName = $fileName !== '' ? $fileName : 'photo.jpg';
        $curlUrl = $uploadUrl . ($fileName === '' ? '&fileName=' . $curlFileName : '');

        return $this->createJsonResult([
            'uploadUrl' => $uploadUrl,
            'targetFolder' => $folder->getCombinedIdentifier(),
            'fileName' => $fileName !== '' ? $fileName : null,
            'validUntil' => date('c', $tokenData['validUntil']),
            'instructions' => 'Send the raw file bytes via HTTP PUT (or POST) to the uploadUrl, e.g.: '
                . "curl -sS -T '" . $curlFileName . "' '" . $curlUrl . "'"
                . ($fileName === '' ? ' - replace the fileName query parameter with the actual file name including extension.' : '')
                . ' The URL is single-use and expires at validUntil. '
                . 'The endpoint responds with JSON containing the created file (uid, fileName, publicUrl, ...); '
                . 'use that uid with WriteTable to reference the file from a record.',
        ]);
    }

    /**
     * Store a YouTube/Vimeo URL as an online media asset via the core helper.
     * Returns null when the URL is not an online media URL.
     */
    protected function tryCreateOnlineMedia(string $url, Folder $folder, FileUploadService $uploadService): ?CallToolResult
    {
        $registry = GeneralUtility::makeInstance(OnlineMediaHelperRegistry::class);
        try {
            $file = $registry->transformUrlToFile($url, $folder);
        } catch (OnlineMediaAlreadyExistsException $e) {
            return $this->buildResult($uploadService, $e->getOnlineMedia(), '', deduplicated: true, onlineMedia: true);
        }
        if ($file === null) {
            return null;
        }
        return $this->buildResult($uploadService, $file, $file->getName(), deduplicated: false, onlineMedia: true);
    }

    /**
     * Download a remote file to a temp path. Redirects are followed by Guzzle,
     * but every hop is re-validated against the SSRF rules via on_redirect.
     * Returns [tempPath, fileName].
     *
     * @return array{0: string, 1: string}
     */
    protected function downloadFile(string $url, string $requestedFileName, int $maxBytes): array
    {
        $resolvedIp = $this->assertPublicHttpUrl($url);

        $options = [
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'track_redirects' => true,
                'on_redirect' => function ($request, $response, $uri): void {
                    $this->assertPublicHttpUrl((string)$uri);
                },
            ],
            'http_errors' => false,
            'stream' => true,
            'connect_timeout' => 10,
            'timeout' => 300,
            'headers' => ['User-Agent' => 'TYPO3-MCP-Server'],
        ];
        // Pin the vetted IP so the actual request cannot be re-routed to an
        // internal address via DNS rebinding (honored by the curl handler;
        // harmless elsewhere). Only possible for the first hop - redirect
        // targets are still host-validated in on_redirect above.
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if ($resolvedIp !== null && !filter_var($host, FILTER_VALIDATE_IP) && defined('CURLOPT_RESOLVE')) {
            $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);
            $options['curl'] = [\CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolvedIp]];
        }

        try {
            $response = GeneralUtility::makeInstance(RequestFactory::class)->request($url, 'GET', $options);
        } catch (GuzzleException $e) {
            // Re-throw SSRF violations from on_redirect with their original message
            $previous = $e->getPrevious();
            if ($previous instanceof \InvalidArgumentException) {
                throw $previous;
            }
            if ($e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \InvalidArgumentException('Downloading the URL failed: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new \InvalidArgumentException('Downloading the URL failed with HTTP status ' . $response->getStatusCode() . '.');
        }

        // The final URL after redirects (Guzzle records the hops in a response header)
        $redirectHistory = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);
        $finalUrl = $redirectHistory !== [] ? (string)end($redirectHistory) : $url;

        $tempPath = GeneralUtility::tempnam('mcp_upload_');
        $handle = fopen($tempPath, 'wb');
        $body = $response->getBody();
        $bytes = 0;
        while (!$body->eof()) {
            $chunk = $body->read(65536);
            if ($chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                fclose($handle);
                @unlink($tempPath);
                throw new \InvalidArgumentException(
                    'The file exceeds the maximum size of ' . round($maxBytes / 1048576) . ' MiB '
                    . '(configurable via the extension setting "maxFileSizeMb").'
                );
            }
            fwrite($handle, $chunk);
        }
        fclose($handle);

        if ($bytes === 0) {
            @unlink($tempPath);
            throw new \InvalidArgumentException('The URL returned an empty response body.');
        }

        $fileName = $requestedFileName !== '' ? $requestedFileName : $this->deriveFileName(
            $finalUrl,
            $response->getHeaderLine('Content-Disposition'),
            $response->getHeaderLine('Content-Type')
        );

        return [$tempPath, $fileName];
    }

    /**
     * Validate that a URL is http(s) and does not point at a private or
     * reserved address. Returns the vetted IP (null for URLs whose host is
     * already an IP literal).
     */
    protected function assertPublicHttpUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('Only http:// and https:// URLs can be downloaded. Got: ' . $url);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
            $isLiteral = true;
        } else {
            $ips = gethostbynamel($host) ?: [];
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
            if (empty($ips)) {
                throw new \InvalidArgumentException('Could not resolve host "' . $host . '".');
            }
            $isLiteral = false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException(
                    'The URL points to a private or reserved network address and cannot be downloaded.'
                );
            }
        }

        return $isLiteral ? null : $ips[0];
    }

    /**
     * Derive the file name: Content-Disposition beats the URL path, the
     * Content-Type based extension is the last resort.
     */
    protected function deriveFileName(string $url, string $contentDisposition, string $contentType): string
    {
        $fileName = GeneralUtility::makeInstance(FileUploadService::class)
            ->extractFileNameFromContentDisposition($contentDisposition);

        if ($fileName === null) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $fileName = basename(rawurldecode($path));
        }

        if ($fileName !== '' && str_contains($fileName, '.')) {
            return $fileName;
        }

        $mimeType = strtolower(trim(explode(';', $contentType)[0]));
        $extension = self::MIME_TO_EXTENSION[$mimeType] ?? null;
        if ($extension === null) {
            throw new \InvalidArgumentException(
                'Could not derive a file name from the URL (content type "' . $contentType . '"). Pass an explicit "fileName".'
            );
        }
        return ($fileName !== '' ? $fileName : 'download') . '.' . $extension;
    }

    protected function buildResult(
        FileUploadService $uploadService,
        File $file,
        string $requestedFileName,
        bool $deduplicated,
        bool $onlineMedia = false
    ): CallToolResult {
        $data = $uploadService->describeFile($file);

        if ($onlineMedia) {
            $data['onlineMedia'] = true;
        }
        if ($deduplicated) {
            $data['deduplicated'] = true;
            $data['note'] = 'An identical file already exists in this storage; it is returned instead of creating a duplicate.';
        } else {
            if ($requestedFileName !== '' && $file->getName() !== $requestedFileName) {
                $data['renamedFrom'] = $requestedFileName;
                $data['note'] = 'A different file with this name already existed, so the upload was stored under a new name.';
            }
            $data['nextStep'] = 'The file is stored but not yet used anywhere. Reference it from a record via WriteTable, '
                . 'e.g. tt_content field "assets": [{"uid_local": ' . $file->getUid() . ', "alternative": "<alt text>"}].';
        }

        return $this->createJsonResult($data);
    }
}
