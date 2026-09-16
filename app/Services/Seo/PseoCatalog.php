<?php

namespace App\Services\Seo;

use Illuminate\Support\Str;

final class PseoCatalog
{
    public const CHUNK_SIZE = 10000;

    private const INTENTS = ['aplikasi', 'software', 'sistem', 'platform', 'solusi', 'rekomendasi', 'panduan', 'otomasi', 'digitalisasi', 'manajemen'];

    private const INDUSTRIES = ['toko-ritel', 'minimarket', 'supermarket', 'toko-kelontong', 'restoran', 'kafe', 'bakery', 'apotek', 'klinik', 'salon', 'barbershop', 'laundry', 'bengkel', 'toko-bangunan', 'toko-elektronik', 'fashion', 'distributor', 'grosir', 'koperasi', 'pet-shop', 'toko-buku', 'percetakan', 'warung', 'food-court', 'multi-cabang'];

    private const CITIES = ['jakarta', 'surabaya', 'bandung', 'medan', 'semarang', 'makassar', 'palembang', 'tangerang', 'depok', 'bekasi', 'bogor', 'malang', 'yogyakarta', 'solo', 'denpasar', 'balikpapan', 'samarinda', 'banjarmasin', 'pontianak', 'manado', 'padang', 'pekanbaru', 'batam', 'bandar-lampung', 'cirebon', 'tasikmalaya', 'purwokerto', 'kediri', 'madiun', 'jember', 'sidoarjo', 'gresik', 'mataram', 'kupang', 'ambon', 'jayapura', 'banda-aceh', 'serang', 'karawang', 'cilegon'];

    private const CAPABILITIES = ['kasir-cepat', 'stok-akurat', 'multi-cabang', 'laporan-laba', 'pembelian', 'retur', 'split-payment', 'approval', 'audit-stok', 'pelanggan', 'supplier', 'barcode', 'offline-sync', 'api', 'invoice', 'gudang', 'transfer-stok', 'role-user', 'backup', 'dashboard'];

    private const CONTEXTS = ['harian', 'real-time', 'otomatis', 'terintegrasi', 'aman'];

    public function totalUrls(): int
    {
        return $this->baseCombinationCount() * (count(self::INTENTS) + 1);
    }

    public function chunkCount(): int
    {
        return (int) ceil($this->totalUrls() / self::CHUNK_SIZE);
    }

    public function urlAt(int $ordinal): string
    {
        abort_if($ordinal < 0 || $ordinal >= $this->totalUrls(), 404);

        $baseCount = $this->baseCombinationCount();
        $sourceCode = $ordinal >= $baseCount * count(self::INTENTS);
        $intentIndex = $sourceCode ? null : intdiv($ordinal, $baseCount);
        $offset = $ordinal % $baseCount;
        [$industry, $city, $feature] = $this->combinationAt($offset);

        return $sourceCode
            ? route('pseo.source-code', compact('industry', 'city', 'feature'))
            : route('pseo.growth', ['intent' => self::INTENTS[$intentIndex], 'industry' => $industry, 'city' => $city, 'feature' => $feature]);
    }

    public function urlsForChunk(int $chunk): array
    {
        abort_if($chunk < 1 || $chunk > $this->chunkCount(), 404);
        $start = ($chunk - 1) * self::CHUNK_SIZE;
        $end = min($start + self::CHUNK_SIZE, $this->totalUrls());
        $urls = [];

        for ($ordinal = $start; $ordinal < $end; $ordinal++) {
            $urls[] = $this->urlAt($ordinal);
        }

        return $urls;
    }

    public function describe(string $industry, string $city, string $feature): array
    {
        abort_unless(in_array($industry, self::INDUSTRIES, true), 404);
        abort_unless(in_array($city, self::CITIES, true), 404);
        abort_unless($this->validFeatures()->contains($feature), 404);

        return [
            'industry' => Str::headline($industry),
            'city' => Str::headline($city),
            'feature' => Str::headline($feature),
        ];
    }

    public function validIntent(string $intent): bool
    {
        return in_array($intent, self::INTENTS, true);
    }

    private function baseCombinationCount(): int
    {
        return count(self::INDUSTRIES) * count(self::CITIES) * count(self::CAPABILITIES) * count(self::CONTEXTS);
    }

    private function combinationAt(int $offset): array
    {
        $featuresPerCity = count(self::CAPABILITIES) * count(self::CONTEXTS);
        $industryIndex = intdiv($offset, count(self::CITIES) * $featuresPerCity);
        $offset %= count(self::CITIES) * $featuresPerCity;
        $cityIndex = intdiv($offset, $featuresPerCity);
        $featureIndex = $offset % $featuresPerCity;
        $capability = self::CAPABILITIES[intdiv($featureIndex, count(self::CONTEXTS))];
        $context = self::CONTEXTS[$featureIndex % count(self::CONTEXTS)];

        return [self::INDUSTRIES[$industryIndex], self::CITIES[$cityIndex], $capability.'-'.$context];
    }

    private function validFeatures()
    {
        return collect(self::CAPABILITIES)->crossJoin(self::CONTEXTS)->map(fn (array $parts) => implode('-', $parts));
    }
}
