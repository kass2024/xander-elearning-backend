<?php

namespace App\Services;

use App\Models\Course;
use App\Models\ElearningProgram;
use App\Support\ApiListCache;
use Illuminate\Support\Collection;

class CourseProgramAssignmentService
{
    /**
     * @param list<int> $courseIds
     * @return array{moved: int, program: ElearningProgram}
     */
    public function assignCoursesToProgram(int $programId, array $courseIds): array
    {
        $program = ElearningProgram::findOrFail($programId);
        $ids = array_values(array_unique(array_filter(array_map('intval', $courseIds))));

        if ($ids === []) {
            return ['moved' => 0, 'program' => $program];
        }

        $moved = Course::whereIn('id', $ids)->update(['program_id' => $program->id]);
        $this->bumpCaches();

        return ['moved' => $moved, 'program' => $program->fresh()];
    }

    public function moveCourse(int $courseId, int $programId): Course
    {
        $program = ElearningProgram::findOrFail($programId);
        $course = Course::findOrFail($courseId);
        $course->program_id = $program->id;
        $course->save();
        $this->bumpCaches();

        return $course->load('program:id,name');
    }

    /**
     * @return array{
     *   assigned: int,
     *   summary: array<string, int>,
     *   details: list<array{course_id: int, title: string, from: string|null, to: string}>
     * }
     */
    public function autoAssignByKeywords(bool $createMissing = true, bool $force = false): array
    {
        $config = config('course_program_mapping.programs', []);
        $fallback = (string) config('course_program_mapping.fallback_program', 'General');
        $programMap = $this->resolveProgramMap($config, $fallback, $createMissing);

        $query = Course::query()->orderBy('id');
        if (!$force) {
            $query->whereNull('program_id');
        }
        $courses = $query->get();

        $assigned = 0;
        $summary = [];
        $details = [];

        foreach ($courses as $course) {
            $programName = $this->matchProgramName($course, $config, $fallback);
            $programId = $programMap[$programName] ?? $programMap[$fallback] ?? null;

            if (!$programId) {
                continue;
            }

            $from = $course->program_id
                ? (ElearningProgram::find($course->program_id)?->name ?? "id {$course->program_id}")
                : null;

            if ((int) $course->program_id === (int) $programId) {
                continue;
            }

            $course->program_id = $programId;
            $course->save();

            $assigned++;
            $summary[$programName] = ($summary[$programName] ?? 0) + 1;
            $details[] = [
                'course_id' => $course->id,
                'title' => $course->title,
                'from' => $from,
                'to' => $programName,
            ];
        }

        if ($assigned > 0) {
            $this->bumpCaches();
        }

        return [
            'assigned' => $assigned,
            'summary' => $summary,
            'details' => $details,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $config
     * @return array<string, int>
     */
    private function resolveProgramMap(array $config, string $fallback, bool $createMissing): array
    {
        $names = array_keys($config);
        if (!in_array($fallback, $names, true)) {
            $names[] = $fallback;
        }

        $map = [];
        foreach (array_unique($names) as $name) {
            $existing = ElearningProgram::where('name', $name)->first();
            if ($existing) {
                $map[$name] = $existing->id;
                continue;
            }

            if (!$createMissing) {
                continue;
            }

            $meta = $config[$name] ?? [];
            $program = ElearningProgram::create([
                'name' => $name,
                'description' => $meta['description'] ?? null,
                'status' => 'Active',
                'sort_order' => $meta['sort_order'] ?? 0,
            ]);
            $map[$name] = $program->id;
        }

        foreach (ElearningProgram::orderBy('name')->get(['id', 'name']) as $p) {
            $map[$p->name] = $p->id;
        }

        return $map;
    }

    /**
     * @param array<string, array<string, mixed>> $config
     */
    private function matchProgramName(Course $course, array $config, string $fallback): string
    {
        $haystack = strtolower(trim(($course->title ?? '') . ' ' . ($course->course_code ?? '')));

        foreach ($config as $programName => $meta) {
            if ($programName === $fallback) {
                continue;
            }
            foreach ($meta['keywords'] ?? [] as $keyword) {
                if ($keyword !== '' && str_contains($haystack, strtolower($keyword))) {
                    return $programName;
                }
            }
        }

        return $fallback;
    }

    private function bumpCaches(): void
    {
        ApiListCache::bump('courses');
        ApiListCache::bump('elearning_programs');
    }
}
