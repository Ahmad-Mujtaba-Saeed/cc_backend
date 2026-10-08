<?php

namespace Modules\Mcp\Server;

use Illuminate\Support\Facades\Log;
use Modules\Mcp\Support\Guides;
use Modules\Mcp\Tools\VideoStudioTools;

/**
 * The Model Context Protocol, server side — JSON-RPC 2.0 over the Streamable
 * HTTP transport, stateless.
 *
 * Every request is answered with a single JSON body (the transport allows a
 * plain `application/json` response instead of an SSE stream), which is all a
 * request/response tool server needs and keeps this behind an ordinary PHP-FPM
 * pool: no long-lived connections, no session table. Long work (rendering)
 * is a queued job the model polls with a tool.
 *
 * Methods: initialize, ping, tools/list, tools/call, resources/list,
 * resources/read, resources/templates/list, prompts/list, prompts/get, and the
 * notifications (accepted and ignored).
 */
final class McpServer
{
    /** Newest first: the first one is what we answer an unknown request with. */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private VideoStudioTools $tools, private McpContext $ctx)
    {
    }

    /**
     * Handle one decoded JSON-RPC message (or a batch). Returns the response
     * body to send, or null when nothing should be sent back (notifications
     * and client responses only).
     */
    public function handle(mixed $message): ?array
    {
        if (is_array($message) && array_is_list($message)) {
            if ($message === []) {
                return self::error(null, -32600, 'Invalid Request: empty batch');
            }
            $out = [];
            foreach ($message as $one) {
                $response = $this->handleOne($one);
                if ($response !== null) {
                    $out[] = $response;
                }
            }

            return $out === [] ? null : $out;
        }

        return $this->handleOne($message);
    }

    private function handleOne(mixed $msg): ?array
    {
        if (!is_array($msg) || ($msg['jsonrpc'] ?? null) !== '2.0') {
            return self::error(null, -32600, 'Invalid Request');
        }

        $hasId = array_key_exists('id', $msg);
        $id = $msg['id'] ?? null;

        // A response or error from the client to something we never asked:
        // nothing to do (this server sends no requests of its own).
        if (!isset($msg['method'])) {
            return null;
        }

        $method = (string) $msg['method'];
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        // Notifications get no response, whatever they are.
        if (!$hasId) {
            return null;
        }
        if ($id !== null && !is_string($id) && !is_int($id)) {
            return self::error(null, -32600, 'Invalid Request: id must be a string or number');
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $this->tools->definitions()],
                'tools/call' => $this->callTool($params),
                'resources/list' => ['resources' => Guides::resources()],
                'resources/templates/list' => ['resourceTemplates' => []],
                'resources/read' => $this->readResource($params),
                'prompts/list' => ['prompts' => $this->prompts()],
                'prompts/get' => $this->getPrompt($params),
                default => null,
            };
        } catch (ProtocolError $e) {
            return self::error($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('MCP: method crashed', ['method' => $method, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return self::error($id, -32603, 'Internal error');
        }

        if ($result === null) {
            return self::error($id, -32601, "Method not found: {$method}");
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');
        $version = in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];

        $client = trim((string) (($params['clientInfo']['name'] ?? '') . ' ' . ($params['clientInfo']['version'] ?? '')));
        if ($client !== '' && $this->ctx->token) {
            $this->ctx->token->forceFill(['last_client' => mb_substr($client, 0, 120)])->saveQuietly();
            if ($this->ctx->token->grant_id) {
                \Modules\Mcp\Models\McpOAuthGrant::whereKey($this->ctx->token->grant_id)->update(['last_client' => mb_substr($client, 0, 120)]);
            }
        }

        return [
            'protocolVersion' => $version,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => (string) config('mcp.server_name'),
                'title' => (string) config('mcp.server_title'),
                'version' => (string) config('mcp.server_version'),
            ],
            'instructions' => Guides::instructions(),
        ];
    }

    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        if (!$this->tools->has($name)) {
            throw new ProtocolError("Unknown tool: {$name}", -32602);
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            $arguments = [];
        }

        try {
            $result = $this->tools->call($name, $arguments, $this->ctx);
        } catch (ToolException $e) {
            $result = ToolResult::error($e->getMessage(), $e->details());
        } catch (\Throwable $e) {
            Log::error('MCP: tool crashed', [
                'tool' => $name,
                'user_id' => $this->ctx->user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $result = ToolResult::error("The {$name} tool hit an internal error. Try again; if it keeps failing, continue with another step and tell the user.");
        }

        return $result->toArray();
    }

    private function readResource(array $params): array
    {
        $uri = (string) ($params['uri'] ?? '');
        $text = Guides::readUri($uri);
        if ($text === null) {
            throw new ProtocolError("Resource not found: {$uri}", -32002);
        }

        return ['contents' => [['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => $text]]];
    }

    private function prompts(): array
    {
        return [[
            'name' => 'make_explainer_video',
            'title' => 'Make an explainer video from a script',
            'description' => 'Turn a script into a finished, custom-animated explainer video (up to 15 minutes).',
            'arguments' => [
                ['name' => 'script', 'description' => 'The script / voice-over text', 'required' => true],
                ['name' => 'notes', 'description' => 'Anything about the look, audience or tone', 'required' => false],
            ],
        ], [
            'name' => 'make_presenter_video',
            'title' => 'Add motion graphics to my talking-head recording',
            'description' => 'Upload a video of yourself speaking the script; get it back cut with motion graphics.',
            'arguments' => [
                ['name' => 'script', 'description' => 'The script you spoke (optional — the recording is transcribed anyway)', 'required' => false],
            ],
        ]];
    }

    private function getPrompt(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $text = match ($name) {
            'make_explainer_video' => "Make me an explainer video with the Vreato Video Studio tools from the script below.\n"
                . "Read get_guide(workflow) and get_guide(scene_code) first. Ask me which voice (list_voices), the aspect ratio and the music before you create the video. "
                . "Design every scene yourself as custom animated code, preview each one and fix what the frames show, then render at 60 fps and give me the link.\n\n"
                . (trim((string) ($args['notes'] ?? '')) !== '' ? 'Notes: ' . $args['notes'] . "\n\n" : '')
                . "SCRIPT:\n" . (string) ($args['script'] ?? ''),
            'make_presenter_video' => "I recorded myself speaking a script. Use the Vreato Video Studio tools to turn it into a video with motion graphics: "
                . "read get_guide(workflow) and get_guide(presenter), create a presenter video, give me the upload link, wait until my recording is transcribed, "
                . "then design the motion graphics along my real words — sometimes show me full screen, sometimes small in a corner, sometimes only the graphics — preview, render and give me the link."
                . (trim((string) ($args['script'] ?? '')) !== '' ? "\n\nThe script I spoke:\n" . $args['script'] : ''),
            default => throw new ProtocolError("Prompt not found: {$name}", -32602),
        };

        return [
            'description' => $name === 'make_presenter_video' ? 'Motion graphics over your own recording' : 'Explainer video from a script',
            'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]],
        ];
    }

    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
