<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PublicStorageController extends Controller
{
    public function show(string $path)
    {
        $path = str_replace(['\\', '..'], ['/', ''], $path);
        $path = ltrim($path, '/');

        if ($path === '' || !str_starts_with($path, 'uploads/')) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->file($disk->path($path));
    }
}
