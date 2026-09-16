<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Category;
use App\Services\Seo\PseoCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    public function index(PseoCatalog $catalog)
    {
        $xml = Cache::remember('seo.sitemap.index.v2', now()->addDay(), function () use ($catalog) {
            $sitemaps = [['loc' => route('sitemap.content'), 'lastmod' => now()]];
            for ($chunk = 1; $chunk <= $catalog->chunkCount(); $chunk++) {
                $sitemaps[] = ['loc' => route('sitemap.pseo', $chunk), 'lastmod' => now()];
            }

            return view('public.sitemap-index', compact('sitemaps'))->render();
        });

        return $this->xml($xml);
    }

    public function content()
    {
        $xml = Cache::remember('seo.sitemap.content.v2', now()->addDay(), function () {
            $urls = collect([
                ['loc' => route('home'), 'lastmod' => now()],
                ['loc' => route('docs'), 'lastmod' => now()],
                ['loc' => route('blog.index'), 'lastmod' => now()],
                ['loc' => route('faq'), 'lastmod' => now()],
                ['loc' => route('contact'), 'lastmod' => now()],
            ]);

            BlogPost::published()->select(['id', 'slug', 'updated_at'])->chunkById(500, function ($posts) use ($urls) {
                foreach ($posts as $post) {
                    $urls->push(['loc' => route('blog.show', $post->slug), 'lastmod' => $post->updated_at]);
                }
            });

            Category::withoutGlobalScopes()->select(['id', 'name', 'updated_at'])->limit(20000)->get()->each(function ($category) use ($urls) {
                $slug = Str::slug($category->name);
                $urls->push(['loc' => route('pseo.best.year', [$slug, date('Y')]), 'lastmod' => $category->updated_at]);
                $urls->push(['loc' => route('pseo.alternatives', $slug), 'lastmod' => $category->updated_at]);
            });

            return view('public.sitemap', compact('urls'))->render();
        });

        return $this->xml($xml);
    }

    public function pseo(int $chunk, PseoCatalog $catalog)
    {
        $xml = Cache::remember("seo.sitemap.pseo.{$chunk}.v2", now()->addDay(), function () use ($catalog, $chunk) {
            $lastmod = now();
            $urls = collect($catalog->urlsForChunk($chunk))->map(fn (string $url) => ['loc' => $url, 'lastmod' => $lastmod]);

            return view('public.sitemap', compact('urls'))->render();
        });

        return $this->xml($xml);
    }

    private function xml(string $xml)
    {
        return response($xml)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
