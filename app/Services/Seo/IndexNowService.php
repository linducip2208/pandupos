<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IndexNowService
{
    public function submit(array $urls): array
    {
        $key = (string) config('services.indexnow.key');
        $endpoints = array_values(array_filter(config('services.indexnow.endpoints', [])));
        $urls = array_values(array_unique(array_filter($urls, fn ($url) => filter_var($url, FILTER_VALIDATE_URL))));

        if ($key === '' || $endpoints === [] || $urls === []) {
            return ['submitted' => 0, 'skipped' => count($urls), 'reason' => 'IndexNow belum dikonfigurasi.'];
        }

        $fresh = array_values(array_filter($urls, fn ($url) => ! Cache::has($this->cacheKey($url))));
        if ($fresh === []) {
            return ['submitted' => 0, 'skipped' => count($urls)];
        }

        $payload = [
            'host' => parse_url(config('app.url'), PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => url('/indexnow-key.txt'),
            'urlList' => $fresh,
        ];

        foreach ($endpoints as $endpoint) {
            Http::timeout(10)->retry(2, 300)->post($endpoint, $payload)->throw();
        }

        foreach ($fresh as $url) {
            Cache::put($this->cacheKey($url), true, now()->addDays(7));
        }

        return ['submitted' => count($fresh), 'skipped' => count($urls) - count($fresh)];
    }

    private function cacheKey(string $url): string
    {
        return 'indexnow.submitted.'.hash('sha256', $url);
    }
}
