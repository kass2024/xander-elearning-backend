<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$token = config('services.pcloud.access_token');
$folderid = (int) config('services.pcloud.root_folder_id', 31887143130);
$file = tempnam(sys_get_temp_dir(), 'pcloud_auth_test_');
file_put_contents($file, 'auth parameter test');

foreach (['auth', 'access_token'] as $authKey) {
    foreach (['POST', 'PUT'] as $method) {
        $url = 'https://api.pcloud.com/uploadfile?' . http_build_query([
            'folderid' => $folderid,
            $authKey => $token,
            'filename' => 'auth-param-test.txt',
            'renameifexists' => 1,
            'nopartial' => 1,
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init();
        $fh = fopen($file, 'rb');
        $size = filesize($file);

        if ($method === 'PUT') {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_PUT => true,
                CURLOPT_INFILE => $fh,
                CURLOPT_INFILESIZE => $size,
                CURLOPT_RETURNTRANSFER => true,
            ]);
        } else {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['file' => curl_file_create($file, 'text/plain', 'auth-param-test.txt')],
                CURLOPT_RETURNTRANSFER => true,
            ]);
        }

        $body = curl_exec($ch);
        curl_close($ch);
        fclose($fh);

        $json = json_decode((string) $body, true);
        $ok = is_array($json) && ($json['result'] ?? 1) === 0;
        echo $method . '/' . $authKey . ': ' . ($ok ? 'OK' : ($json['error'] ?? substr((string) $body, 0, 80))) . PHP_EOL;

        if ($ok) {
            $meta = $json['metadata'][0] ?? $json['metadata'] ?? null;
            $fileId = is_array($meta) ? (int) ($meta['fileid'] ?? 0) : 0;
            if ($fileId > 0) {
                $del = file_get_contents('https://api.pcloud.com/deletefile?access_token=' . rawurlencode($token) . '&fileid=' . $fileId);
                echo '  deleted fileid ' . $fileId . PHP_EOL;
            }
        }
    }
}

@unlink($file);
