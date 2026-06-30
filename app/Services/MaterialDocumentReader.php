<?php

namespace App\Services;

use App\Models\CourseMaterial;
use App\Support\MaterialFileHelper;
use App\Support\QuizMaterialHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;

class MaterialDocumentReader
{
    protected ?string $lastFetchError = null;

    public function __construct(protected PCloudService $pcloud)
    {
    }

    public function lastFetchError(): ?string
    {
        return $this->lastFetchError;
    }

    /**
     * @param  Collection<int, CourseMaterial>|array<int, CourseMaterial>  $materials
     */
    public function buildStudyExcerpt(Collection|array $materials, int $maxChars = 18000): string
    {
        $materials = $materials instanceof Collection ? $materials : collect($materials);
        $sections = [];

        foreach ($materials as $material) {
            if (!$material instanceof CourseMaterial) {
                continue;
            }

            $label = QuizMaterialHelper::extractTopicLabel($material)
                ?? ($material->title ?? 'Material');
            $text = $this->readMaterialText($material);

            if ($text === null || trim($text) === '') {
                continue;
            }

            $sections[] = "### {$label}\n" . trim($text);
        }

        if ($sections === []) {
            return '';
        }

        $combined = implode("\n\n", $sections);

        return Str::limit($combined, $maxChars, "\n\n[Material excerpt truncated for AI context length.]");
    }

    public function readMaterialText(CourseMaterial $material): ?string
    {
        $ttl = (int) config('services.quiz_ai.material_cache_ttl', 3600);
        if ($ttl <= 0) {
            return $this->readMaterialTextUncached($material);
        }

        $cacheKey = 'quiz_mat_text:' . $material->id . ':' . $this->materialCacheVersion($material);

        return $this->rememberCacheValue($cacheKey, $ttl, fn () => $this->readMaterialTextUncached($material));
    }

    protected function readMaterialTextUncached(CourseMaterial $material): ?string
    {
        $meta = QuizMaterialHelper::meta($material);
        $filename = (string) ($meta['filename'] ?? $material->title ?? 'file');

        if (!empty($meta['transcript']) && is_string($meta['transcript'])) {
            return $this->normalizeWhitespace($meta['transcript']);
        }

        if (!empty($meta['youtube_transcript']) && is_string($meta['youtube_transcript'])) {
            return $this->normalizeWhitespace($meta['youtube_transcript']);
        }

        $bytes = $this->fetchBytes($material);

        if ($bytes !== null && $bytes !== '') {
            $text = $this->extractTextFromBytes($bytes, $filename);
            if ($text !== null && trim($text) !== '') {
                return $this->normalizeWhitespace($text);
            }

            if ($this->shouldAttemptMediaTranscription($filename)) {
                $mediaText = $this->transcribeMediaBytes($bytes, $filename);
                if ($mediaText !== null && trim($mediaText) !== '') {
                    return $this->normalizeWhitespace($mediaText);
                }
            }
        }

        $description = trim((string) ($material->description ?? ''));
        if ($description !== '') {
            return $this->normalizeWhitespace($description);
        }

        return null;
    }

    public function fetchBytes(CourseMaterial $material): ?string
    {
        $ttl = (int) config('services.quiz_ai.material_cache_ttl', 3600);
        if ($ttl <= 0) {
            return $this->fetchBytesUncached($material);
        }

        $cacheKey = 'quiz_mat_bytes_b64:' . $material->id . ':' . $this->materialCacheVersion($material);

        $encoded = $this->rememberCacheValue($cacheKey, $ttl, function () use ($material) {
            $bytes = $this->fetchBytesUncached($material);

            return is_string($bytes) && $bytes !== '' ? base64_encode($bytes) : null;
        });

        if (!is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    /**
     * Cache helper — never let cache write failures break PDF reads (binary data breaks DB cache).
     *
     * @template T
     * @param  callable(): (T|null)  $callback
     * @return T|null
     */
    protected function rememberCacheValue(string $cacheKey, int $ttl, callable $callback): mixed
    {
        try {
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
        } catch (\Throwable $e) {
            Log::warning('Material cache read failed', ['key' => $cacheKey, 'error' => $e->getMessage()]);
        }

        $value = $callback();

        if ($value === null) {
            return null;
        }

        try {
            Cache::put($cacheKey, $value, $ttl);
        } catch (\Throwable $e) {
            Log::warning('Material cache write failed', ['key' => $cacheKey, 'error' => $e->getMessage()]);
        }

        return $value;
    }

    protected function fetchBytesUncached(CourseMaterial $material): ?string
    {
        $this->lastFetchError = null;
        $meta = QuizMaterialHelper::meta($material);
        $fileId = MaterialFileHelper::pcloudFileId($meta);

        try {
            if ($fileId && $this->pcloud->isConfigured()) {
                $url = $this->pcloud->downloadLink($fileId);
                $response = Http::timeout(60)->connectTimeout(15)->get($url);
                if ($response->successful()) {
                    return $response->body();
                }

                $this->lastFetchError = 'pCloud download failed (HTTP ' . $response->status() . ') for file #' . $fileId;
                Log::warning('Material download failed', [
                    'material_id' => $material->id,
                    'status' => $response->status(),
                ]);
            } elseif ($fileId && !$this->pcloud->isConfigured()) {
                $this->lastFetchError = 'pCloud is not configured on the server (PCLOUD_ACCESS_TOKEN missing).';
            } elseif (!$fileId) {
                $this->lastFetchError = 'Material has no pCloud file attached. Re-upload the PDF to course materials.';
            }

            $resourceUrl = trim((string) ($material->resource_url ?? ''));
            if ($resourceUrl !== '' && filter_var($resourceUrl, FILTER_VALIDATE_URL)) {
                $response = Http::timeout(60)->connectTimeout(15)->get($resourceUrl);
                if ($response->successful()) {
                    return $response->body();
                }

                $this->lastFetchError = ($this->lastFetchError ? $this->lastFetchError . ' ' : '')
                    . 'Direct URL download failed (HTTP ' . $response->status() . ').';
            }
        } catch (\Throwable $e) {
            $this->lastFetchError = $e->getMessage();
            Log::warning('Material fetch failed', [
                'material_id' => $material->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function materialCacheVersion(CourseMaterial $material): string
    {
        $meta = QuizMaterialHelper::meta($material);
        $fileId = MaterialFileHelper::pcloudFileId($meta);
        if ($fileId) {
            return 'f' . $fileId;
        }

        return 'u' . ($material->updated_at?->timestamp ?? 0);
    }

    protected function shouldAttemptMediaTranscription(string $filename): bool
    {
        if (!filter_var(config('services.quiz_ai.enable_media_transcription', false), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($ext, ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'mp4', 'mov', 'webm', 'mkv'], true);
    }

    protected function extractTextFromBytes(string $bytes, string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext === 'pdf' || str_starts_with($bytes, '%PDF')) {
            return $this->extractPdfText($bytes);
        }

        if (in_array($ext, ['txt', 'md', 'csv', 'rtf', 'vtt', 'srt'], true)) {
            if ($ext === 'rtf') {
                return $this->extractRtfText($bytes);
            }
            if (in_array($ext, ['vtt', 'srt'], true)) {
                return $this->extractSubtitleText($bytes);
            }

            return $bytes;
        }

        if (in_array($ext, ['docx', 'doc'], true) || $this->isZipOfficeDoc($bytes, 'word/document.xml')) {
            return $this->extractOfficeOpenXmlText($bytes, 'word/document.xml');
        }

        if (in_array($ext, ['pptx', 'ppt'], true) || $this->isZipOfficeDoc($bytes, 'ppt/slides/slide1.xml')) {
            return $this->extractPptxText($bytes);
        }

        if (in_array($ext, ['doc', 'ppt'], true)) {
            return null;
        }

        if ($this->looksLikePlainText($bytes)) {
            return $bytes;
        }

        return null;
    }

    protected function extractPdfText(string $bytes): ?string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseContent($bytes);
            $text = trim((string) $pdf->getText());

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('PDF text extraction failed', ['error' => $e->getMessage()]);

            return $this->fallbackPdfTextExtraction($bytes);
        }
    }

    protected function fallbackPdfTextExtraction(string $bytes): ?string
    {
        if (!preg_match_all('/\(([^()\\\\]*(?:\\\\.[^()\\\\]*)*)\)/s', $bytes, $matches)) {
            return null;
        }

        $parts = [];
        foreach ($matches[1] as $chunk) {
            $chunk = str_replace(['\\(', '\\)', '\\n', '\\r', '\\t'], ['(', ')', ' ', ' ', ' '], $chunk);
            $chunk = trim(preg_replace('/[^\P{C}\n\r\t]+/u', ' ', $chunk) ?? '');
            if (strlen($chunk) >= 4 && preg_match('/[A-Za-z]{3,}/', $chunk)) {
                $parts[] = $chunk;
            }
        }

        $text = trim(implode(' ', $parts));

        return $text !== '' ? $text : null;
    }

    protected function looksLikePlainText(string $bytes): bool
    {
        if ($bytes === '' || str_contains($bytes, "\0")) {
            return false;
        }

        $sample = substr($bytes, 0, 4096);

        return preg_match('//u', $sample) === 1;
    }

    protected function normalizeWhitespace(string $text): string
    {
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    protected function isZipOfficeDoc(string $bytes, string $entry): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mat_');
        if ($tmp === false) {
            return false;
        }

        try {
            file_put_contents($tmp, $bytes);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return false;
            }
            $exists = $zip->locateName($entry) !== false;
            $zip->close();

            return $exists;
        } finally {
            @unlink($tmp);
        }
    }

    protected function extractOfficeOpenXmlText(string $bytes, string $entry): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'docx_');
        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $bytes);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }
            $xml = $zip->getFromName($entry);
            $zip->close();
            if (!is_string($xml) || $xml === '') {
                return null;
            }

            $text = strip_tags(str_replace(['</w:p>', '<w:tab/>'], ["\n", ' '], $xml));
            $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

            return trim($text) !== '' ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('DOCX extraction failed', ['error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($tmp);
        }
    }

    protected function extractPptxText(string $bytes): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pptx_');
        if ($tmp === false) {
            return null;
        }

        try {
            file_put_contents($tmp, $bytes);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }

            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || !preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
                    continue;
                }
                $xml = $zip->getFromName($name);
                if (!is_string($xml)) {
                    continue;
                }
                $text = strip_tags(str_replace('</a:p>', "\n", $xml));
                $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            $zip->close();

            return $parts !== [] ? implode("\n\n", $parts) : null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    protected function extractRtfText(string $bytes): ?string
    {
        $text = preg_replace('/\{\\\\\*\\\\[^}]+\}/', '', $bytes) ?? $bytes;
        $text = preg_replace('/\{[^}]*\}/', '', $text) ?? $text;
        $text = preg_replace('/\\\\[a-z]+\d* ?/i', '', $text) ?? $text;
        $text = str_replace(['\\{', '\\}', '\\\\'], ['{', '}', '\\'], $text);
        $text = trim(preg_replace('/[^\P{C}\n\r\t]+/u', ' ', $text) ?? '');

        return $text !== '' ? $text : null;
    }

    protected function extractSubtitleText(string $bytes): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $bytes) ?: [];
        $parts = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^\d+$/', $line) || str_contains($line, '-->')) {
                continue;
            }
            if (!str_starts_with($line, 'WEBVTT')) {
                $parts[] = $line;
            }
        }

        $text = trim(implode(' ', $parts));

        return $text !== '' ? $text : null;
    }

    public function transcribeMediaBytes(string $bytes, string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $audioVideo = ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'mp4', 'mov', 'webm', 'mkv'];
        if (!in_array($ext, $audioVideo, true)) {
            return null;
        }

        if (strlen($bytes) > 15 * 1024 * 1024) {
            return null;
        }

        $key = env('GOOGLE_AI_API_KEY') ?: env('GEMINI_API_KEY');
        if (!is_string($key) || trim($key, " \t\"'") === '') {
            return null;
        }

        $mime = MaterialFileHelper::mimeFromFilename($filename);
        $model = config('services.gemini.stt_model', config('services.gemini.model', 'gemini-2.5-flash'));
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . trim($key, " \t\"'");

        try {
            $response = Http::timeout(120)->post($url, [
                'contents' => [[
                    'parts' => [
                        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                        ['text' => 'Transcribe this audio/video verbatim for educational quiz generation. Output plain text only.'],
                    ],
                ]],
                'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 4096],
            ]);

            if (!$response->successful()) {
                Log::info('Media transcription skipped', ['status' => $response->status()]);

                return null;
            }

            $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::info('Media transcription failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
