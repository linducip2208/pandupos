<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\Seo\PseoCatalog;
use Illuminate\Support\Str;

class ProgrammaticSeoController extends Controller
{
    public function growth(string $intent, string $industry, string $city, string $feature, PseoCatalog $catalog)
    {
        abort_unless($catalog->validIntent($intent), 404);
        $terms = $catalog->describe($industry, $city, $feature);
        $title = Str::headline($intent).' POS '.$terms['industry'].' di '.$terms['city'].' untuk '.$terms['feature'];

        return $this->page('growth', $title, $industry, $city, null, $terms);
    }

    public function sourceCode(string $industry, string $city, string $feature, PseoCatalog $catalog)
    {
        $terms = $catalog->describe($industry, $city, $feature);
        $title = 'Source Code POS '.$terms['industry'].' '.$terms['city'].' dengan '.$terms['feature'];

        return $this->page('source-code', $title, $industry, $city, null, $terms);
    }

    public function best(string $category, ?int $year = null)
    {
        $year ??= (int) date('Y');
        $title = '10 Aplikasi POS Terbaik untuk '.Str::headline($category).' '.$year;

        return $this->page('best', $title, $category, null, $year);
    }

    public function alternatives(string $slug)
    {
        return $this->page('alternatives', 'Alternatif Terbaik untuk '.Str::headline($slug), $slug);
    }

    public function compare(string $a, string $b)
    {
        abort_if($a === $b, 404);

        return $this->page('compare', Str::headline($a).' vs '.Str::headline($b).': Perbandingan Lengkap', $a, $b);
    }

    private function page(string $type, string $title, string $primary, ?string $secondary = null, ?int $year = null, array $terms = [])
    {
        $products = Product::withoutGlobalScopes()->with('variants')->where('is_active', true)->limit(10)->get();
        $categories = Category::withoutGlobalScopes()->select('name')->distinct()->limit(12)->pluck('name');

        return view('public.pseo', compact('type', 'title', 'primary', 'secondary', 'year', 'products', 'categories', 'terms'));
    }
}
