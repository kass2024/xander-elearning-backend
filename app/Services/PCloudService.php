<?php

namespace App\Services;

use App\Support\MaterialFileHelper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PCloudService
{
    protected string $baseUrl;

    protected ?string $accessToken;

    protected string $rootFolder;

    protected ?int $rootFolderId = null;

    protected ?string $resolvedApiHost = null;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.pcloud.base_url', 'https://api.pcloud.com'), '/');
        $this->accessToken = self::normalizeAccessToken(
            config('services.pcloud.access_token') ?: env('PCLOUD_ACCESS_TOKEN')
        );
        $this->rootFolder = trim((string) config('services.pcloud.root_folder', 'parrotacademy'), '/');

        $rootId = config('services.pcloud.root_folder_id') ?: env('PCLOUD_ROOT_FOLDER_ID', 31887143130);
        if (is_numeric($rootId) && (int) $rootId > 0) {
            $this->rootFolderId = (int) $rootId;
        }
    }

    public static function normalizeAccessToken(mixed $token): ?string
    {
        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);
        if ($token === '') {
            return null;
        }

        // Strip wrapping quotes and UTF-8 BOM (common cPanel .env copy/paste issues).
        $token = preg_replace('/^\xEF\xBB\xBF/', '', $token) ?? $token;
        $token = trim($token, " \t\n\r\0\x0B\"'");

        return $token !== '' ? $token : null;
    }

    /**
     * Detect US (api.pcloud.com) vs EU (eapi.pcloud.com) for this token.
     */
    public function resolveApiHost(): string
    {
        if ($this->resolvedApiHost) {
            return $this->resolvedApiHost;
        }

        $this->assertConfigured();

        $hosts = array_values(array_unique(array_filter([
            $this->baseUrl,
            'https://api.pcloud.com',
            'https://eapi.pcloud.com',
        ])));

        foreach ($hosts as $host) {
            $host = rtrim($host, '/');
            try {
                $response = Http::timeout(25)->get($host . '/userinfo', [
                    'access_token' => $this->accessToken,
                ]);
                $json = $response->json();
                if (is_array($json) && ($json['result'] ?? 1) === 0) {
                    $this->resolvedApiHost = $host;
                    $this->baseUrl = $host;

                    return $host;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        throw new \RuntimeException(
            'Invalid pCloud access token on this server. Set PCLOUD_ACCESS_TOKEN in cPanel .env to the same value as local, then run: php artisan config:clear && php artisan config:cache'
        );
    }

    /**
     * @return array{configured: bool, ok: bool, api_host?: string, root_folder_id?: int|null, email?: string|null, message?: string}
     */
    public function status(): array
    {
        if (!$this->isConfigured()) {
            return [
                'configured' => false,
                'ok' => false,
                'message' => 'PCLOUD_ACCESS_TOKEN is missing. Add it to the server .env file.',
            ];
        }

        try {
            $host = $this->resolveApiHost();
            $info = $this->request('GET', '/userinfo');
            $uploadTest = $this->testUpload();

            return [
                'configured' => true,
                'ok' => ($uploadTest['ok'] ?? false),
                'api_host' => $host,
                'root_folder_id' => $this->rootFolderId,
                'token_length' => strlen((string) $this->accessToken),
                'email' => is_array($info) ? ($info['email'] ?? null) : null,
                'upload_test' => $uploadTest,
                'upload_implementation' => 'curl-bearer-v4',
            ];
        } catch (\Throwable $e) {
            return [
                'configured' => true,
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok: bool, method?: string, error?: string}
     */
    public function testUpload(): array
    {
        if (!$this->rootFolderId) {
            return ['ok' => false, 'error' => 'PCLOUD_ROOT_FOLDER_ID is not configured'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pcloud_test_');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'Unable to create temp file'];
        }

        file_put_contents($tmp, 'pcloud upload health check');
        $params = ['folderid' => $this->rootFolderId, 'nopartial' => 1];
        $errors = [];

        try {
            foreach (['uploadViaCurl' => 'cURL', 'uploadViaPut' => 'PUT', 'uploadViaMultipartPost' => 'POST'] as $method => $label) {
                try {
                    $response = $this->{$method}($params, $tmp, 'pcloud-health-check.txt');
                    $meta = $response['metadata'][0] ?? $response['metadata'] ?? null;
                    $fileId = is_array($meta) ? (int) ($meta['fileid'] ?? 0) : 0;
                    if ($fileId > 0) {
                        try {
                            $this->deleteFile($fileId);
                        } catch (\Throwable) {
                            // ignore cleanup failure
                        }
                    }

                    return ['ok' => true, 'method' => $label];
                } catch (\Throwable $e) {
                    $errors[] = $label . ': ' . $e->getMessage();
                }
            }

            return ['ok' => false, 'error' => implode(' | ', $errors)];
        } finally {
            @unlink($tmp);
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->accessToken);
    }

    public function rootFolderId(): ?int
    {
        return $this->rootFolderId;
    }

    public function courseFolderPath(int $courseId): string
    {
        if ($this->rootFolderId) {
            return '/course-' . $courseId;
        }

        return '/' . $this->rootFolder . '/course-' . $courseId;
    }

    /**
     * Config for browser → pCloud direct upload (file never touches cPanel disk).
     *
     * @return array{upload_url: string, folderid: int, access_token: string, folder_path: string}
     */
    public function directUploadConfig(int $courseId): array
    {
        $folder = $this->ensureCourseFolder($courseId);

        return [
            'upload_mode' => 'api',
            'upload_url' => $this->uploadBaseUrl() . '/uploadfile',
            'folderid' => (int) $folder['folderid'],
            'root_folderid' => $this->rootFolderId,
            'folder_path' => (string) $folder['path'],
        ];
    }

    public function fileInCourseFolder(int $courseId, int $fileId): bool
    {
        $this->assertConfigured();
        $courseFolder = $this->ensureCourseFolder($courseId);
        $courseFolderId = (int) ($courseFolder['folderid'] ?? 0);

        $response = $this->request('GET', '/stat', ['fileid' => $fileId]);

        if (($response['result'] ?? 1) !== 0) {
            return false;
        }

        $meta = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $parentFolderId = (int) ($meta['parentfolderid'] ?? 0);

        if ($courseFolderId > 0 && $parentFolderId === $courseFolderId) {
            return true;
        }

        $path = (string) ($meta['path'] ?? '');
        $prefix = $this->courseFolderPath($courseId);

        return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/');
    }

    public function uploadBaseUrl(): string
    {
        $configured = config('services.pcloud.upload_base_url');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        return $this->resolveApiHost();
    }

    /**
     * @return list<string>
     */
    protected function uploadEndpointCandidates(): array
    {
        return [$this->uploadBaseUrl()];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function uploadQueryParams(array $params, string $filename): array
    {
        unset($params['access_token'], $params['auth'], $params['filename']);

        return array_merge($params, [
            'access_token' => $this->accessToken,
            'filename' => $filename,
            'renameifexists' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    protected function uploadAuthHeaders(): array
    {
        return ['Authorization: Bearer ' . $this->accessToken];
    }

    /**
     * Copy Laravel temp upload to a stable path (some hosts invalidate getRealPath() before cURL runs).
     */
    protected function stableUploadPath(UploadedFile $file): string
    {
        $real = $file->getRealPath();
        if (is_string($real) && $real !== '' && is_readable($real)) {
            return $real;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pcloud_upload_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temp file for pCloud upload');
        }

        $src = fopen($real ?: $file->getPathname(), 'rb');
        if ($src === false) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to read uploaded file');
        }

        $dest = fopen($tmp, 'wb');
        if ($dest === false) {
            fclose($src);
            @unlink($tmp);
            throw new \RuntimeException('Unable to prepare temp file for pCloud upload');
        }

        try {
            stream_copy_to_stream($src, $dest);
        } finally {
            fclose($src);
            fclose($dest);
        }

        register_shutdown_function(static fn () => @unlink($tmp));

        return $tmp;
    }

    /**
     * cURL POST — auth in query string, file only in body (matches working userinfo GET pattern).
     * Most reliable on cPanel where mod_security may strip access_token from POST fields.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function uploadViaCurl(array $params, string $filePath, string $filename): array
    {
        if (!function_exists('curl_init') || !function_exists('curl_file_create')) {
            throw new \RuntimeException('PHP cURL extension is required for pCloud uploads');
        }

        unset($params['access_token'], $params['filename']);

        $url = $this->uploadBaseUrl() . '/uploadfile?' . http_build_query(
            $this->uploadQueryParams($params, $filename),
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $mime = @mime_content_type($filePath) ?: MaterialFileHelper::mimeFromFilename($filename);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file' => curl_file_create($filePath, $mime, $filename),
            ],
            CURLOPT_HTTPHEADER => $this->uploadAuthHeaders(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new \RuntimeException('cURL upload to pCloud failed: ' . ($error ?: 'unknown cURL error'));
        }

        return $this->parseUploadJson((string) $body, 'cURL POST upload to pCloud failed (HTTP ' . $httpCode . ')');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function uploadChunkViaCurl(array $params, string $chunk, string $filename): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is required for pCloud uploads');
        }

        unset($params['access_token'], $params['filename']);

        $query = array_merge($params, [
            'access_token' => $this->accessToken,
            'filename' => $filename,
            'renameifexists' => 1,
        ]);

        $url = $this->uploadBaseUrl() . '/uploadfile?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $tmp = tempnam(sys_get_temp_dir(), 'pcloud_chunk_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temp chunk file');
        }

        file_put_contents($tmp, $chunk);

        try {
            return $this->uploadViaCurl($params, $tmp, $filename);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * PUT upload — auth in query string (same as userinfo GET).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function uploadViaPut(array $params, string $filePath, string $filename): array
    {
        unset($params['access_token'], $params['filename']);

        $url = $this->uploadBaseUrl() . '/uploadfile?' . http_build_query(
            $this->uploadQueryParams($params, $filename),
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read uploaded file');
        }

        try {
            $response = Http::timeout(3600)
                ->connectTimeout(60)
                ->withHeaders([
                    'Content-Type' => 'application/octet-stream',
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ])
                ->send('PUT', $url, ['body' => $handle]);
        } finally {
            fclose($handle);
        }

        return $this->parseUploadResponse($response, 'PUT upload to pCloud failed');
    }

    /**
     * POST multipart — parameters MUST come before the file (pCloud requirement).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function uploadViaMultipartPost(array $params, string $filePath, string $filename): array
    {
        unset($params['access_token'], $params['auth'], $params['filename']);

        $url = $this->uploadBaseUrl() . '/uploadfile?' . http_build_query(
            $this->uploadQueryParams($params, $filename),
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read uploaded file');
        }

        $multipart = [
            [
                'name' => 'file',
                'contents' => $handle,
                'filename' => $filename,
            ],
        ];

        try {
            $response = Http::timeout(3600)
                ->connectTimeout(60)
                ->withHeaders(['Authorization' => 'Bearer ' . $this->accessToken])
                ->send('POST', $url, ['multipart' => $multipart]);
        } finally {
            fclose($handle);
        }

        return $this->parseUploadResponse($response, 'POST upload to pCloud failed');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function postUploadFile(array $params, string $filePath, string $filename): array
    {
        $errors = [];

        foreach (['uploadViaCurl', 'uploadViaPut', 'uploadViaMultipartPost'] as $method) {
            try {
                return $this->{$method}($params, $filePath, $filename);
            } catch (\Throwable $e) {
                $errors[] = $method . ': ' . $e->getMessage();
                Log::warning('pCloud upload attempt failed', ['method' => $method, 'error' => $e->getMessage()]);
            }
        }

        throw new \RuntimeException('Upload to pCloud failed: ' . implode(' | ', $errors));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function postUploadChunk(array $params, string $chunk, string $filename): array
    {
        try {
            return $this->uploadChunkViaCurl($params, $chunk, $filename);
        } catch (\Throwable $curlError) {
            Log::warning('pCloud chunk curl upload failed', ['error' => $curlError->getMessage()]);
        }

        $url = $this->uploadBaseUrl() . '/uploadfile?' . http_build_query(
            array_merge($params, [
                'access_token' => $this->accessToken,
                'filename' => $filename,
                'renameifexists' => 1,
            ]),
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $multipart = [
            ['name' => 'file', 'contents' => $chunk, 'filename' => $filename],
        ];

        $response = Http::timeout(600)
            ->connectTimeout(60)
            ->send('POST', $url, ['multipart' => $multipart]);

        return $this->parseUploadResponse($response, 'Chunk upload to pCloud failed');
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseUploadJson(string $body, string $context): array
    {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            Log::warning('pCloud upload invalid JSON', ['context' => $context, 'body' => substr($body, 0, 500)]);
            throw new \RuntimeException($context . ': invalid JSON response');
        }

        if (($json['result'] ?? 1) !== 0) {
            $error = (string) ($json['error'] ?? 'Unknown pCloud error');
            throw new \RuntimeException($context . ': ' . $error);
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseUploadResponse(\Illuminate\Http\Client\Response $response, string $context): array
    {
        if ($response->failed()) {
            Log::warning('pCloud upload HTTP failure', [
                'context' => $context,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException($context . ': ' . ($response->body() ?: 'HTTP ' . $response->status()));
        }

        return $this->parseUploadJson((string) $response->body(), $context);
    }

    /**
     * @return array{folderid: int, path: string}
     */
    public function ensureCourseFolder(int $courseId): array
    {
        $this->assertConfigured();

        if ($this->rootFolderId) {
            return $this->ensureCourseFolderUnderRootId($courseId);
        }

        $rootPath = '/' . $this->rootFolder;
        $this->ensureFolderPath($rootPath);

        $coursePath = '/' . $this->rootFolder . '/course-' . $courseId;
        $folder = $this->ensureFolderPath($coursePath);

        return [
            'folderid' => (int) ($folder['folderid'] ?? 0),
            'path' => $coursePath,
        ];
    }

    /**
     * @return array{folderid: int, path: string}
     */
    protected function ensureCourseFolderUnderRootId(int $courseId): array
    {
        $name = 'course-' . $courseId;

        $response = $this->request('GET', '/createfolderifnotexists', [
            'folderid' => $this->rootFolderId,
            'name' => $name,
        ]);

        if (($response['result'] ?? 1) !== 0) {
            $response = $this->request('GET', '/createfolder', [
                'folderid' => $this->rootFolderId,
                'name' => $name,
            ]);
        }

        $this->assertOk($response, 'Unable to create course folder in pCloud');

        $meta = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];

        return [
            'folderid' => (int) ($meta['folderid'] ?? $meta['id'] ?? 0),
            'path' => (string) ($meta['path'] ?? ('/course-' . $courseId)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCourseFiles(int $courseId): array
    {
        $this->assertConfigured();
        $folder = $this->ensureCourseFolder($courseId);
        $folderId = (int) ($folder['folderid'] ?? 0);

        $response = $this->request('GET', '/listfolder', ['folderid' => $folderId]);

        if (($response['result'] ?? 1) === 2005) {
            $this->ensureCourseFolder($courseId);

            return [];
        }

        $this->assertOk($response, 'Unable to list course files');

        $files = [];
        foreach (($response['metadata']['contents'] ?? []) as $item) {
            if (!empty($item['isfolder'])) {
                continue;
            }
            $files[] = $this->normalizeFile($item);
        }

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadToCourse(int $courseId, UploadedFile $file): array
    {
        $folder = $this->ensureCourseFolder($courseId);
        $folderId = (int) $folder['folderid'];
        $size = (int) $file->getSize();

        if ($size >= 50 * 1024 * 1024) {
            return $this->uploadLargeFile($folderId, $file);
        }

        return $this->uploadSingleFile($folderId, $file, $size >= 20 * 1024 * 1024);
    }

    /**
     * Stream a pCloud file through the app (inline preview for PDFs, etc.).
     */
    public function streamFileResponse(int $fileId, string $filename, ?string $contentType = null, bool $inline = true): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->assertConfigured();
        $url = $this->downloadLink($fileId);
        $mime = $contentType ?: MaterialFileHelper::mimeFromFilename($filename);
        $disposition = ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $filename) . '"';

        $response = Http::timeout(600)
            ->withOptions(['stream' => true])
            ->get($url);

        if ($response->failed()) {
            throw new \RuntimeException('Unable to fetch file from pCloud');
        }

        return response()->stream(function () use ($response) {
            $body = $response->toPsrResponse()->getBody();
            while (!$body->eof()) {
                echo $body->read(1024 * 256);
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, $response->status(), array_filter([
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, max-age=3600',
            'Content-Length' => $response->header('Content-Length'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function uploadSingleFile(int $folderId, UploadedFile $file, bool $allowPartial = false): array
    {
        $params = [
            'folderid' => $folderId,
            'renameifexists' => 1,
        ];

        if (!$allowPartial) {
            $params['nopartial'] = 1;
        }

        $uploadPath = $this->stableUploadPath($file);
        $copied = $uploadPath !== $file->getRealPath();

        try {
            $response = $this->postUploadFile($params, $uploadPath, $file->getClientOriginalName());
        } finally {
            if ($copied && is_file($uploadPath)) {
                @unlink($uploadPath);
            }
        }

        $this->assertOk($response, 'pCloud upload');

        $meta = $response['metadata'][0] ?? $response['metadata'] ?? null;
        if (!is_array($meta)) {
            throw new \RuntimeException('pCloud upload returned no file metadata');
        }

        return $this->normalizeFile($meta);
    }

    /**
     * Chunked upload for large files (50 MB+) via pCloud partial upload API.
     *
     * @return array<string, mixed>
     */
    protected function uploadLargeFile(int $folderId, UploadedFile $file): array
    {
        $chunkSize = 10 * 1024 * 1024;
        $path = $this->stableUploadPath($file);
        $copied = $path !== $file->getRealPath();
        $totalSize = (int) $file->getSize();
        $filename = $file->getClientOriginalName();
        $uploadId = null;
        $offset = 0;
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('Unable to read uploaded file');
        }

        $response = null;

        try {
            while ($offset < $totalSize) {
                $readSize = (int) min($chunkSize, $totalSize - $offset);
                $chunk = fread($handle, $readSize);
                if ($chunk === false || $chunk === '') {
                    throw new \RuntimeException('Failed to read upload chunk');
                }

                $params = [
                    'folderid' => $folderId,
                    'uploadoffset' => $offset,
                    'renameifexists' => 1,
                ];

                if ($uploadId !== null) {
                    $params['uploadid'] = $uploadId;
                }

                $isFinal = ($offset + strlen($chunk)) >= $totalSize;
                $attachName = $isFinal ? $filename : 'chunk.bin';

                $response = $this->postUploadChunk($params, $chunk, $attachName);

                $this->assertOk($response, 'Chunk upload to pCloud failed');

                if (!empty($response['uploadid'])) {
                    $uploadId = (int) $response['uploadid'];
                }

                $offset += strlen($chunk);

                if (!empty($response['metadata'])) {
                    break;
                }
            }
        } finally {
            fclose($handle);
            if ($copied && is_file($path)) {
                @unlink($path);
            }
        }

        $meta = $response['metadata'][0] ?? $response['metadata'] ?? null;
        if (!is_array($meta)) {
            throw new \RuntimeException('pCloud chunked upload returned no file metadata');
        }

        return $this->normalizeFile($meta);
    }

    public function deleteFile(int $fileId): void
    {
        $this->assertConfigured();
        $response = $this->request('GET', '/deletefile', ['fileid' => $fileId]);
        $this->assertOk($response, 'Unable to delete pCloud file');
    }

    public function downloadLink(int $fileId): string
    {
        $response = $this->request('GET', '/getfilelink', ['fileid' => $fileId]);
        $this->assertOk($response, 'Unable to resolve download link');

        return (string) ($response['link'] ?? $response['metadata']['link'] ?? '');
    }

    public function videoLink(int $fileId): string
    {
        $response = $this->request('GET', '/getvideolink', [
            'fileid' => $fileId,
            'stream' => 1,
        ]);
        $this->assertOk($response, 'Unable to resolve video link');

        return (string) ($response['link'] ?? $response['metadata']['link'] ?? '');
    }

    public function thumbnailUrl(int $fileId, string $size = '800x800'): string
    {
        $this->assertConfigured();

        return $this->baseUrl . '/getthumb?' . http_build_query([
            'fileid' => $fileId,
            'access_token' => $this->accessToken,
            'size' => $size,
            'type' => 'auto',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ensureFolderPath(string $path): array
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return [];
        }

        $response = $this->request('GET', '/listfolder', ['path' => $path]);

        if (($response['result'] ?? 1) === 0) {
            return is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        }

        $parent = dirname($path);
        if ($parent !== '/' && $parent !== '.' && $parent !== $path) {
            $this->ensureFolderPath($parent);
        }

        $response = $this->request('GET', '/createfolderifnotexists', ['path' => $path]);
        if (($response['result'] ?? 1) !== 0) {
            $response = $this->request('GET', '/createfolder', ['path' => $path]);
        }
        $this->assertOk($response, 'Unable to create pCloud folder: ' . $path);

        return is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function request(string $method, string $endpoint, array $params = []): array
    {
        $params['access_token'] = $this->accessToken;
        $url = $this->resolveApiHost() . $endpoint;

        $response = $method === 'GET'
            ? Http::get($url, $params)
            : Http::asForm()->post($url, $params);

        if ($response->failed()) {
            Log::warning('pCloud HTTP failure', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['result' => $response->status(), 'error' => 'pCloud HTTP error'];
        }

        return $response->json() ?? ['result' => 1, 'error' => 'Invalid pCloud response'];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function assertOk(array $response, string $message): void
    {
        if (($response['result'] ?? 1) !== 0) {
            throw new \RuntimeException($message . ': ' . ($response['error'] ?? 'Unknown pCloud error'));
        }
    }

    protected function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('pCloud is not configured. Set PCLOUD_ACCESS_TOKEN in .env');
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function normalizeFile(array $item): array
    {
        return [
            'fileid' => (int) ($item['fileid'] ?? $item['id'] ?? 0),
            'name' => (string) ($item['name'] ?? 'file'),
            'size' => (int) ($item['size'] ?? 0),
            'created' => $item['created'] ?? null,
            'contenttype' => $item['contenttype'] ?? null,
        ];
    }
}
