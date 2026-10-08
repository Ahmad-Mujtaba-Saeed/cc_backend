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

    public function imageCount(): int
    {
        return count(array_filter($this->content, fn ($c) => $c['type'] === 'image'));
    }

    /** Characters the client counts against its tool-result cap. */
    public function totalChars(): int
    {
        $n = 0;
        foreach ($this->content as $c) {
            $n += strlen($c['type'] === 'image' ? $c['data'] : $c['text']) + 64;
        }

        return $n;
    }

    /**
     * Keep the whole result under the client's cap (Claude.ai/Desktop drop a
     * result over ~150k characters; their images vanish without a word to the
     * model). Images go first — last one first — and the result SAYS so, so
     * the model never believes in pictures it did not get.
     */
    public function enforceBudget(int $maxChars): self
    {
        $dropped = 0;
        while ($this->totalChars() > $maxChars) {
            $idx = null;
            foreach ($this->content as $i => $c) {
                if ($c['type'] === 'image') {
                    $idx = $i;
                }
            }
            if ($idx === null) {
                break;
            }
            array_splice($this->content, $idx, 1);
            $dropped++;
        }
        if ($dropped > 0) {
            $this->content[] = ['type' => 'text', 'text' => "Note: {$dropped} image(s) were left out to stay under the client's tool-result size limit."];
        }
        // Text alone over the cap: trim the longest text block.
        while ($this->totalChars() > $maxChars) {
            $longest = null;
            foreach ($this->content as $i => $c) {
                if ($c['type'] === 'text' && ($longest === null || strlen($c['text']) > strlen($this->content[$longest]['text']))) {
                    $longest = $i;
                }
            }
            if ($longest === null) {
                break;
            }
            $over = $this->totalChars() - $maxChars;
            $keep = max(1000, strlen($this->content[$longest]['text']) - $over - 200);
            $this->content[$longest]['text'] = mb_strcut($this->content[$longest]['text'], 0, $keep) . "\n…(cut to fit the client's size limit — ask for a narrower range)";
            if ($keep === 1000) {
                break;
            }
        }

        return $this;
    }

    public function toArray(): array
    {
        return ['content' => $this->content, 'isError' => $this->isError];
    }
}
