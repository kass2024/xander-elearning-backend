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

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.pcloud.base_url', 'https://api.pcloud.com'), '/');
        $token = config('services.pcloud.access_token') ?: env('PCLOUD_ACCESS_TOKEN');
        $this->accessToken = is_string($token) && $token !== ''
            ? trim($token, " \t\n\r\0\x0B\"'")
            : null;
        $this->rootFolder = trim((string) config('services.pcloud.root_folder', 'parrotacademy'), '/');
    }

    public function isConfigured(): bool
    {
        return !empty($this->accessToken);
    }

    public function courseFolderPath(int $courseId): string
    {
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
            'upload_url' => $this->uploadBaseUrl() . '/uploadfile',
            'folderid' => (int) $folder['folderid'],
            'access_token' => (string) $this->accessToken,
            'folder_path' => (string) $folder['path'],
        ];
    }

    public function fileInCourseFolder(int $courseId, int $fileId): bool
    {
        $this->assertConfigured();
        $response = $this->request('GET', '/stat', ['fileid' => $fileId]);

        if (($response['result'] ?? 1) !== 0) {
            return false;
        }

        $path = (string) ($response['metadata']['path'] ?? '');
        $prefix = $this->courseFolderPath($courseId);

        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    public function uploadBaseUrl(): string
    {
        return 'https://upload.pcloud.com';
    }

    /**
     * @return array{folderid: int, path: string}
     */
    public function ensureCourseFolder(int $courseId): array
    {
        $this->assertConfigured();

        $rootPath = '/' . $this->rootFolder;
        $this->ensureFolderPath($rootPath);

        $coursePath = $this->courseFolderPath($courseId);
        $folder = $this->ensureFolderPath($coursePath);

        return [
            'folderid' => (int) ($folder['folderid'] ?? 0),
            'path' => $coursePath,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCourseFiles(int $courseId): array
    {
        $this->assertConfigured();
        $path = $this->courseFolderPath($courseId);
        $response = $this->request('GET', '/listfolder', ['path' => $path]);

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
            'access_token' => $this->accessToken,
            'renameifexists' => 1,
        ];

        if (!$allowPartial) {
            $params['nopartial'] = 1;
        }

        $response = Http::timeout(3600)
            ->connectTimeout(60)
            ->asMultipart()
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post($this->uploadBaseUrl() . '/uploadfile', $params)
            ->json();

        $this->assertOk($response, 'Upload to pCloud failed');

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
        $path = $file->getRealPath();
        $totalSize = (int) $file->getSize();
        $filename = $file->getClientOriginalName();
        $uploadUrl = $this->uploadBaseUrl() . '/uploadfile';
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
                    'access_token' => $this->accessToken,
                    'uploadoffset' => $offset,
                    'renameifexists' => 1,
                ];

                if ($uploadId !== null) {
                    $params['uploadid'] = $uploadId;
                }

                $isFinal = ($offset + strlen($chunk)) >= $totalSize;
                $attachName = $isFinal ? $filename : 'chunk.bin';

                $response = Http::timeout(600)
                    ->connectTimeout(60)
                    ->asMultipart()
                    ->attach('file', $chunk, $attachName)
                    ->post($uploadUrl, $params)
                    ->json();

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
        $url = $this->baseUrl . $endpoint;

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
