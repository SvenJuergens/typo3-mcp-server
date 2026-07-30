<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Service\SiteInformationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\Tca\TcaFactory;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Target of the pre-signed upload URLs handed out by the UploadFile MCP tool.
 *
 * Accepts the raw file bytes as PUT/POST body (curl -T style) or a multipart
 * form upload, authenticated by a single-use token bound to a backend user
 * and a target folder. This is the "out-of-band upload" pattern for MCP:
 * binary data never travels through the model's context - the client's
 * harness uploads directly and gets the created sys_file back as JSON.
 */
class FileUploadEndpoint
{
    use CorsHeadersTrait;

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if ($request->getMethod() === 'OPTIONS') {
                return $this->handlePreflightRequest($request);
            }
            if (!in_array($request->getMethod(), ['PUT', 'POST'], true)) {
                return $this->jsonError('Use HTTP PUT (or POST) with the raw file bytes as request body.', 405, $request);
            }

            $uploadService = GeneralUtility::makeInstance(FileUploadService::class);
            // Preferred: Authorization header (query strings end up in server
            // logs); the ?token= query parameter is kept as fallback.
            $token = '';
            if (preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $matches)) {
                $token = $matches[1];
            }
            $token = $token !== '' ? $token : (string)($request->getQueryParams()['token'] ?? '');
            $tokenRow = $uploadService->consumeUploadToken($token);
            if ($tokenRow === null) {
                return $this->jsonError('Invalid, expired, or already used upload token.', 401, $request);
            }

            $this->setupBackendUserContext((int)$tokenRow['be_user_uid']);
            GeneralUtility::makeInstance(SiteInformationService::class)->setCurrentRequest($request);

            [$body, $uploadedFileName] = $this->extractUpload($request);

            // A file name fixed in the token wins: the token authorizes exactly
            // that upload, the request must not widen it to another file type.
            $fileName = (string)$tokenRow['file_name']
                ?: trim((string)($request->getQueryParams()['fileName'] ?? ''))
                ?: ($uploadedFileName ?? '')
                ?: ($uploadService->extractFileNameFromContentDisposition($request->getHeaderLine('Content-Disposition')) ?? '');
            if ($fileName === '') {
                return $this->jsonError(
                    'No file name given. Pass it as ?fileName=<name.ext> query parameter or Content-Disposition header.',
                    400,
                    $request
                );
            }

            $tempPath = $this->bufferToTempFile($body, $uploadService->getMaxFileBytes());
            try {
                $folder = $uploadService->resolveTargetFolder((string)$tokenRow['target_folder']);
                $stored = $uploadService->storeFile($tempPath, $fileName, $folder);
            } finally {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }

            $data = $uploadService->describeFile($stored['file']);
            if ($stored['deduplicated']) {
                $data['deduplicated'] = true;
            } elseif ($stored['file']->getName() !== basename($fileName)) {
                $data['renamedFrom'] = basename($fileName);
            }

            return $this->addCorsHeaders(new JsonResponse($data, 201), $request);
        } catch (InsufficientFolderAccessPermissionsException | InsufficientFolderWritePermissionsException $e) {
            return $this->jsonError('No permission for the target folder: ' . $e->getMessage(), 403, $request);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 400, $request);
        } catch (\Throwable $e) {
            // Log the details, but do not leak exception messages (paths etc.)
            GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__)
                ->error('Pre-signed upload failed: ' . $e->getMessage(), ['exception' => $e]);
            return $this->jsonError('Upload failed due to an unexpected server error (see TYPO3 log).', 500, $request);
        }
    }

    /**
     * Get the upload source: a multipart file upload when present (browser
     * forms, curl -F), otherwise the raw request body (curl -T / PUT).
     *
     * @return array{0: StreamInterface, 1: string|null} stream and optional client file name
     */
    protected function extractUpload(ServerRequestInterface $request): array
    {
        $uploadedFiles = $request->getUploadedFiles();
        if (!empty($uploadedFiles)) {
            $first = reset($uploadedFiles);
            if (is_array($first)) {
                $first = reset($first);
            }
            if ($first instanceof \Psr\Http\Message\UploadedFileInterface) {
                $clientName = $first->getClientFilename();
                return [$first->getStream(), $clientName !== null ? basename($clientName) : null];
            }
        }
        return [$request->getBody(), null];
    }

    /**
     * Stream the upload into a temp file, enforcing the size limit.
     */
    protected function bufferToTempFile(StreamInterface $body, int $maxBytes): string
    {
        $tempPath = GeneralUtility::tempnam('mcp_upload_');
        $handle = fopen($tempPath, 'wb');
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
            throw new \InvalidArgumentException('The request body was empty - send the raw file bytes as PUT/POST body.');
        }
        return $tempPath;
    }

    protected function jsonError(string $message, int $status, ServerRequestInterface $request): ResponseInterface
    {
        return $this->addCorsHeaders(new JsonResponse(['error' => $message], $status), $request);
    }

    /**
     * Set up the TYPO3 backend user context for the token's user
     * (same pattern as McpEndpoint's token authentication).
     */
    protected function setupBackendUserContext(int $userId): void
    {
        $userData = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->select(['*'], 'be_users', ['uid' => $userId, 'deleted' => 0])
            ->fetchAssociative();

        $now = time();
        if (!$userData
            || !empty($userData['disable'])
            || ((int)($userData['starttime'] ?? 0) > 0 && (int)$userData['starttime'] > $now)
            || ((int)($userData['endtime'] ?? 0) > 0 && (int)$userData['endtime'] < $now)
        ) {
            throw new \InvalidArgumentException('The backend user this upload token belongs to is not available.');
        }

        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $beUser->user = $userData;
        $GLOBALS['BE_USER'] = $beUser;
        $beUser->initializeUserSessionManager();
        $beUser->fetchGroupData();

        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($beUser);

        // Keep the Context API in sync with $GLOBALS['BE_USER'] (same as
        // McpEndpoint): event listeners on the FAL add events may rely on it.
        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('backend.user', new UserAspect($beUser));
        $context->setAspect('workspace', new WorkspaceAspect($beUser->workspace));

        // Ensure TCA is loaded (the middleware runs before the usual TCA bootstrap)
        if (empty($GLOBALS['TCA'])) {
            $GLOBALS['TCA'] = GeneralUtility::getContainer()->get(TcaFactory::class)->get();
        }
    }
}
