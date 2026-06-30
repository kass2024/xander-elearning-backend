<?php

namespace App\Support;

use App\Models\CourseMaterial;
use Illuminate\Support\Str;

class QuizMaterialHelper
{
    public static function isPdfMaterial(CourseMaterial $material): bool
    {
        $meta = is_array($material->metadata) ? $material->metadata : [];
        $filename = (string) ($meta['filename'] ?? $material->title ?? '');
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            return true;
        }

        $mime = strtolower((string) ($meta['contenttype'] ?? ''));

        return str_contains($mime, 'pdf');
    }

    public static function isStudyMaterial(CourseMaterial $material): bool
    {
        return !in_array(strtolower((string) $material->type), ['quiz', 'assessment', 'zoom'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(CourseMaterial $material): array
    {
        return is_array($material->metadata) ? $material->metadata : [];
    }

    public static function extractModule(CourseMaterial $material): ?string
    {
        $meta = self::meta($material);
        $module = trim((string) ($meta['module'] ?? ''));

        if ($module !== '') {
            return $module;
        }

        return self::matchLabel($material->title ?? '', ['module']) ?? self::matchLabel($material->description ?? '', ['module']);
    }

    public static function extractChapter(CourseMaterial $material): ?string
    {
        $meta = self::meta($material);
        $chapter = trim((string) ($meta['chapter'] ?? ''));

        if ($chapter !== '') {
            return $chapter;
        }

        return self::matchLabel($material->title ?? '', ['chapter', 'ch']) ?? self::matchLabel($material->description ?? '', ['chapter', 'ch']);
    }

    public static function extractTopicLabel(CourseMaterial $material): ?string
    {
        $meta = self::meta($material);
        $topic = trim((string) ($meta['topic'] ?? ''));

        if ($topic !== '') {
            return $topic;
        }

        $module = self::extractModule($material);
        if ($module) {
            return $module;
        }

        $chapter = self::extractChapter($material);
        if ($chapter) {
            return $chapter;
        }

        $title = trim((string) ($material->title ?? ''));
        if ($title !== '') {
            if (self::isPdfMaterial($material) && preg_match('/\.pdf$/i', $title)) {
                return null;
            }

            return $title;
        }

        $description = trim((string) ($material->description ?? ''));
        if ($description !== '') {
            return Str::limit($description, 80, '');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function materialSummary(CourseMaterial $material): array
    {
        $meta = self::meta($material);
        $filename = (string) ($meta['filename'] ?? $material->title ?? 'file');

        return [
            'id' => $material->id,
            'title' => $material->title,
            'description' => $material->description,
            'type' => $material->type,
            'topic' => self::extractTopicLabel($material),
            'module' => self::extractModule($material),
            'chapter' => self::extractChapter($material),
            'filename' => $filename,
            'is_pdf' => self::isPdfMaterial($material),
        ];
    }

    /**
     * @param  iterable<CourseMaterial>  $materials
     * @return array<int, array<string, mixed>>
     */
    public static function buildTopicGroups(iterable $materials): array
    {
        $groups = [];

        foreach ($materials as $material) {
            if (!$material instanceof CourseMaterial || !self::isStudyMaterial($material)) {
                continue;
            }

            $label = self::extractTopicLabel($material);
            if (!$label) {
                continue;
            }

            $key = strtolower($label);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $label,
                    'material_ids' => [],
                    'materials' => [],
                ];
            }

            $groups[$key]['material_ids'][] = $material->id;
            $groups[$key]['materials'][] = self::materialSummary($material);
        }

        return array_values($groups);
    }

    /**
     * Materials linked to a topic label (from uploaded study files).
     *
     * @param  iterable<CourseMaterial>  $materials
     * @return array<int, CourseMaterial>
     */
    public static function materialsForTopic(iterable $materials, string $topic): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            return [];
        }

        $collection = collect($materials)->filter(fn ($m) => $m instanceof CourseMaterial && self::isStudyMaterial($m));
        $groups = self::buildTopicGroups($collection);

        foreach ($groups as $group) {
            if (strcasecmp((string) ($group['label'] ?? ''), $topic) === 0) {
                return $collection->whereIn('id', $group['material_ids'] ?? [])->values()->all();
            }
        }

        foreach ($groups as $group) {
            $label = (string) ($group['label'] ?? '');
            if ($label !== '' && (stripos($label, $topic) !== false || stripos($topic, $label) !== false)) {
                return $collection->whereIn('id', $group['material_ids'] ?? [])->values()->all();
            }
        }

        return self::materialsForTopicFromAnalysis($collection, $topic);
    }

    /**
     * Match a quiz topic to PDF materials via cached Gemini/local analysis.
     *
     * @param  \Illuminate\Support\Collection<int, CourseMaterial>|iterable<CourseMaterial>  $materials
     * @return array<int, CourseMaterial>
     */
    public static function materialsForTopicFromAnalysis(iterable $materials, string $topic): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            return [];
        }

        $analysisService = app(\App\Services\Quiz\QuizMaterialAnalysisService::class);
        $matched = [];

        foreach ($materials as $material) {
            if (!$material instanceof CourseMaterial || !self::isStudyMaterial($material) || !self::isPdfMaterial($material)) {
                continue;
            }

            $map = $analysisService->getCachedKnowledgeMap($material);
            if (!is_array($map) || $map === []) {
                continue;
            }

            $labels = array_merge(
                is_array($map['main_topics'] ?? null) ? $map['main_topics'] : [],
                is_array($map['subtopics'] ?? null) ? $map['subtopics'] : [],
            );

            foreach ($labels as $label) {
                $label = trim((string) $label);
                if ($label === '') {
                    continue;
                }

                if (strcasecmp($label, $topic) === 0
                    || stripos($label, $topic) !== false
                    || stripos($topic, $label) !== false) {
                    $matched[$material->id] = $material;
                    break;
                }
            }
        }

        return array_values($matched);
    }

    public static function quizStatus(CourseMaterial $quiz): string
    {
        $meta = self::meta($quiz);

        if (array_key_exists('status', $meta)) {
            return (string) $meta['status'];
        }

        return self::hasInteractiveQuestions($quiz) ? 'published' : 'draft';
    }

    public static function isPublished(CourseMaterial $quiz): bool
    {
        return self::quizStatus($quiz) === 'published';
    }

    /**
     * @return array<int, int>
     */
    public static function publishedStudentIds(CourseMaterial $quiz): array
    {
        $meta = self::meta($quiz);
        $ids = $meta['published_student_ids'] ?? [];

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => (int) $id > 0))));
    }

    public static function isVisibleToStudent(CourseMaterial $quiz, int $studentId): bool
    {
        if (!in_array($quiz->type, ['quiz', 'assessment'], true)) {
            return true;
        }

        if (!self::isPublished($quiz)) {
            return false;
        }

        $allowed = self::publishedStudentIds($quiz);
        if (empty($allowed)) {
            return true;
        }

        return in_array($studentId, $allowed, true);
    }

    public static function hasInteractiveQuestions(CourseMaterial $quiz): bool
    {
        $meta = self::meta($quiz);

        return count($meta['questions'] ?? []) > 0;
    }

    public static function timeLimitMinutes(CourseMaterial $quiz): ?int
    {
        $meta = self::meta($quiz);
        $minutes = (int) ($meta['time_limit_minutes'] ?? 0);

        return $minutes > 0 ? $minutes : null;
    }

    protected static function matchLabel(string $text, array $keywords): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        foreach ($keywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\s*[\d.:]*\s*[-–—:]?\s*([^\n\r|]+)/iu', $text, $matches)) {
                return trim($matches[0]);
            }
        }

        return null;
    }
}
