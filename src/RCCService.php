<?php
declare(strict_types=1);

namespace Roblox\RCC;

/**
 * Universal PHP interface for the 2016 RCCService SOAP API.
 *
 * This is a backend library, not a web panel.
 *
 * It intentionally uses raw SOAP over HTTP instead of PHP's SoapClient:
 * - the 2016 RCC WSDL has no endpoint address;
 * - the RCC port is normally configured separately;
 * - this works cleanly inside an existing PHP revival backend.
 */
final class RCCException extends \RuntimeException {}

final class RCCService
{
    private string $endpoint;
    private float $timeout;
    private bool $soap12;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 64989,
        float $timeout = 60.0,
        bool $soap12 = false
    ) {
        $scheme = str_starts_with($host, 'https://') ? '' : 'http://';
        $host = preg_replace('#^https?://#', '', $host);
        $this->endpoint = $scheme . $host . ':' . $port . '/';
        $this->timeout = $timeout;
        $this->soap12 = $soap12;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function helloWorld(): ?string
    {
        return $this->call('HelloWorld')['HelloWorldResult'] ?? null;
    }

    public function getVersion(): string
    {
        return (string)($this->call('GetVersion')['GetVersionResult'] ?? '');
    }

    public function getStatus(): array
    {
        $r = $this->call('GetStatus')['GetStatusResult'] ?? [];
        return is_array($r) ? $r : [];
    }

    /**
     * OpenJob executes a script in a persistent DataModel/job.
     */
    public function openJob(
        string $jobId,
        string $script,
        float $expirationInSeconds = 300.0,
        int $category = 0,
        float $cores = 1.0,
        string $name = 'RCC'
    ): array {
        return $this->call('OpenJob', [
            'job' => [
                'id' => $jobId,
                'expirationInSeconds' => $expirationInSeconds,
                'category' => $category,
                'cores' => $cores,
            ],
            'script' => [
                'name' => $name,
                'script' => $script,
                'arguments' => [],
            ],
        ]);
    }

    public function openJobEx(
        string $jobId,
        string $script,
        array $arguments = [],
        float $expirationInSeconds = 300.0,
        int $category = 0,
        float $cores = 1.0,
        string $name = 'RCC'
    ): array {
        $r = $this->call('OpenJobEx', [
            'job' => [
                'id' => $jobId,
                'expirationInSeconds' => $expirationInSeconds,
                'category' => $category,
                'cores' => $cores,
            ],
            'script' => [
                'name' => $name,
                'script' => $script,
                'arguments' => $this->luaArguments($arguments),
            ],
        ]);

        return $this->unwrapLuaResult($r, 'OpenJobExResult');
    }

    public function execute(
        string $jobId,
        string $script,
        array $arguments = [],
        string $name = 'RCC'
    ): array {
        $r = $this->call('Execute', [
            'jobID' => $jobId,
            'script' => [
                'name' => $name,
                'script' => $script,
                'arguments' => $this->luaArguments($arguments),
            ],
        ]);

        return $this->unwrapLuaResult($r, 'ExecuteResult');
    }

    public function executeEx(
        string $jobId,
        string $script,
        array $arguments = [],
        string $name = 'RCC'
    ): array {
        $r = $this->call('ExecuteEx', [
            'jobID' => $jobId,
            'script' => [
                'name' => $name,
                'script' => $script,
                'arguments' => $this->luaArguments($arguments),
            ],
        ]);

        return $this->unwrapLuaResult($r, 'ExecuteExResult');
    }

    public function closeJob(string $jobId): void
    {
        $this->call('CloseJob', ['jobID' => $jobId]);
    }

    public function renewLease(string $jobId, float $expirationInSeconds): float
    {
        return (float)($this->call('RenewLease', [
            'jobID' => $jobId,
            'expirationInSeconds' => $expirationInSeconds,
        ])['RenewLeaseResult'] ?? 0);
    }

    public function getExpiration(string $jobId): float
    {
        return (float)($this->call('GetExpiration', [
            'jobID' => $jobId,
        ])['GetExpirationResult'] ?? 0);
    }

    public function getAllJobs(): array
    {
        $r = $this->call('GetAllJobs');
        $jobs = $r['GetAllJobsResult'] ?? [];
        return $this->normalizeList($jobs);
    }

    public function getAllJobsEx(): array
    {
        $r = $this->call('GetAllJobsEx');
        return $this->normalizeList($r['GetAllJobsExResult'] ?? []);
    }

    public function closeExpiredJobs(): int
    {
        return (int)($this->call('CloseExpiredJobs')['CloseExpiredJobsResult'] ?? 0);
    }

    public function closeAllJobs(): int
    {
        return (int)($this->call('CloseAllJobs')['CloseAllJobsResult'] ?? 0);
    }

    public function diag(int $type, ?string $jobId = null): array
    {
        $args = ['type' => $type];
        if ($jobId !== null) {
            $args['jobID'] = $jobId;
        }
        return $this->unwrapLuaResult(
            $this->call('Diag', $args),
            'DiagResult'
        );
    }

    public function diagEx(int $type, ?string $jobId = null): array
    {
        $args = ['type' => $type];
        if ($jobId !== null) {
            $args['jobID'] = $jobId;
        }
        return $this->unwrapLuaResult(
            $this->call('DiagEx', $args),
            'DiagExResult'
        );
    }

    /**
     * Run a synchronous BatchJob. RCC creates a temporary DataModel
     * and closes it when the script finishes.
     */
    public function batchJob(
        string $jobId,
        string $script,
        array $arguments = [],
        float $expirationInSeconds = 300.0,
        int $category = 0,
        float $cores = 1.0,
        string $name = 'RCC'
    ): array {
        $r = $this->call('BatchJob', [
            'job' => [
                'id' => $jobId,
                'expirationInSeconds' => $expirationInSeconds,
                'category' => $category,
                'cores' => $cores,
            ],
            'script' => [
                'name' => $name,
                'script' => $script,
                'arguments' => $this->luaArguments($arguments),
            ],
        ]);

        return $this->unwrapLuaResult($r, 'BatchJobResult');
    }

    public function batchJobEx(
        string $jobId,
        string $script,
        array $arguments = [],
        float $expirationInSeconds = 300.0,
        int $category = 0,
        float $cores = 1.0,
        string $name = 'RCC'
    ): array {
        $r = $this->call('BatchJobEx', [
            'job' => [
                'id' => $jobId,
                'expirationInSeconds' => $expirationInSeconds,
                'category' => $category,
                'cores' => $cores,
            ],
            'script' => [
                'name' => $name,
                'script' => $script,
                'arguments' => $this->luaArguments($arguments),
            ],
        ]);

        return $this->unwrapLuaResult($r, 'BatchJobExResult');
    }

    /**
     * Return true when RCC is reachable.
     */
    public function isAlive(): bool
    {
        try {
            $this->helloWorld();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Find a running job by its exact ID.
     * Uses GetAllJobsEx, which exposes Job records in this RCC build.
     */
    public function getJob(string $jobId): ?array
    {
        foreach ($this->getAllJobsEx() as $job) {
            if (is_array($job) && (($job['id'] ?? null) === $jobId)) {
                return $job;
            }
        }

        return null;
    }

    public function jobExists(string $jobId): bool
    {
        return $this->getJob($jobId) !== null;
    }

    /**
     * Close a job only when it currently exists.
     */
    public function closeJobIfExists(string $jobId): bool
    {
        if (!$this->jobExists($jobId)) {
            return false;
        }

        $this->closeJob($jobId);
        return true;
    }

    /**
     * Renew a job and return its new expiration value when available.
     */
    public function renewJob(string $jobId, float $expirationInSeconds = 300.0): float
    {
        return $this->renewLease($jobId, $expirationInSeconds);
    }

    /**
     * Get jobs optionally filtered by category.
     */
    public function getJobs(?int $category = null): array
    {
        $jobs = $this->getAllJobsEx();

        if ($category === null) {
            return $jobs;
        }

        return array_values(array_filter(
            $jobs,
            static fn($job) => is_array($job)
                && isset($job['category'])
                && (int)$job['category'] === $category
        ));
    }

    /**
     * Return a small backend-friendly RCC health snapshot.
     */
    public function health(): array
    {
        $version = $this->getVersion();
        $status = $this->getStatus();

        return [
            'online' => true,
            'endpoint' => $this->endpoint(),
            'version' => $version,
            'environmentCount' => (int)($status['environmentCount'] ?? 0),
        ];
    }

    /**
     * Diagnostics constants used by the 2016 RCC implementation.
     *
     * The source uses a bitmask:
     *   1 = leak dump
     *   2 = allocation test
     *   4 = performance/diagnostic data
     *
     * Use only for debugging/maintenance, not per-player requests.
     */
    public const DIAG_LEAK_DUMP = 1;
    public const DIAG_ALLOCATION_TEST = 2;
    public const DIAG_PERFORMANCE = 4;

    public function diagnose(int $flags = self::DIAG_PERFORMANCE, ?string $jobId = null): array
    {
        return $this->diagEx($flags, $jobId);
    }

    /**
     * Render a raw texture through the 2016 ThumbnailGenerator:ClickTexture.
     *
     * $textureId is the ContentId understood by the RCC ContentProvider.
     */
    public function renderTextureThumbnail(
        string $textureId,
        int $width = 420,
        int $height = 420,
        string $format = 'PNG'
    ): string {
        $jobId = 'texture-thumbnail-' . bin2hex(random_bytes(12));

        $lua = 'return game:GetService("ThumbnailGenerator"):ClickTexture('
            . $this->luaString($textureId) . ', '
            . $this->luaString(strtoupper($format)) . ', '
            . $width . ', '
            . $height . ')';

        try {
            $result = $this->openJobEx(
                $jobId,
                $lua,
                [],
                300.0,
                0,
                1.0,
                'TextureThumbnail'
            );

            return $this->firstLuaString($result);
        } finally {
            try {
                $this->closeJob($jobId);
            } catch (\Throwable $ignored) {}
        }
    }

    /**
     * Execute a script in a one-shot temporary RCC environment.
     */
    public function run(
        string $script,
        array $arguments = [],
        string $name = 'RCC',
        float $expirationInSeconds = 300.0
    ): array {
        return $this->batchJobEx(
            'batch-' . bin2hex(random_bytes(12)),
            $script,
            $arguments,
            $expirationInSeconds,
            0,
            1.0,
            $name
        );
    }

    /**
     * Start a persistent job if it does not already exist.
     * Returns whether this call created the job.
     */
    public function ensureJob(
        string $jobId,
        string $script,
        array $arguments = [],
        float $expirationInSeconds = 300.0,
        int $category = 0,
        float $cores = 1.0,
        string $name = 'RCC'
    ): bool {
        if ($this->jobExists($jobId)) {
            return false;
        }

        $this->openJobEx(
            $jobId,
            $script,
            $arguments,
            $expirationInSeconds,
            $category,
            $cores,
            $name
        );

        return true;
    }

    /**
     * Render a place through the 2016 ThumbnailGenerator.
     *
     * The uploaded 2016 source binds:
     * Click(fileType, width, height, hideSky, crop).
     *
     * The returned value is the Base64 image produced by RCC.
     */
    public function renderPlaceThumbnail(
        int $placeId,
        string $baseUrl,
        int $width = 420,
        int $height = 420,
        bool $hideSky = false,
        bool $crop = true,
        string $format = 'PNG'
    ): string {
        $baseUrl = rtrim($baseUrl, '/');
        $jobId = 'thumbnail-' . bin2hex(random_bytes(12));

        $lua = <<<LUA
game:GetService("ContentProvider"):SetBaseUrl({$this->luaString($baseUrl)})
game:GetService("ScriptContext").ScriptsDisabled = true
game:Load({$this->luaString($baseUrl . '/asset/?id=' . $placeId)})
return game:GetService("ThumbnailGenerator"):Click(
    {$this->luaString(strtoupper($format))},
    {$width},
    {$height},
    {$this->luaBool($hideSky)},
    {$this->luaBool($crop)}
)
LUA;

        try {
            $result = $this->openJobEx(
                $jobId,
                $lua,
                [],
                300.0,
                0,
                1.0,
                'PlaceThumbnail'
            );

            return $this->firstLuaString($result);
        } finally {
            try {
                $this->closeJob($jobId);
            } catch (\Throwable $ignored) {
                // Preserve the original render exception/result.
            }
        }
    }

    /**
     * Generic thumbnail helper. Use this when the revival needs custom
     * avatar/model setup before Click().
     *
     * $scriptBody must return ThumbnailGenerator:Click(...).
     */
    public function renderWithScript(
        string $scriptBody,
        array $arguments = [],
        float $expirationInSeconds = 300.0,
        string $name = 'Thumbnail'
    ): string {
        $jobId = 'thumbnail-' . bin2hex(random_bytes(12));

        try {
            $result = $this->openJobEx(
                $jobId,
                $scriptBody,
                $arguments,
                $expirationInSeconds,
                0,
                1.0,
                $name
            );

            return $this->firstLuaString($result);
        } finally {
            try {
                $this->closeJob($jobId);
            } catch (\Throwable $ignored) {}
        }
    }

    /**
     * Start the 2016 gameserver script included with this RCC source.
     *
     * The script starts NetworkServer on $port and loads the place.
     * $gameserverScript should contain gameserver.txt.
     */
    public function startGameServer(
        string $jobId,
        int $placeId,
        int $port,
        string $baseUrl,
        string $gameserverScript,
        float $expirationInSeconds = 86400.0,
        int $category = 0,
        float $cores = 1.0
    ): array {
        $script = rtrim($gameserverScript) . "\n\n"
            . 'start('
            . $placeId . ', '
            . $port . ', '
            . $this->luaString(rtrim($baseUrl, '/'))
            . ")\n";

        return $this->openJobEx(
            $jobId,
            $script,
            [],
            $expirationInSeconds,
            $category,
            $cores,
            'GameServer'
        );
    }

    public function stopGameServer(string $jobId): void
    {
        $this->closeJob($jobId);
    }

    /**
     * Returns a Lua script for the 2016 ThumbnailGenerator.
     * Useful when the revival needs to add avatar assets or models itself.
     */
    public static function thumbnailClickScript(
        string $format = 'PNG',
        int $width = 420,
        int $height = 420,
        bool $hideSky = true,
        bool $crop = true
    ): string {
        return 'return game:GetService("ThumbnailGenerator"):Click('
            . self::luaStringStatic(strtoupper($format)) . ', '
            . $width . ', '
            . $height . ', '
            . ($hideSky ? 'true' : 'false') . ', '
            . ($crop ? 'true' : 'false') . ')';
    }

    public function call(string $operation, array $params = []): array
    {
        $xml = $this->buildEnvelope($operation, $params);
        $response = $this->http($xml, $operation);
        return $this->parseResponse($response, $operation);
    }

    private function buildEnvelope(string $operation, array $params): string
    {
        $soapNs = $this->soap12
            ? 'http://www.w3.org/2003/05/soap-envelope'
            : 'http://schemas.xmlsoap.org/soap/envelope/';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="' . $soapNs . '" '
            . 'xmlns:ns1="http://roblox.com/">'
            . '<soap:Body><ns1:' . $this->xmlName($operation) . '>';

        foreach ($params as $name => $value) {
            $xml .= $this->encodeElement((string)$name, $value);
        }

        return $xml . '</ns1:' . $this->xmlName($operation)
            . '></soap:Body></soap:Envelope>';
    }

    private function encodeElement(string $name, mixed $value): string
    {
        $name = $this->xmlName($name);

        if (is_array($value)) {
            $xml = '<ns1:' . $name . '>';

            // Arrays representing RCC's ArrayOfLuaValue.
            if ($name === 'arguments') {
                foreach ($value as $item) {
                    $xml .= $this->encodeLuaValue($item);
                }
            } else {
                foreach ($value as $key => $item) {
                    $xml .= $this->encodeElement((string)$key, $item);
                }
            }

            return $xml . '</ns1:' . $name . '>';
        }

        if ($value === null) {
            return '<ns1:' . $name . ' xsi:nil="true" '
                . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>';
        }

        return '<ns1:' . $name . '>'
            . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '</ns1:' . $name . '>';
    }

    private function encodeLuaValue(mixed $value): string
    {
        $type = 'LUA_TNIL';
        $scalar = null;
        $table = null;

        if (is_bool($value)) {
            $type = 'LUA_TBOOLEAN';
            $scalar = $value ? 'true' : 'false';
        } elseif (is_int($value) || is_float($value)) {
            $type = 'LUA_TNUMBER';
            $scalar = (string)$value;
        } elseif (is_string($value)) {
            $type = 'LUA_TSTRING';
            $scalar = $value;
        } elseif (is_array($value)) {
            $type = 'LUA_TTABLE';
            $table = $value;
        }

        $xml = '<ns1:LuaValue>'
            . '<ns1:type>' . $type . '</ns1:type>';

        if ($scalar !== null) {
            $xml .= '<ns1:value>'
                . htmlspecialchars($scalar, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '</ns1:value>';
        } elseif ($table !== null) {
            $xml .= '<ns1:table>';
            foreach ($table as $item) {
                $xml .= $this->encodeLuaValue($item);
            }
            $xml .= '</ns1:table>';
        }

        return $xml . '</ns1:LuaValue>';
    }

    private function http(string $xml, string $operation): string
    {
        $headers = [
            'Content-Type: ' . (
                $this->soap12
                    ? 'application/soap+xml; charset=utf-8; action="http://roblox.com/' . $operation . '"'
                    : 'text/xml; charset=utf-8'
            ),
            'Content-Length: ' . strlen($xml),
            'Connection: close',
        ];

        if (!$this->soap12) {
            $headers[] = 'SOAPAction: "http://roblox.com/' . $operation . '"';
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($this->endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $xml,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => (int)min($this->timeout * 1000, 10000),
                CURLOPT_TIMEOUT_MS => (int)($this->timeout * 1000),
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false) {
                throw new RCCException('RCC HTTP error: ' . $error);
            }
            if ($status >= 400) {
                throw new RCCException('RCC HTTP ' . $status . ': ' . trim($body));
            }
            return $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $xml,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($this->endpoint, false, $context);
        if ($body === false) {
            throw new RCCException('Unable to connect to RCC at ' . $this->endpoint);
        }

        return $body;
    }

    private function parseResponse(string $xml, string $operation): array
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;

        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RCCException('RCC returned invalid XML.');
        }

        $xp = new \DOMXPath($dom);

        $fault = $xp->query('//*[local-name()="Fault"]')->item(0);
        if ($fault instanceof \DOMElement) {
            $text = trim($fault->textContent);
            throw new RCCException('RCC SOAP fault: ' . $text);
        }

        $body = $xp->query('//*[local-name()="Body"]')->item(0);
        if (!$body instanceof \DOMElement) {
            throw new RCCException('RCC response has no SOAP Body.');
        }

        $responseNode = null;
        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $responseNode = $child;
                break;
            }
        }

        if (!$responseNode) {
            return [];
        }

        return $this->elementToArray($responseNode);
    }

    private function elementToArray(\DOMElement $element): mixed
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child;
            }
        }

        if (!$children) {
            return trim($element->textContent);
        }

        $out = [];
        foreach ($children as $child) {
            $key = $child->localName ?? $child->nodeName;
            $value = $this->elementToArray($child);

            if (array_key_exists($key, $out)) {
                if (!is_array($out[$key]) || !array_is_list($out[$key])) {
                    $out[$key] = [$out[$key]];
                }
                $out[$key][] = $value;
            } else {
                $out[$key] = $value;
            }
        }

        // Preserve LuaValue/table collections as arrays when there are
        // repeated LuaValue elements.
        return $out;
    }

    private function unwrapLuaResult(array $response, string $resultName): array
    {
        $value = $response[$resultName] ?? [];

        if ($value === '') {
            return [];
        }

        if (isset($value['LuaValue'])) {
            $value = $value['LuaValue'];
        }

        if (!is_array($value)) {
            return [$this->decodeLuaValue($value)];
        }

        if (array_is_list($value)) {
            return array_map(fn($v) => $this->decodeLuaValue($v), $value);
        }

        if (isset($value['type'])) {
            return [$this->decodeLuaValue($value)];
        }

        return $value;
    }

    private function decodeLuaValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $type = $value['type'] ?? null;
        switch ($type) {
            case 'LUA_TNIL':
                return null;
            case 'LUA_TBOOLEAN':
                return strtolower((string)($value['value'] ?? '')) === 'true';
            case 'LUA_TNUMBER':
                $n = (string)($value['value'] ?? '0');
                return str_contains($n, '.') || stripos($n, 'e') !== false
                    ? (float)$n : (int)$n;
            case 'LUA_TSTRING':
                return (string)($value['value'] ?? '');
            case 'LUA_TTABLE':
                $items = $value['table']['LuaValue'] ?? [];
                if (!is_array($items)) {
                    $items = [$items];
                }
                return array_map(fn($v) => $this->decodeLuaValue($v), $items);
            default:
                return $value;
        }
    }

    private function firstLuaString(array $values): string
    {
        foreach ($values as $value) {
            if (is_string($value)) {
                return $value;
            }
        }

        throw new RCCException('RCC script did not return a string.');
    }

    private function luaArguments(array $arguments): array
    {
        return array_map(fn($v) => $v, $arguments);
    }

    private function normalizeList(mixed $value): array
    {
        if ($value === '' || $value === null) {
            return [];
        }

        if (!is_array($value)) {
            return [$value];
        }

        foreach (['Job', 'GetAllJobsResult'] as $key) {
            if (isset($value[$key])) {
                $v = $value[$key];
                return is_array($v) && array_is_list($v) ? $v : [$v];
            }
        }

        return array_is_list($value) ? $value : [$value];
    }

    private function luaString(string $value): string
    {
        return self::luaStringStatic($value);
    }

    private static function luaStringStatic(string $value): string
    {
        return '"' . str_replace(
            ['\\', '"', "\r", "\n"],
            ['\\\\', '\\"', '\\r', '\\n'],
            $value
        ) . '"';
    }

    private function luaBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function xmlName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '', $name);
    }
}
