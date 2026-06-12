<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(\App\Services\ZoomService::class);
$tracked = \App\Support\AdminRecordingCatalog::trackedMeetingIds();

echo 'configured=' . (int) $zoom->isConfigured() . PHP_EOL;
echo 'tracked=' . count($tracked) . PHP_EOL;

$collected = $zoom->collectAllCloudRecordings($tracked, 12);
echo 'meetings=' . count($collected['meetings']) . PHP_EOL;
echo 'strategies=' . implode(', ', $collected['strategies']) . PHP_EOL;

if ($collected['errors'] !== []) {
    echo 'errors:' . PHP_EOL;
    foreach ($collected['errors'] as $err) {
        echo '  - ' . $err . PHP_EOL;
    }
}

foreach (array_slice($collected['meetings'], 0, 5) as $m) {
    echo '- ' . ($m['topic'] ?? '?') . ' id=' . ($m['id'] ?? '?') . ' files=' . count($m['recording_files'] ?? []) . PHP_EOL;
}

$items = $zoom->formatRecordingItems(['meetings' => $collected['meetings']]);
echo 'formatted_items=' . count($items) . PHP_EOL;
