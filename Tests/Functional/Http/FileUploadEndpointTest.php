<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\FileUploadEndpoint;
use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Hn\McpServer\Service\FileUploadService;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests for the pre-signed upload endpoint (/mcp_upload).
 *
 * Flow under test: the UploadFile MCP tool (or FileUploadService directly)
 * mints a single-use token; the client PUTs raw file bytes to the endpoint;
 * the endpoint stores the file as the token's backend user and returns the
 * created sys_file as JSON.
 */
class FileUploadEndpointTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);

        // A site with a fully qualified base URL: without one (and without an
        // HTTP request context) pre-signed upload URLs cannot be built.
        $siteDir = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($siteDir);
        GeneralUtility::writeFile($siteDir . '/config.yaml', "rootPageId: 1\nbase: 'https://example.com/'\n", true);

        $this->setUpBackendUser(1);
    }

    /**
     * Create an upload token for the admin user, targeting /user_upload/.
     */
    protected function createToken(string $fileName = ''): string
    {
        $service = GeneralUtility::makeInstance(FileUploadService::class);
        $folder = $service->resolveTargetFolder('/user_upload/');
        return $service->createUploadToken($folder, $fileName)['token'];
    }

    protected function dispatchUpload(string $token, string $body, array $extraQuery = [], string $method = 'PUT', array $headers = []): \Psr\Http\Message\ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        $request = (new ServerRequest(
            new Uri('https://example.com/mcp_upload'),
            $method,
            $stream,
            $headers
        ))->withQueryParams(array_merge(['token' => $token], $extraQuery));

        return (new FileUploadEndpoint())($request);
    }

    protected function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    public function testUploadStoresFileAndReturnsJson(): void
    {
        $token = $this->createToken();
        $response = $this->dispatchUpload($token, $this->pngBytes(), ['fileName' => 'uploaded.png']);

        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());
        $data = json_decode((string)$response->getBody(), true);

        $this->assertEquals('uploaded.png', $data['fileName']);
        $this->assertEquals('1:/user_upload/uploaded.png', $data['identifier']);
        $this->assertGreaterThan(0, $data['uid']);

        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($data['uid']);
        $this->assertEquals($this->pngBytes(), $file->getContents());
    }

    public function testTokenIsSingleUse(): void
    {
        $token = $this->createToken();

        $first = $this->dispatchUpload($token, $this->pngBytes(), ['fileName' => 'once.png']);
        $this->assertEquals(201, $first->getStatusCode());

        $second = $this->dispatchUpload($token, $this->pngBytes(), ['fileName' => 'twice.png']);
        $this->assertEquals(401, $second->getStatusCode(), 'A used token must be rejected');
    }

    public function testInvalidTokenIsRejected(): void
    {
        $response = $this->dispatchUpload('not-a-real-token', $this->pngBytes(), ['fileName' => 'x.png']);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testFileNameFromTokenIsUsed(): void
    {
        $token = $this->createToken('preset-name.png');
        $response = $this->dispatchUpload($token, $this->pngBytes());

        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('preset-name.png', $data['fileName']);
    }

    public function testFileNameFromContentDispositionHeader(): void
    {
        $token = $this->createToken();
        $response = $this->dispatchUpload(
            $token,
            $this->pngBytes(),
            [],
            'PUT',
            ['Content-Disposition' => 'attachment; filename="from-header.png"']
        );

        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());
        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('from-header.png', $data['fileName']);
    }

    public function testMissingFileNameIsRejected(): void
    {
        $token = $this->createToken();
        $response = $this->dispatchUpload($token, $this->pngBytes());
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('file name', json_decode((string)$response->getBody(), true)['error']);
    }

    public function testEmptyBodyIsRejected(): void
    {
        $token = $this->createToken();
        $response = $this->dispatchUpload($token, '', ['fileName' => 'empty.png']);
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testFailedUploadConsumesTheToken(): void
    {
        // The token is consumed on the attempt, not on success: a leaked token
        // must be a single attempt, not a 15-minute upload permit with retries.
        $token = $this->createToken();

        $failed = $this->dispatchUpload($token, '', ['fileName' => 'empty.png']);
        $this->assertEquals(400, $failed->getStatusCode());

        $retry = $this->dispatchUpload($token, $this->pngBytes(), ['fileName' => 'retry.png']);
        $this->assertEquals(401, $retry->getStatusCode(), 'A token must not survive a failed upload attempt');
    }

    public function testGetMethodIsRejected(): void
    {
        $response = $this->dispatchUpload($this->createToken(), '', [], 'GET');
        $this->assertEquals(405, $response->getStatusCode());
    }

    /**
     * Full round trip: the UploadFile tool mints the URL, the endpoint consumes it.
     */
    public function testEndToEndFlowFromTool(): void
    {
        $result = GeneralUtility::makeInstance(UploadFileTool::class)->execute([
            'targetFolder' => '/user_upload/',
            'fileName' => 'roundtrip.png',
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $toolData = json_decode($result->content[0]->text, true);

        parse_str((string)parse_url($toolData['uploadUrl'], PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['token']);

        $response = $this->dispatchUpload($query['token'], $this->pngBytes());
        $this->assertEquals(201, $response->getStatusCode(), (string)$response->getBody());

        $data = json_decode((string)$response->getBody(), true);
        $this->assertEquals('roundtrip.png', $data['fileName'], 'File name preset in the tool call must be used');
        $this->assertEquals('1:/user_upload/roundtrip.png', $data['identifier']);
    }
}
