<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    public function index(Request $request, ?BlogCategory $category = null)
    {
        $posts = BlogPost::published()
            ->with(['category', 'author'])
            ->when($category, fn ($query) => $query->whereBelongsTo($category, 'category'))
            ->when($request->string('q')->trim()->value(), function ($query, $term) {
                $query->where(fn ($nested) => $nested->where('title', 'like', "%{$term}%")->orWhere('excerpt', 'like', "%{$term}%"));
            })
            ->latest('published_at')
            ->paginate(9)
            ->withQueryString();

        return view('public.blog.index', [
            'posts' => $posts,
            'activeCategory' => $category,
            'categories' => BlogCategory::withCount(['posts' => fn ($query) => $query->published()])->orderBy('name')->get(),
            'recentPosts' => BlogPost::published()->latest('published_at')->limit(5)->get(),
        ]);
    }

    public function show(string $slug)
    {
        $post = BlogPost::published()->with(['category', 'author'])->where('slug', $slug)->firstOrFail();

        return view('public.blog.show', [
            'post' => $post,
            'categories' => BlogCategory::withCount(['posts' => fn ($query) => $query->published()])->orderBy('name')->get(),
            'recentPosts' => BlogPost::published()->whereKeyNot($post->id)->latest('published_at')->limit(5)->get(),
        ]);
    }

    public function feed()
    {
        $posts = BlogPost::published()->latest('published_at')->limit(30)->get();

        return response()->view('public.blog.feed', compact('posts'))->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}
