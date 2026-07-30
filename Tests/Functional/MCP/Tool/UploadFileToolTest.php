<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests for the UploadFile MCP tool.
 *
 * HTTP downloads are intercepted by a Guzzle handler middleware registered via
 * $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'], so no real network access
 * happens. Test URLs use public-range IP literals (203.0.113.0/24, TEST-NET-3)
 * so the SSRF host validation passes without DNS resolution.
 */
class UploadFileToolTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_filemounts.csv');

        // Create the default "fileadmin" storage (uid 1) with a real directory
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin');
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', '', true);

        $this->setUpBackendUser(1);
    }

    /**
     * Register a fake HTTP layer. Map of url-prefix => Response; anything
     * unmatched gets a 404.
     *
     * @param array<string, Response> $responses
     */
    protected function mockHttpResponses(array $responses): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = [
            'mcp_test_mock' => function (callable $handler) use ($responses) {
                return function ($request, array $options) use ($responses) {
                    $url = (string)$request->getUri();
                    foreach ($responses as $prefix => $response) {
                        if (str_starts_with($url, $prefix)) {
                            return new FulfilledPromise($response);
                        }
                    }
                    return new FulfilledPromise(new Response(404, [], 'not mocked: ' . $url));
                };
            },
        ];
    }

    /**
     * A real (valid) 1x1 transparent PNG so TYPO3's resource consistency check
     * (content must match the file extension) passes.
     */
    protected function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    protected function executeUpload(array $params): array
    {
        $result = GeneralUtility::makeInstance(UploadFileTool::class)->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        return json_decode($result->content[0]->text, true);
    }

    protected function assertUploadError(array $params, string $messagePart): void
    {
        $result = GeneralUtility::makeInstance(UploadFileTool::class)->execute($params);
        $this->assertTrue($result->isError, 'Expected an error result, got: ' . json_encode($result->jsonSerialize()));
        $this->assertStringContainsStringIgnoringCase($messagePart, $result->content[0]->text);
    }

    // ---------------------------------------------------------------
    // content uploads
    // ---------------------------------------------------------------

    public function testContentUploadCreatesFile(): void
    {
        $text = "Some plain text content\nwith a second line.";
        $data = $this->executeUpload([
            'content' => $text,
            'fileName' => 'readme.txt',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertGreaterThan(0, $data['uid']);
        $this->assertEquals('readme.txt', $data['fileName']);
        $this->assertEquals('1:/user_upload/readme.txt', $data['identifier']);
        $this->assertArrayHasKey('nextStep', $data);

        // sys_file row exists and physical file has the content
        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($data['uid']);
        $this->assertEquals($text, $file->getContents());

        // metadata record was created by the indexer
        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->count('uid', 'sys_file_metadata', ['file' => $data['uid']]);
        $this->assertEquals(1, $count, 'A sys_file_metadata record should exist for the uploaded file');
    }

    public function testContentUploadIntoNestedFolderCreatesFolders(): void
    {
        $data = $this->executeUpload([
            'content' => "a;b;c\n1;2;3",
            'fileName' => 'table.csv',
            'targetFolder' => '/user_upload/reports/2026/',
        ]);

        $this->assertEquals('1:/user_upload/reports/2026/table.csv', $data['identifier']);
        $this->assertFileExists(Environment::getPublicPath() . '/fileadmin/user_upload/reports/2026/table.csv');
    }

    public function testContentUploadRequiresFileName(): void
    {
        $this->assertUploadError(['content' => 'hello'], 'fileName');
    }

    public function testUrlAndContentTogetherAreRejected(): void
    {
        $this->assertUploadError(
            ['url' => 'http://203.0.113.10/a.jpg', 'content' => 'x'],
            'only one'
        );
    }

    public function testNoSourceReturnsPresignedUploadUrl(): void
    {
        $data = $this->executeUpload(['targetFolder' => '/user_upload/']);

        $this->assertArrayHasKey('uploadUrl', $data);
        $this->assertStringContainsString('/mcp_upload?token=', $data['uploadUrl']);
        $this->assertEquals('1:/user_upload/', $data['targetFolder']);
        $this->assertArrayHasKey('validUntil', $data);
        $this->assertStringContainsString('curl', $data['instructions']);

        // A hashed single-use token was persisted
        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens')
            ->count('uid', 'tx_mcpserver_upload_tokens', ['used' => 0]);
        $this->assertEquals(1, $count);
    }

    public function testSchemaOffersDefaultTargetFolder(): void
    {
        $schema = GeneralUtility::makeInstance(UploadFileTool::class)->getSchema();
        $this->assertEquals(
            '1:/user_upload/',
            $schema['inputSchema']['properties']['targetFolder']['default'] ?? null,
            'The schema should advertise the default upload folder'
        );
    }

    public function testPhpFileIsRejected(): void
    {
        $this->assertUploadError(
            ['content' => '<?php echo 1;', 'fileName' => 'evil.php', 'targetFolder' => '/user_upload/'],
            'extension'
        );
    }

    public function testFileNameWithoutExtensionIsRejected(): void
    {
        $this->assertUploadError(
            ['content' => 'hello', 'fileName' => 'noextension', 'targetFolder' => '/user_upload/'],
            'extension'
        );
    }

    // ---------------------------------------------------------------
    // conflict handling: rename + dedupe
    // ---------------------------------------------------------------

    public function testDuplicateNameIsRenamedNotOverwritten(): void
    {
        $first = $this->executeUpload([
            'content' => 'first content',
            'fileName' => 'notes.txt',
            'targetFolder' => '/user_upload/',
        ]);
        $second = $this->executeUpload([
            'content' => 'different content',
            'fileName' => 'notes.txt',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertNotEquals($first['uid'], $second['uid']);
        $this->assertNotEquals('notes.txt', $second['fileName'], 'Second upload must not reuse the taken name');
        $this->assertEquals('notes.txt', $second['renamedFrom']);

        // The first file is untouched
        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($first['uid']);
        $this->assertEquals('first content', $file->getContents());
    }

    public function testIdenticalContentIsDeduplicated(): void
    {
        $first = $this->executeUpload([
            'content' => 'identical bytes',
            'fileName' => 'a.txt',
            'targetFolder' => '/user_upload/',
        ]);
        $second = $this->executeUpload([
            'content' => 'identical bytes',
            'fileName' => 'b.txt',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertEquals($first['uid'], $second['uid'], 'Identical content should return the existing file');
        $this->assertTrue($second['deduplicated']);

        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->count('uid', 'sys_file', ['storage' => 1]);
        $this->assertEquals(1, $count, 'No duplicate sys_file record should have been created');
    }

    // ---------------------------------------------------------------
    // url uploads
    // ---------------------------------------------------------------

    public function testUrlUploadDownloadsFile(): void
    {
        $bytes = $this->pngBytes();
        $this->mockHttpResponses([
            'http://203.0.113.10/images/photo.png' => new Response(200, ['Content-Type' => 'image/png'], $bytes),
        ]);

        $data = $this->executeUpload([
            'url' => 'http://203.0.113.10/images/photo.png',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertEquals('photo.png', $data['fileName']);
        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($data['uid']);
        $this->assertEquals($bytes, $file->getContents());
    }

    public function testUrlUploadFollowsRedirects(): void
    {
        $this->mockHttpResponses([
            'http://203.0.113.10/start' => new Response(302, ['Location' => '/moved/final.png']),
            'http://203.0.113.10/moved/final.png' => new Response(200, ['Content-Type' => 'image/png'], $this->pngBytes()),
        ]);

        $data = $this->executeUpload([
            'url' => 'http://203.0.113.10/start',
            'targetFolder' => '/user_upload/',
        ]);

        // File name is derived from the redirect target
        $this->assertEquals('final.png', $data['fileName']);
    }

    public function testUrlUploadDerivesExtensionFromContentType(): void
    {
        $this->mockHttpResponses([
            'http://203.0.113.10/image?id=42' => new Response(200, ['Content-Type' => 'image/png'], $this->pngBytes()),
        ]);

        $data = $this->executeUpload([
            'url' => 'http://203.0.113.10/image?id=42',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertStringEndsWith('.png', $data['fileName']);
    }

    public function testUrlUploadPrefersContentDispositionFileName(): void
    {
        $this->mockHttpResponses([
            'http://203.0.113.10/download?id=7' => new Response(200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'attachment; filename="pretty-name.png"',
            ], $this->pngBytes()),
        ]);

        $data = $this->executeUpload([
            'url' => 'http://203.0.113.10/download?id=7',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertEquals('pretty-name.png', $data['fileName']);
    }

    public function testUrlUploadFailsOnHttpError(): void
    {
        $this->mockHttpResponses([]);
        $this->assertUploadError(
            ['url' => 'http://203.0.113.10/missing.jpg', 'targetFolder' => '/user_upload/'],
            '404'
        );
    }

    public function testPrivateAddressesAreRejected(): void
    {
        // No mock registered on purpose: validation must fail before any request
        foreach (['http://127.0.0.1/x.jpg', 'http://192.168.1.5/x.jpg', 'http://10.0.0.8/x.jpg', 'http://169.254.169.254/latest'] as $url) {
            $this->assertUploadError(['url' => $url, 'targetFolder' => '/user_upload/'], 'private or reserved');
        }
    }

    public function testNonHttpSchemesAreRejected(): void
    {
        $this->assertUploadError(['url' => 'ftp://203.0.113.10/x.jpg', 'targetFolder' => '/user_upload/'], 'http');
        $this->assertUploadError(['url' => 'file:///etc/passwd', 'targetFolder' => '/user_upload/'], 'http');
    }

    // ---------------------------------------------------------------
    // online media (YouTube/Vimeo)
    // ---------------------------------------------------------------

    public function testYoutubeUrlCreatesOnlineMediaAsset(): void
    {
        // oEmbed title lookup is unmocked (404) so the file falls back to the video id
        $this->mockHttpResponses([]);

        $data = $this->executeUpload([
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertTrue($data['onlineMedia']);
        $this->assertStringEndsWith('.youtube', $data['fileName']);

        // The placeholder file contains just the video id
        $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($data['uid']);
        $this->assertEquals('dQw4w9WgXcQ', trim($file->getContents()));
    }

    public function testContentRewrittenDuringUploadIsStillDeduplicated(): void
    {
        // The core SVG sanitizer rewrites SVG content while it is stored, so the
        // hash of the upload differs from the hash of the stored file. The dedupe
        // must catch this via the post-store hash check.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';

        $first = $this->executeUpload([
            'content' => $svg,
            'fileName' => 'shape.svg',
            'targetFolder' => '/user_upload/',
        ]);
        $second = $this->executeUpload([
            'content' => $svg,
            'fileName' => 'shape.svg',
            'targetFolder' => '/user_upload/',
        ]);

        $storedSha1 = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select(['sha1'], 'sys_file', ['uid' => $first['uid']])
            ->fetchOne();
        $this->assertNotEquals(sha1($svg), $storedSha1, 'Precondition: the sanitizer must actually rewrite the SVG for this test to be meaningful');

        $this->assertEquals($first['uid'], $second['uid'], 'Identical (sanitizer-rewritten) content should return the existing file');
        $this->assertTrue($second['deduplicated'] ?? false);

        $count = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->count('uid', 'sys_file', ['storage' => 1]);
        $this->assertEquals(1, $count, 'No duplicate sys_file record should have been created');
    }

    public function testYoutubeUrlIsDeduplicated(): void
    {
        $this->mockHttpResponses([]);

        $first = $this->executeUpload([
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'targetFolder' => '/user_upload/',
        ]);
        $second = $this->executeUpload([
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
            'targetFolder' => '/user_upload/',
        ]);

        $this->assertEquals($first['uid'], $second['uid'], 'Same video should not create a second asset');
        $this->assertTrue($second['deduplicated']);
    }

    // ---------------------------------------------------------------
    // default folder + permissions
    // ---------------------------------------------------------------

    public function testUploadWithoutTargetFolderUsesDefaultUploadFolder(): void
    {
        $data = $this->executeUpload([
            'content' => 'default folder upload',
            'fileName' => 'default.txt',
        ]);

        $this->assertGreaterThan(0, $data['uid']);
        $this->assertStringStartsWith('1:/', $data['identifier']);
    }

    public function testNonAdminCanUploadIntoMountedFolder(): void
    {
        // The mounted folder must exist for the file mount to be resolvable
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/user_upload');

        $uid = $this->createNonAdminUserWithMounts('1'); // mount 1 = "1:/user_upload/"
        $this->authenticateUser($uid);

        $data = $this->executeUpload([
            'content' => 'editor upload',
            'fileName' => 'editor.txt',
            'targetFolder' => '/user_upload/',
        ]);
        $this->assertEquals('1:/user_upload/editor.txt', $data['identifier']);
    }

    public function testNonAdminCannotUploadOutsideMounts(): void
    {
        // Physically create /secret/ so the folder exists but is outside the mount
        GeneralUtility::mkdir_deep(Environment::getPublicPath() . '/fileadmin/secret');

        $uid = $this->createNonAdminUserWithMounts('1'); // only "1:/user_upload/"
        $this->authenticateUser($uid);

        $this->assertUploadError(
            ['content' => 'sneaky', 'fileName' => 'sneaky.txt', 'targetFolder' => '/secret/'],
            'permission'
        );
    }

    // ---------------------------------------------------------------
    // helpers for non-admin users (mirrors FileMountPermissionTest)
    // ---------------------------------------------------------------

    protected function createNonAdminUserWithMounts(string $fileMountUids): int
    {
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'pid' => 0,
            'username' => 'editor_' . uniqid(),
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'file_mountpoints' => $fileMountUids,
            'deleted' => 0,
            'disable' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'email' => 'test' . uniqid() . '@example.com',
        ]);

        return (int)$connection->lastInsertId();
    }

    protected function authenticateUser(int $uid): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser($uid);
        $user->groupData['tables_select'] = 'sys_file,pages,tt_content';
        $user->groupData['tables_modify'] = 'sys_file,pages,tt_content';
        $user->groupData['filemounts'] = (string)($user->user['file_mountpoints'] ?? '');
        // FAL write operations check granular file permissions for non-admins
        $user->groupData['file_permissions'] = 'readFolder,writeFolder,addFolder,readFile,writeFile,addFile,copyFile,moveFile,renameFile';
        $user->user['admin'] = 0;
        $GLOBALS['BE_USER'] = $user;

        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();

        return $user;
    }
}
