# Move existing courses into e-learning programs.
# Run from E-learning-parrot-backend folder.
#
# Examples:
#   .\scripts\assign-courses-to-programs.ps1 -List
#   .\scripts\assign-courses-to-programs.ps1 -DryRun -CreateMissing
#   .\scripts\assign-courses-to-programs.ps1 -Program "Language"
#   .\scripts\assign-courses-to-programs.ps1 -ProgramId 2 -CourseId 5

param(
    [switch]$List,
    [switch]$DryRun,
    [switch]$Force,
    [switch]$CreateMissing,
    [string]$Program,
    [int]$ProgramId = 0,
    [int]$CourseId = 0
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$args = @()
if ($List) { $args += "--list" }
if ($DryRun) { $args += "--dry-run" }
if ($Force) { $args += "--force" }
if ($CreateMissing) { $args += "--create-missing" }
if ($Program) { $args += "--program=$Program" }
if ($ProgramId -gt 0) { $args += "--program-id=$ProgramId" }
if ($CourseId -gt 0) { $args += "--course-id=$CourseId" }

php artisan courses:assign-programs @args
exit $LASTEXITCODE
