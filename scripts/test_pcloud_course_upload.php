<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PCloudService;
use Illuminate\Http\UploadedFile;

$service = app(PCloudService::class);
$courseId = (int) ($argv[1] ?? 1);

$tmp = tempnam(sys_get_temp_dir(), 'course_upload_');
file_put_contents($tmp, str_repeat('x', 1024 * 1024)); // 1MB test file

$uploaded = new UploadedFile($tmp, 'Mukeshimana-staff_cards.pdf', 'application/pdf', null, true);

try {
    $folder = $service->ensureCourseFolder($courseId);
    echo 'Course folder: ' . json_encode($folder) . PHP_EOL;
    $result = $service->uploadToCourse($courseId, $uploaded);
    echo 'Upload OK: ' . json_encode($result) . PHP_EOL;
    if (!empty($result['fileid'])) {
        $service->deleteFile((int) $result['fileid']);
        echo 'Cleaned up fileid ' . $result['fileid'] . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'FAILED: ' . $e->getMessage() . PHP_EOL;
    exit(1);
} finally {
    @unlink($tmp);
}
