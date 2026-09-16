<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use App\Services\Seo\PseoCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_growth_pages_are_available(): void
    {
        $this->get('/')->assertOk()->assertSee('Kasir cepat');
        $this->get('/docs')->assertOk()->assertSee('TUTORIAL 34 LANGKAH');
        $this->get('/blog')->assertOk();
        $this->get('/best-toko-ritel-2026')->assertOk()->assertSee('Kriteria yang paling penting');
        $this->get('/alternatives-to-aplikasi-kasir')->assertOk();
        $this->get('/compare/pandupos-vs-spreadsheet')->assertOk();
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->get('/faq')->assertOk()->assertSee('FAQPage');
        $this->get('/contact')->assertOk()->assertSee('KONTAK');
        $this->get('/aplikasi-pos/toko-ritel/jakarta/kasir-cepat-harian')->assertOk()->assertSee('Toko Ritel');
        $this->get('/source-code-pos/toko-ritel/jakarta/kasir-cepat-harian')->assertOk()->assertSee('Source Code POS');
    }

    public function test_published_blog_has_detail_feed_schema_and_sitemap(): void
    {
        $author = User::factory()->create();
        $category = BlogCategory::create(['name' => 'POS', 'slug' => 'pos']);
        $post = BlogPost::create([
            'category_id' => $category->id,
            'author_id' => $author->id,
            'title' => 'Panduan Kasir',
            'slug' => 'panduan-kasir',
            'content' => 'Konten panduan kasir untuk operasional toko.',
            'excerpt' => 'Ringkasan artikel.',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->get(route('blog.show', $post->slug))->assertOk()->assertSee('Article');
        $this->get(route('blog.category', $category->slug))->assertOk()->assertSee($post->title);
        $this->get(route('blog.feed'))->assertOk()->assertSee($post->title);
        $this->get(route('sitemap.content'))->assertOk()->assertSee(route('blog.show', $post->slug));
    }

    public function test_pseo_catalog_exposes_one_million_pages_plus_source_code_pages_in_split_sitemaps(): void
    {
        $catalog = app(PseoCatalog::class);
        $this->assertSame(1_100_000, $catalog->totalUrls());
        $this->assertSame(110, $catalog->chunkCount());

        $index = $this->get(route('sitemap'))->assertOk()->assertSee(route('sitemap.pseo', 110));
        $chunk = $this->get(route('sitemap.pseo', 1))->assertOk();
        $this->assertLessThanOrEqual(2 * 1024 * 1024, strlen($chunk->getContent()));
        $this->assertStringContainsString('/aplikasi-pos/', $chunk->getContent());
        $this->assertStringContainsString('sitemapindex', $index->getContent());
    }

    public function test_draft_blog_is_not_public(): void
    {
        $post = BlogPost::create([
            'title' => 'Draft Rahasia',
            'slug' => 'draft-rahasia',
            'content' => 'Belum boleh tampil.',
            'is_published' => false,
        ]);

        $this->get(route('blog.show', $post->slug))->assertNotFound();
    }
}
