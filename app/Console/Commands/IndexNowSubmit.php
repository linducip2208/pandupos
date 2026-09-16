<?php

namespace App\Console\Commands;

use App\Models\BlogPost;
use App\Services\Seo\IndexNowService;
use Illuminate\Console\Command;

class IndexNowSubmit extends Command
{
    protected $signature = 'seo:indexnow {--url=* : URL spesifik yang akan dikirim}';

    protected $description = 'Kirim URL publik baru atau berubah ke endpoint IndexNow terkonfigurasi';

    public function handle(IndexNowService $service): int
    {
        $urls = $this->option('url');
        if ($urls === []) {
            $urls = BlogPost::published()->where('updated_at', '>=', now()->subDay())->pluck('slug')->map(fn ($slug) => route('blog.show', $slug))->all();
        }

        $result = $service->submit($urls);
        $this->info("Submitted: {$result['submitted']}; skipped: {$result['skipped']}");
        if (isset($result['reason'])) {
            $this->warn($result['reason']);
        }

        return self::SUCCESS;
    }
}
