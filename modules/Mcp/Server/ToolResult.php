<?php

namespace Modules\Mcp\Server;

/**
 * What a tool hands back: MCP `content` blocks plus the isError flag.
 *
 * Tool failures the model can act on (a refused scene, a missing upload) are
 * RESULTS with isError=true — the spec's way of letting the model read the
 * reason and retry — never JSON-RPC protocol errors.
 */
final class ToolResult
{
    /** @var array<int, array<string, mixed>> */
    private array $content = [];

    private bool $isError = false;

    public static function text(string $text): self
    {
        $r = new self();
        $r->content[] = ['type' => 'text', 'text' => $text];

        return $r;
    }

    /**
     * A JSON payload as text (every client shows text; not every client reads
     * structuredContent), optionally led by one plain sentence.
     */
    public static function json(array $data, ?string $lead = null): self
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return self::text(($lead !== null ? $lead . "\n\n" : '') . $json);
    }

    public static function error(string $message, array $details = []): self
    {
        $r = $details === [] ? self::text($message) : self::json($details, $message);
        $r->isError = true;

        return $r;
    }

    public function addText(string $text): self
    {
        $this->content[] = ['type' => 'text', 'text' => $text];

        return $this;
    }

    public function addImage(string $binary, string $mime = 'image/jpeg'): self
    {
        $this->content[] = ['type' => 'image', 'data' => base64_encode($binary), 'mimeType' => $mime];

        return $this;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function toArray(): array
    {
        return ['content' => $this->content, 'isError' => $this->isError];
    }
}
