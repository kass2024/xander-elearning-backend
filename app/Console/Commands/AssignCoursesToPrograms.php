<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\ElearningProgram;
use App\Support\ApiListCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AssignCoursesToPrograms extends Command
{
    protected $signature = 'courses:assign-programs
                            {--list : Show courses and their current program assignment}
                            {--dry-run : Preview changes without writing to the database}
                            {--force : Reassign courses that already have a program_id}
                            {--create-missing : Create programs from mapping config when missing}
                            {--program= : Assign all targeted courses to this program name}
                            {--program-id= : Assign all targeted courses to this program ID}
                            {--course-id= : Only process a single course ID}';

    protected $description = 'Move existing courses into e-learning programs (keyword mapping or manual target)';

    public function handle(): int
    {
        if (!Schema::hasTable('elearning_programs') || !Schema::hasColumn('courses', 'program_id')) {
            $this->error('Program tables are missing. Run migrations first: php artisan migrate --force');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->listAssignments();
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $createMissing = (bool) $this->option('create-missing');
        $singleCourseId = $this->option('course-id') ? (int) $this->option('course-id') : null;
        $targetProgramName = $this->option('program') ? trim((string) $this->option('program')) : null;
        $targetProgramId = $this->option('program-id') ? (int) $this->option('program-id') : null;

        if ($targetProgramName && $targetProgramId) {
            $this->error('Use only one of --program or --program-id.');

            return self::FAILURE;
        }

        $query = Course::query()->orderBy('id');
        if ($singleCourseId) {
            $query->where('id', $singleCourseId);
        } elseif (!$force) {
            $query->whereNull('program_id');
        }

        $courses = $query->get();
        if ($courses->isEmpty()) {
            $this->info($force
                ? 'No courses found to process.'
                : 'All courses already have a program. Use --force to remap by keywords.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no database changes will be made.');
        }

        $programMap = $this->resolveProgramMap($createMissing, $dryRun);
        if ($programMap === null) {
            return self::FAILURE;
        }

        if ($targetProgramName || $targetProgramId) {
            return $this->assignToTargetProgram($courses, $programMap, $targetProgramName, $targetProgramId, $dryRun);
        }

        return $this->assignByKeywordMapping($courses, $programMap, $dryRun);
    }

    private function listAssignments(): int
    {
        $courses = Course::with('program:id,name')
            ->orderBy('id')
            ->get(['id', 'title', 'course_code', 'program_id', 'status']);

        if ($courses->isEmpty()) {
            $this->info('No courses in the database.');

            return self::SUCCESS;
        }

        $rows = $courses->map(fn (Course $c) => [
            $c->id,
            $c->course_code ?? '—',
            $c->title,
            $c->program?->name ?? '(unassigned)',
            $c->status ?? '—',
        ])->all();

        $this->table(['ID', 'Code', 'Title', 'Program', 'Status'], $rows);

        $unassigned = $courses->whereNull('program_id')->count();
        $this->newLine();
        $this->line("Total: {$courses->count()} | Unassigned: {$unassigned}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>|null program name => id
     */
    private function resolveProgramMap(bool $createMissing, bool $dryRun): ?array
    {
        $config = config('course_program_mapping.programs', []);
        $fallback = (string) config('course_program_mapping.fallback_program', 'General');
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
            if ($dryRun) {
                $this->line("[dry-run] Would create program: {$name}");
                $map[$name] = 0;
                continue;
            }

            $program = ElearningProgram::create([
                'name' => $name,
                'description' => $meta['description'] ?? null,
                'status' => 'Active',
                'sort_order' => $meta['sort_order'] ?? 0,
            ]);
            $map[$name] = $program->id;
            $this->info("Created program: {$name} (id {$program->id})");
        }

        foreach (ElearningProgram::orderBy('name')->get(['id', 'name']) as $p) {
            $map[$p->name] = $p->id;
        }

        if ($map === []) {
            $this->error('No programs found. Create programs in the dashboard or run with --create-missing.');

            return null;
        }

        return $map;
    }

  /**
     * @param \Illuminate\Support\Collection<int, Course> $courses
     * @param array<string, int> $programMap
     */
    private function assignToTargetProgram(
        $courses,
        array $programMap,
        ?string $targetProgramName,
        ?int $targetProgramId,
        bool $dryRun
    ): int {
        $programId = $targetProgramId;
        $programLabel = $targetProgramId ? "id {$targetProgramId}" : $targetProgramName;

        if ($targetProgramName) {
            $programId = $programMap[$targetProgramName] ?? ElearningProgram::where('name', $targetProgramName)->value('id');
            if (!$programId) {
                $this->error("Program not found: {$targetProgramName}");

                return self::FAILURE;
            }
            $programLabel = $targetProgramName;
        } elseif ($targetProgramId && !ElearningProgram::where('id', $targetProgramId)->exists()) {
            $this->error("Program not found: id {$targetProgramId}");

            return self::FAILURE;
        }

        $updated = 0;
        foreach ($courses as $course) {
            $from = $course->program_id ? "program_id {$course->program_id}" : 'unassigned';
            $this->line("  [{$course->id}] {$course->title}: {$from} → {$programLabel}");

            if (!$dryRun && $programId) {
                $course->program_id = $programId;
                $course->save();
            }
            $updated++;
        }

        if (!$dryRun) {
            $this->bumpCaches();
        }

        $this->newLine();
        $this->info($dryRun
            ? "Would assign {$updated} course(s) to {$programLabel}."
            : "Assigned {$updated} course(s) to {$programLabel}.");

        return self::SUCCESS;
    }

    /**
     * @param \Illuminate\Support\Collection<int, Course> $courses
     * @param array<string, int> $programMap
     */
    private function assignByKeywordMapping($courses, array $programMap, bool $dryRun): int
    {
        $config = config('course_program_mapping.programs', []);
        $fallback = (string) config('course_program_mapping.fallback_program', 'General');
        $fallbackId = $programMap[$fallback] ?? null;

        if (!$fallbackId && !$dryRun) {
            $this->error("Fallback program \"{$fallback}\" does not exist. Run with --create-missing.");

            return self::FAILURE;
        }

        $updated = 0;
        $summary = [];

        foreach ($courses as $course) {
            $programName = $this->matchProgramName($course, $config, $fallback);
            $programId = $programMap[$programName] ?? $fallbackId;

            if (!$programId && $dryRun) {
                $this->line("  [{$course->id}] {$course->title} → {$programName} (program would be created)");
                $updated++;
                $summary[$programName] = ($summary[$programName] ?? 0) + 1;
                continue;
            }

            if (!$programId) {
                $this->warn("  [{$course->id}] {$course->title} → skipped (program \"{$programName}\" missing)");

                continue;
            }

            $from = $course->program_id ? ElearningProgram::find($course->program_id)?->name ?? "id {$course->program_id}" : 'unassigned';
            $this->line("  [{$course->id}] {$course->title}: {$from} → {$programName}");

            if (!$dryRun) {
                $course->program_id = $programId;
                $course->save();
            }

            $updated++;
            $summary[$programName] = ($summary[$programName] ?? 0) + 1;
        }

        if (!$dryRun) {
            $this->bumpCaches();
        }

        $this->newLine();
        foreach ($summary as $name => $count) {
            $this->line("  {$name}: {$count} course(s)");
        }
        $this->newLine();
        $this->info($dryRun
            ? "Would assign {$updated} course(s)."
            : "Assigned {$updated} course(s).");

        return self::SUCCESS;
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
