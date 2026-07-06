# LLM Tests for MCP Tools

This directory contains tests that use actual Language Models to verify that the MCP tools are intuitive and work as expected when used by LLMs.

## Purpose

These tests help ensure that:
1. Tool schemas are clear and understandable to LLMs
2. Tool descriptions alone are sufficient for correct tool usage (no hints needed)
3. LLMs can successfully complete common tasks using the tools
4. Tool behavior matches realistic user expectations
5. The tools work together effectively for complex workflows

## Running the Tests

### Prerequisites

1. Set an API key for one of the supported gateways. Both expose an
   OpenAI-compatible API and let you reach many models with a single key.
   When both are set, **Cortecs is preferred**.

   **Cortecs** (https://cortecs.ai) — EU-native gateway with Zero Data
   Retention. Requests are routed only to ZDR-compliant EU providers.

   ```bash
   export CORTECS_API_KEY="..."
   ```

   **OpenRouter** (https://openrouter.ai) — broadest model catalog, incl.
   proprietary OpenAI GPT models.

   ```bash
   export OPENROUTER_API_KEY="sk-or-v1-..."
   ```

   Or create a `.env.local` file:
   ```bash
   CORTECS_API_KEY="..."
   # or
   OPENROUTER_API_KEY="sk-or-v1-..."
   ```

2. Run the LLM tests:
   ```bash
   composer test:llm
   ```

3. Run a specific test with a filter:
   ```bash
   composer test:llm -- --filter testLlmFixesHeaderSpellingErrors
   ```

### Supported Models

Tests using `#[DataProvider('modelProvider')]` run once per model. Both
providers expose the same logical keys, mapped to provider-specific model IDs.
`modelProvider()` picks the map for the active provider, so if the sets ever
diverge each provider only advertises models it can actually serve.

| Key                  | Cortecs Model ID     | OpenRouter Model ID             |
|----------------------|----------------------|---------------------------------|
| `haiku-4.5`          | `claude-haiku-4-5`   | `anthropic/claude-haiku-4.5`    |
| `gpt-oss-120b`       | `gpt-oss-120b`       | `openai/gpt-oss-120b`           |
| `minimax-m3`         | `minimax-m3`         | `minimax/minimax-m3`            |
| `mistral-large-2512` | `mistral-large-2512` | `mistralai/mistral-large-2512`  |
| `gemini-3-flash`     | `gemini-3.5-flash`   | `google/gemini-3-flash-preview` |

**No proprietary GPT:** Cortecs' catalog contains no proprietary OpenAI models
(`gpt-4`/`gpt-5`/o-series) — with or without ZDR. The only OpenAI models it
offers are the open-weight `gpt-oss-*` series, served by EU providers
(Scaleway, Nebius, OVH, IONOS, …), not Azure. Proprietary GPT is therefore not
part of the test matrix. Proprietary Anthropic (Claude, via AWS Bedrock),
Google (Gemini) and Mistral models *are* available.

**Reasoning parameter caveat:** On Cortecs, Claude is served via AWS Bedrock,
which rejects the OpenRouter-style `reasoning` object (HTTP 400). So the
extended-thinking boost configured for Haiku on OpenRouter is not applied on
Cortecs. The `gpt-oss` series does accept `reasoning.effort`.

### Cost Considerations

These tests make actual API calls and will incur costs:
- Each test typically makes 2-5 API calls (due to multi-step conversations)
- Multi-model tests multiply the cost by the number of models
- Using Claude 3.5 Haiku (default) is cost-effective
- Tests are excluded from the default test suite to avoid unexpected charges
- Consider using environment-specific keys to track costs
- Use `--filter` to run specific tests when iterating

### Test Configuration

The tests use:
- **Temperature**: 0 (for deterministic responses)
- **Default Model**: Haiku 4.5 (`claude-haiku-4-5` on Cortecs / `anthropic/claude-haiku-4.5` on OpenRouter)
- **Max Tokens**: 4000 per call
- **Provider**: Cortecs (preferred) or OpenRouter, selected by which API key is set

## Writing New Tests

### Basic Principles

1. **Extend `LlmTestCase`** for access to helper methods and proper setup
2. **Write realistic prompts** - avoid hints, IDs, or implementation details
3. **Expect exploration** - LLMs typically check context before acting
4. **Test real tool execution** - use multi-step conversations
5. **Assert tool failures properly** - WriteTable errors should fail the test

### Key Methods

- `callLlm($prompt)` - Send initial prompt with all MCP tools available
- `setModel($key)` - Set the model for subsequent calls (e.g., `'haiku'`, `'gpt-5.2'`)
- `assertToolCalled($response, $toolName, $expectedParams)` - Verify tool usage
- `executeToolCall($toolCall)` - Execute a tool and get results
- `continueWithToolResult($previousResponse, $toolResult)` - Continue conversation
- `assertFollowsPattern($response, $patterns)` - Assert flexible tool sequences
- `modelProvider()` - Data provider for multi-model tests

### Example: Simple Page Creation

```php
public function testLlmCreatesPage(): void
{
    // Step 1: Send realistic prompt (no IDs or hints!)
    $prompt = "Create a new page called 'Services' under the home page";
    $response1 = $this->callLlm($prompt);
    
    // Step 2: Expect exploration first
    $this->assertToolCalled($response1, 'GetPageTree');
    
    // Step 3: Execute the tool call
    $treeResult = $this->executeToolCall($response1->getToolCalls()[0]);
    $this->assertFalse($treeResult['isError'] ?? false);
    
    // Step 4: Continue conversation with tree result
    $response2 = $this->continueWithToolResult($response1, $treeResult);
    
    // Step 5: Now expect page creation
    $this->assertToolCalled($response2, 'WriteTable', [
        'action' => 'create',
        'table' => 'pages',
        'data' => [
            'title' => 'Services'
        ]
    ]);
    
    // Step 6: Execute write and ensure it succeeds
    $writeResult = $this->executeToolCall($response2->getToolCalls()[0]);
    $this->assertFalse($writeResult['isError'] ?? false, 
        'WriteTable failed: ' . $writeResult['content']);
}
```

### Testing Patterns

1. **Exploration Pattern** - Most operations should explore first:
   ```php
   // Good: Expect GetPageTree → WriteTable
   // Bad: Expect immediate WriteTable without context
   ```

2. **Flexible Assertions** - Allow reasonable variations:
   ```php
   $this->assertContains($writeCall['data']['title'], 
       ['Products', 'Our Products'], 
       "Title could be interpreted either way");
   ```

3. **Error Handling** - Always assert tool success:
   ```php
   $this->assertFalse($result['isError'] ?? false, 
       'Tool failed: ' . $result['content']);
   ```

### Common Pitfalls to Avoid

1. **Don't give hints**: 
   - ❌ "Create page under ID 1"
   - ✅ "Create page under the home page"

2. **Don't force behavior**:
   - ❌ "Make sure to check the page tree first"
   - ✅ Let the LLM decide its approach

3. **Don't assume single tool calls**:
   - ❌ Expect everything in one response
   - ✅ Support multi-step conversations

4. **Don't ignore errors**:
   - ❌ Continue if WriteTable fails
   - ✅ Assert tool execution succeeds

## Multi-Model Testing

Tests can run against multiple LLM providers by using the `#[DataProvider]` attribute:

```php
use PHPUnit\Framework\Attributes\DataProvider;

#[DataProvider('modelProvider')]
public function testSomethingWithAllModels(string $modelKey): void
{
    // setModel() skips the test automatically if the model has no equivalent
    // on the active provider (e.g. proprietary GPT keys when running on Cortecs).
    $this->setModel($modelKey);

    // Test logic here...
}
```

`LlmTestCase` selects the client in `initializeLlmClient()`: `CortecsClient`
when `CORTECS_API_KEY` is set, otherwise `OpenRouterClient` when
`OPENROUTER_API_KEY` is set. Both talk the OpenAI-compatible API.

### Adding New Providers

To add a new provider:
1. Create a new client implementing `LlmClientInterface`
2. Add initialization logic in `LlmTestCase::initializeLlmClient()`
3. Add a provider-specific model map (see `MODELS` / `CORTECS_MODELS`) so the
   shared `modelProvider()` keys resolve to that provider's model IDs

## Handling Test Failures

LLM tests may fail due to:
1. **Model behavior changes**: LLMs evolve; update assertions if needed
2. **Non-determinism**: Despite temperature=0, some variation is possible
3. **Tool schema issues**: Failures may indicate unclear tool descriptions
4. **Missing extensions**: Ensure core extensions are loaded (workspaces, frontend)
5. **Authentication issues**: Backend user must be properly initialized

### Debugging Tips

- Use `getToolCallsDebugString()` to see what the LLM actually did
- Check for workspace errors - ensure `coreExtensionsToLoad` includes necessary extensions
- Verify backend user and language service are initialized in `setUp()`
- Enable debug output temporarily to see tool execution results
- Consider running a single test with `--filter` to isolate issues

## Architecture Notes

### Test Base Class Setup

The `LlmTestCase` base class handles:
- Loading core extensions (workspaces, frontend) and test extensions
- Setting up backend user authentication
- Initializing language service
- Loading `.env.local` for API keys
- Converting MCP tool schemas to OpenAI-compatible function format

### Conversation Continuation

The framework supports multi-step conversations:
1. Initial prompt → LLM response with tool calls
2. Execute tool calls → Get results
3. Continue conversation with results → Next LLM response
4. Repeat as needed for complex workflows

This mirrors real-world usage where LLMs explore, act, and verify.

## Future Enhancements

- Test result caching to reduce costs during development
- More complex multi-step workflow tests
- Performance benchmarks and comparison reports across models
- Cost tracking and reporting per test run
- Additional providers (Google Gemini, etc.)