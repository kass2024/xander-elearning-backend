<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$token = config('services.pcloud.access_token');
$folderid = (int) config('services.pcloud.root_folder_id', 31887143130);
$file = tempnam(sys_get_temp_dir(), 'pcloud_auth_');
file_put_contents($file, 'upload auth modes test');

$tests = [
    'POST query access_token' => function () use ($token, $folderid, $file) {
        $url = 'https://api.pcloud.com/uploadfile?' . http_build_query([
            'folderid' => $folderid,
            'access_token' => $token,
            'filename' => 'auth-mode-test.txt',
            'renameifexists' => 1,
            'nopartial' => 1,
        ]);
        return curlPostFile($url, $file);
    },
    'POST query auth' => function () use ($token, $folderid, $file) {
        $url = 'https://api.pcloud.com/uploadfile?' . http_build_query([
            'folderid' => $folderid,
            'auth' => $token,
            'filename' => 'auth-mode-test.txt',
            'renameifexists' => 1,
            'nopartial' => 1,
        ]);
        return curlPostFile($url, $file);
    },
    'POST Bearer header' => function () use ($token, $folderid, $file) {
        $url = 'https://api.pcloud.com/uploadfile?' . http_build_query([
            'folderid' => $folderid,
            'filename' => 'auth-mode-test.txt',
            'renameifexists' => 1,
            'nopartial' => 1,
        ]);
        return curlPostFile($url, $file, ['Authorization: Bearer ' . $token]);
    },
    'POST form auth field' => function () use ($token, $folderid, $file) {
        $url = 'https://api.pcloud.com/uploadfile';
        return curlPostMultipart($url, $file, [
            'auth' => $token,
            'folderid' => (string) $folderid,
            'renameifexists' => '1',
            'filename' => 'auth-mode-test.txt',
            'nopartial' => '1',
        ]);
    },
];

function curlPostFile(string $url, string $filePath, array $headers = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => curl_file_create($filePath, 'text/plain', 'auth-mode-test.txt')],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    return json_decode((string) $body, true) ?: ['error' => substr((string) $body, 0, 120)];
}

function curlPostMultipart(string $url, string $filePath, array $fields): array
{
    $fields['file'] = curl_file_create($filePath, 'text/plain', 'auth-mode-test.txt');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    return json_decode((string) $body, true) ?: ['error' => substr((string) $body, 0, 120)];
}

foreach ($tests as $label => $fn) {
    $json = $fn();
    $ok = ($json['result'] ?? 1) === 0;
    echo $label . ': ' . ($ok ? 'OK' : ($json['error'] ?? 'unknown')) . PHP_EOL;
    if ($ok) {
        $meta = $json['metadata'][0] ?? $json['metadata'] ?? null;
        $fileId = is_array($meta) ? (int) ($meta['fileid'] ?? 0) : 0;
        if ($fileId > 0) {
            file_get_contents('https://api.pcloud.com/deletefile?access_token=' . rawurlencode($token) . '&fileid=' . $fileId);
        }
    }
}

@unlink($file);
