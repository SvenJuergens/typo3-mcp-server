<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Http\OAuthTokenEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tests for CORS header security
 */
class CorsHeadersTest extends AbstractFunctionalTest
{
    private mixed $previousRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    public function testCorsReflectsRequestOriginNotWildcard(): void
    {
        $endpoint = new OAuthTokenEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp_oauth/token'),
            'OPTIONS',
            'php://input',
            ['Origin' => 'https://my-mcp-client.example.com']
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        $origin = $response->getHeaderLine('Access-Control-Allow-Origin');
        $this->assertNotEquals('*', $origin, 'CORS must NOT use wildcard origin');
        $this->assertEquals('https://my-mcp-client.example.com', $origin);
    }

    public function testCorsWithoutOriginHeaderSkipsHeaders(): void
    {
        $endpoint = new OAuthTokenEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp_oauth/token'),
            'OPTIONS'
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        $this->assertFalse(
            $response->hasHeader('Access-Control-Allow-Origin'),
            'No CORS headers should be set for non-CORS requests'
        );
    }

    /**
     * The authenticated /mcp data response must carry CORS headers, just like
     * the error responses in the same class already do. Without this, browser-
     * and Electron-based MCP clients have the successful response blocked by
     * the browser before the application ever sees it. See issue #105.
     */
    public function testMcpSuccessResponseIncludesCorsHeaders(): void
    {
        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $tokenData = $oauthService->createToken(1, 'cors-test-client');

        $endpoint = new McpEndpoint();

        $request = new ServerRequest(
            new Uri('https://example.com/mcp'),
            'POST',
            'php://input',
            [
                'Origin' => 'https://claude.ai',
                'Authorization' => 'Bearer ' . $tokenData['access_token'],
                'Content-Type' => 'application/json',
            ]
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $endpoint($request);

        // Regardless of the JSON-RPC payload the MCP runner produces, the HTTP
        // response for an authenticated, CORS request must reflect the Origin.
        $this->assertTrue(
            $response->hasHeader('Access-Control-Allow-Origin'),
            'Authenticated /mcp response must include CORS headers'
        );
        $this->assertEquals(
            'https://claude.ai',
            $response->getHeaderLine('Access-Control-Allow-Origin'),
            'CORS origin must reflect the request Origin, not a wildcard'
        );
    }
}
