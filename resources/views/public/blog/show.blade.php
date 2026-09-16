@extends('layouts.public')
@section('title', $post->meta_title ?: $post->title)
@section('description', $post->meta_description ?: ($post->excerpt ?: Str::limit(strip_tags($post->content), 155)))
@section('og_type', 'article')
@push('head')
<script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@type'=>'Article','headline'=>$post->title,'datePublished'=>$post->published_at?->toIso8601String(),'dateModified'=>$post->updated_at->toIso8601String(),'author'=>['@type'=>'Person','name'=>$post->author?->name ?? config('app.name')],'mainEntityOfPage'=>route('blog.show',$post->slug)], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}</script>
@endpush
@section('content')
<section class="page-hero"><div class="public-container"><span class="eyebrow">{{ strtoupper($post->category?->name ?? 'PANDUPOS') }}</span><h1>{{ $post->title }}</h1><p>{{ $post->excerpt }}</p><small>{{ $post->published_at->translatedFormat('d F Y') }} · {{ $post->author?->name ?? config('app.name') }}</small></div></section>
<section class="section"><div class="public-container content-grid"><article class="content-card article-body">{!! nl2br(e($post->content)) !!}<div class="source-cta"><h2>Ingin operasional serapi ini?</h2><p>Pelajari PanduPOS atau gunakan fondasi source code untuk produk Anda sendiri.</p><a class="button button-light" href="{{ route('docs') }}">Lihat PanduPOS</a></div></article>@include('public.blog.sidebar')</div></section>
@endsection
