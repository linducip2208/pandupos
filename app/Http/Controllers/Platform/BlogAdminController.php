<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BlogAdminController extends Controller
{
    public function index()
    {
        return view('platform.blog.index', [
            'posts' => BlogPost::with(['category', 'author'])->latest()->paginate(20),
            'categories' => BlogCategory::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['author_id'] = $request->user()->id;
        $data['slug'] = $this->uniqueSlug($data['slug'] ?: $data['title']);
        $data['is_published'] = $request->boolean('is_published');
        $data['published_at'] = $data['is_published'] ? ($data['published_at'] ?: now()) : null;
        BlogPost::create($data);
        Cache::forget('seo.sitemap.index');

        return back()->with('status', 'Artikel berhasil dibuat.');
    }

    public function update(Request $request, BlogPost $post)
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?: $data['title'], $post->id);
        $data['is_published'] = $request->boolean('is_published');
        $data['published_at'] = $data['is_published'] ? ($data['published_at'] ?: $post->published_at ?: now()) : null;
        $post->update($data);
        Cache::forget('seo.sitemap.index');

        return back()->with('status', 'Artikel berhasil diperbarui.');
    }

    public function destroy(BlogPost $post)
    {
        $post->delete();
        Cache::forget('seo.sitemap.index');

        return back()->with('status', 'Artikel dihapus.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:blog_categories,id'],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'content' => ['required', 'string'],
            'featured_image' => ['nullable', 'url', 'max:2048'],
            'published_at' => ['nullable', 'date'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
        ]);
    }

    private function uniqueSlug(string $value, ?int $except = null): string
    {
        $base = Str::slug($value) ?: Str::random(8);
        $slug = $base;
        $suffix = 2;
        while (BlogPost::where('slug', $slug)->when($except, fn ($query) => $query->whereKeyNot($except))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
