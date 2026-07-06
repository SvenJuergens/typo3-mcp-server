<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Doctrine\DBAL\ParameterType;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\Service\SiteInformationService;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\IllegalFileExtensionException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Resource\Exception\OnlineMediaAlreadyExistsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for uploading new files into TYPO3 file storages (FAL).
 *
 * Files are deliberately create-only through MCP: physical files are not
 * workspace-versioned in TYPO3, so overwriting or deleting them would be the
 * only immediately-live, irreversible operation in this server. Name conflicts
 * are therefore auto-renamed and identical content is deduplicated instead.
 */
class UploadFileTool extends AbstractRecordTool
{
    protected const MAX_DOWNLOAD_BYTES = 52428800; // 50 MiB
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
        return [
            'description' => 'Upload a new file into the TYPO3 file storage (e.g. fileadmin). '
                . 'Provide EITHER "url" (the server downloads the file; YouTube/Vimeo URLs create an online media asset instead) '
                . 'OR "content" (raw text for text-based formats like svg, csv or vtt). '
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
                            . 'Binary formats cannot be uploaded this way - use "url" instead.',
                    ],
                    'fileName' => [
                        'type' => 'string',
                        'description' => 'Target file name including extension (e.g. "team-photo.jpg"). '
                            . 'Required with "content"; optional with "url" (derived from the URL when omitted).',
                    ],
                    'targetFolder' => [
                        'type' => 'string',
                        'description' => 'Folder path inside the storage, e.g. "/user_upload/campaign/" or "2:/some/folder/" to address '
                            . 'a specific storage. Missing folders are created. Defaults to the user\'s default upload folder.',
                    ],
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

        if (($url !== '') === $hasContent) {
            return $this->createErrorResult('Provide exactly one of "url" or "content".');
        }

        try {
            $folder = $this->resolveTargetFolder(trim((string)($params['targetFolder'] ?? '')));
        } catch (InsufficientFolderAccessPermissionsException | InsufficientFolderWritePermissionsException $e) {
            return $this->createErrorResult('No permission for the target folder: ' . $e->getMessage());
        }

        if ($url !== '') {
            // Online media (YouTube/Vimeo) never hits the download path: the URL is
            // recognized by its pattern and stored as a placeholder file via the core API.
            $onlineMediaResult = $this->tryCreateOnlineMedia($url, $folder);
            if ($onlineMediaResult !== null) {
                return $onlineMediaResult;
            }
            [$tempPath, $contentType, $finalUrl] = $this->downloadFile($url);
            $fileName = $requestedFileName !== '' ? $requestedFileName : $this->deriveFileNameFromUrl($finalUrl, $contentType);
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
            $fileName = basename($fileName);
            if ($fileName === '' || !str_contains($fileName, '.')) {
                return $this->createErrorResult(
                    'Could not determine a file name with an extension. Pass an explicit "fileName" like "image.jpg".'
                );
            }

            // Identical content in this storage? Return the existing file instead of
            // creating a copy - this also makes retries after timeouts idempotent.
            $existingFile = $this->findIdenticalFile($folder->getStorage(), sha1_file($tempPath));
            if ($existingFile !== null) {
                return $this->buildResult($existingFile, $fileName, deduplicated: true);
            }

            try {
                $file = $folder->getStorage()->addFile($tempPath, $folder, $fileName, DuplicationBehavior::RENAME);
            } catch (IllegalFileExtensionException $e) {
                return $this->createErrorResult('This file extension is not allowed: ' . $e->getMessage());
            } catch (InsufficientFolderWritePermissionsException $e) {
                return $this->createErrorResult('No permission to add files to this folder: ' . $e->getMessage());
            } catch (\TYPO3\CMS\Core\Validation\ResultException $e) {
                // TYPO3 >= 14: ResourceConsistencyService rejects files whose content
                // does not match their extension/mime type.
                return $this->createErrorResult(
                    'The file was rejected because its content does not match the file extension "'
                    . pathinfo($fileName, PATHINFO_EXTENSION) . '". '
                    . 'Make sure the URL/content actually delivers this file type.'
                );
            }

            return $this->buildResult($file, $fileName, deduplicated: false);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Resolve the target folder, creating missing folders along the way.
     * Accepts "" (default upload folder), "/path/in/default/storage" and
     * combined identifiers like "2:/path".
     */
    protected function resolveTargetFolder(string $targetFolder): Folder
    {
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);

        if ($targetFolder === '') {
            $folder = GeneralUtility::makeInstance(DefaultUploadFolderResolver::class)->resolve($GLOBALS['BE_USER']);
            if (!$folder instanceof Folder) {
                throw new \InvalidArgumentException(
                    'No default upload folder available for this user. Pass an explicit "targetFolder".'
                );
            }
            $this->applyUserPermissionsToStorage($folder->getStorage());
            return $folder;
        }

        if (preg_match('/^(\d+):(.*)$/', $targetFolder, $matches)) {
            $storage = $storageRepository->getStorageObject((int)$matches[1]);
            $path = $matches[2];
        } else {
            $storage = $storageRepository->getDefaultStorage();
            $path = $targetFolder;
        }

        if ($storage === null || !$storage->isOnline()) {
            throw new \InvalidArgumentException('No usable file storage found for target folder "' . $targetFolder . '".');
        }
        $this->applyUserPermissionsToStorage($storage);

        $segments = GeneralUtility::trimExplode('/', $path, true);
        if (empty($segments)) {
            return $storage->getRootLevelFolder();
        }

        // Walk down the existing part of the path, then create the remainder relative
        // to the deepest existing folder (permission checks apply where creation starts,
        // which keeps this working for users whose file mount is not the storage root).
        $existingIdentifier = '/';
        while (!empty($segments) && $storage->hasFolder($existingIdentifier . $segments[0] . '/')) {
            $existingIdentifier .= array_shift($segments) . '/';
        }
        $folder = $storage->getFolder($existingIdentifier);
        if (!empty($segments)) {
            try {
                $folder = $storage->createFolder(implode('/', $segments), $folder);
            } catch (ExistingTargetFolderException) {
                $folder = $storage->getFolder($existingIdentifier . implode('/', $segments) . '/');
            }
        }
        return $folder;
    }

    /**
     * Enforce the user's file mounts and file permissions on the storage.
     *
     * TYPO3's StoragePermissionsAspect does this only for backend HTTP requests;
     * MCP tools also run via CLI/stdio where $GLOBALS['TYPO3_REQUEST'] is not a
     * backend request, so the storage would otherwise be unrestricted for
     * non-admin users (mirrors the read-side SysFileMountRestrictionListener).
     */
    protected function applyUserPermissionsToStorage(ResourceStorage $storage): void
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication
            || $user->isAdmin()
            || $storage->isFallbackStorage()
            || $storage->getEvaluatePermissions()
        ) {
            return;
        }

        $storage->setEvaluatePermissions(true);
        // getFilePermissions() merged with per-storage TSconfig overrides,
        // same as the core aspect's getFilePermissionsForStorage()
        $permissions = $user->getFilePermissions();
        $storageOverrides = $user->getTSConfig()['permissions.']['file.']['storage.'][$storage->getUid() . '.'] ?? [];
        foreach ($storageOverrides as $permission => $value) {
            $permissions[$permission] = (bool)$value;
        }
        $storage->setUserPermissions($permissions);
        foreach ($user->getFileMountRecords() as $fileMountRow) {
            if (!str_contains($fileMountRow['identifier'] ?? '', ':')) {
                continue;
            }
            [$base, $path] = GeneralUtility::trimExplode(':', $fileMountRow['identifier'], false, 2);
            if ((int)$base === $storage->getUid()) {
                try {
                    $storage->addFileMount($path, $fileMountRow);
                } catch (FolderDoesNotExistException) {
                    // Invalid mount, skip silently (same as the core aspect)
                }
            }
        }
    }

    /**
     * Store a YouTube/Vimeo URL as an online media asset via the core helper.
     * Returns null when the URL is not an online media URL.
     */
    protected function tryCreateOnlineMedia(string $url, Folder $folder): ?CallToolResult
    {
        $registry = GeneralUtility::makeInstance(OnlineMediaHelperRegistry::class);
        try {
            $file = $registry->transformUrlToFile($url, $folder);
        } catch (OnlineMediaAlreadyExistsException $e) {
            return $this->buildResult($e->getOnlineMedia(), '', deduplicated: true, onlineMedia: true);
        }
        if ($file === null) {
            return null;
        }
        return $this->buildResult($file, $file->getName(), deduplicated: false, onlineMedia: true);
    }

    /**
     * Download a remote file to a temp path, following redirects manually so
     * every hop passes the SSRF checks. Returns [tempPath, contentType, finalUrl].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function downloadFile(string $url): array
    {
        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $resolvedIp = $this->assertPublicHttpUrl($currentUrl);

            $options = [
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'connect_timeout' => 10,
                'timeout' => 60,
                'headers' => ['User-Agent' => 'TYPO3-MCP-Server'],
            ];
            // Pin the vetted IP so the actual request cannot be re-routed to an
            // internal address via DNS rebinding (honored by the curl handler;
            // harmless elsewhere).
            $parts = parse_url($currentUrl);
            $host = $parts['host'] ?? '';
            if ($resolvedIp !== null && !filter_var($host, FILTER_VALIDATE_IP) && defined('CURLOPT_RESOLVE')) {
                $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);
                $options['curl'] = [\CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolvedIp]];
            }

            $response = $requestFactory->request($currentUrl, 'GET', $options);
            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400) {
                $location = $response->getHeaderLine('Location');
                if ($location === '') {
                    throw new \InvalidArgumentException('The URL redirected without a Location header.');
                }
                $currentUrl = (string)UriResolver::resolve(new Uri($currentUrl), new Uri($location));
                continue;
            }
            if ($status !== 200) {
                throw new \InvalidArgumentException('Downloading the URL failed with HTTP status ' . $status . '.');
            }

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
                if ($bytes > self::MAX_DOWNLOAD_BYTES) {
                    fclose($handle);
                    @unlink($tempPath);
                    throw new \InvalidArgumentException(
                        'The file exceeds the maximum download size of ' . (self::MAX_DOWNLOAD_BYTES / 1048576) . ' MiB.'
                    );
                }
                fwrite($handle, $chunk);
            }
            fclose($handle);

            if ($bytes === 0) {
                @unlink($tempPath);
                throw new \InvalidArgumentException('The URL returned an empty response body.');
            }

            return [$tempPath, $response->getHeaderLine('Content-Type'), $currentUrl];
        }

        throw new \InvalidArgumentException('Too many redirects while downloading the URL.');
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

    protected function deriveFileNameFromUrl(string $url, string $contentType): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $fileName = basename(rawurldecode($path));

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

    /**
     * Find a non-missing file with identical content (same SHA1) in the storage.
     * Non-admin users only get matches within their file mounts, otherwise the
     * dedupe would leak (and hand out) files they cannot access.
     */
    protected function findIdenticalFile(ResourceStorage $storage, string $sha1): ?File
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $rows = $queryBuilder->select('uid')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq('storage', $queryBuilder->createNamedParameter($storage->getUid(), ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sha1', $queryBuilder->createNamedParameter($sha1)),
                $queryBuilder->expr()->eq('missing', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            if (!$GLOBALS['BE_USER']->isAdmin() && !$this->tableAccessService->canAccessFileUid($uid)) {
                continue;
            }
            try {
                return GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($uid);
            } catch (\Exception) {
                continue;
            }
        }
        return null;
    }

    protected function buildResult(File $file, string $requestedFileName, bool $deduplicated, bool $onlineMedia = false): CallToolResult
    {
        $siteInformation = GeneralUtility::makeInstance(SiteInformationService::class);

        $data = [
            'uid' => $file->getUid(),
            'fileName' => $file->getName(),
            'identifier' => $file->getCombinedIdentifier(),
            'publicUrl' => $siteInformation->makeAbsoluteUrl($file->getPublicUrl()),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];

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
