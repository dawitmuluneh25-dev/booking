<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        private readonly array $headers,
        private readonly string $body,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers, $body);
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $body = '{"error":"encoding_failed"}';
            $status = 500;
        }

        return new self($status, ['Content-Type' => 'application/json; charset=UTF-8'], $body);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, ['Location' => $location], '');
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=UTF-8'], $body);
    }

    /**
     * A private file handed to one authorised reader.
     *
     * `attachment` by default: the default is what a browser does with a type it
     * does not recognise, and choosing inline for a PDF is an explicit decision by
     * the caller rather than something a stored document gets to make for itself
     * (SEC 14.3). `no-store` keeps the bytes out of every cache between here and
     * the clinician's screen.
     */
    public static function download(
        string $bytes,
        string $mimeType,
        string $downloadName,
        bool $inline = false,
    ): self {
        $name = self::safeFileName($downloadName);

        return new self(200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => self::disposition($inline, $name),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ], $bytes);
    }

    /**
     * Strip everything that could terminate the header or escape the quotes.
     *
     * The display name comes from the uploader, so it is untrusted input that
     * happens to look like a filename.
     */
    private static function safeFileName(string $name): string
    {
        $name = str_replace(['"', '\\'], '', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return $name === '' ? 'document' : mb_substr($name, 0, 190);
    }

    private static function disposition(bool $inline, string $name): string
    {
        $type = $inline ? 'inline' : 'attachment';

        // ASCII fallback plus RFC 5987 form, so a name with non-Latin characters
        // survives without putting raw bytes into a header.
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'document';
        $ascii = str_replace('"', '', $ascii);

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $ascii, rawurlencode($name));
    }

    /**
     * The same response without the body, for HEAD.
     *
     * Headers are kept as they are, including any Content-Length a download set:
     * RFC 9110 §9.3.2 wants HEAD to describe the GET response, and a monitor that
     * reads Content-Length should see the real size rather than zero.
     */
    public function withoutBody(): self
    {
        return new self($this->status, $this->headers, '');
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->status, $headers, $this->body);
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->body;
        }
    }
}
