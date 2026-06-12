<?php

namespace App\Support;

class FrontendUrl
{
    /**
     * Base URL for learner-facing React app (Stripe return URLs, emails, certificates).
     */
    public static function base(): string
    {
        $explicit = rtrim((string) env('FRONTEND_URL', ''), '/');
        if ($explicit !== '') {
            return $explicit;
        }

        $appUrl = rtrim((string) config('app.url', ''), '/');

        // When API runs on api.example.com, learner app is on elearning.example.com
        if ($appUrl !== '' && preg_match('#^https?://api\.(.+)$#i', $appUrl, $matches)) {
            $scheme = str_starts_with(strtolower($appUrl), 'https://') ? 'https' : 'http';

            return $scheme . '://elearning.' . $matches[1];
        }

        if ($appUrl !== '') {
            return $appUrl;
        }

        return 'http://localhost:8080';
    }
}
