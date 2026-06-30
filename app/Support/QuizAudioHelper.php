<?php

namespace App\Support;

class QuizAudioHelper
{
    public static function pcloudRef(int $fileId): string
    {
        return 'pcloud:' . $fileId;
    }

    public static function answerPcloudRef(int $fileId): string
    {
        return 'audio:pcloud:' . $fileId;
    }

    /**
     * @return array{type: string, file_id?: int, path?: string, filename?: string}|null
     */
    public static function parseRef(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        if (str_starts_with($ref, 'audio:pcloud:')) {
            $fileId = (int) substr($ref, strlen('audio:pcloud:'));

            return $fileId > 0 ? ['type' => 'pcloud', 'file_id' => $fileId] : null;
        }

        if (str_starts_with($ref, 'pcloud:')) {
            $fileId = (int) substr($ref, strlen('pcloud:'));

            return $fileId > 0 ? ['type' => 'pcloud', 'file_id' => $fileId] : null;
        }

        if (str_starts_with($ref, 'audio:')) {
            $path = ltrim(substr($ref, 6), '/');

            return $path !== '' ? ['type' => 'local', 'path' => $path] : null;
        }

        if (str_starts_with($ref, 'uploads/')) {
            return ['type' => 'local', 'path' => $ref];
        }

        return null;
    }

    public static function parsePcloudFileId(string $ref): ?int
    {
        $parsed = self::parseRef($ref);

        return ($parsed['type'] ?? '') === 'pcloud' ? (int) ($parsed['file_id'] ?? 0) : null;
    }
}
