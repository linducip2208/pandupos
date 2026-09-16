<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\ModuleManifestValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.dashboard.view');

        $checks = [];
        $checks['php'] = ['label' => 'PHP '.PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '8.3.0', '>='), 'detail' => PHP_VERSION];
        $checks['laravel'] = ['label' => 'Laravel '.app()->version(), 'ok' => true, 'detail' => app()->version()];

        try {
            DB::connection()->getPdo();
            $checks['db'] = ['label' => 'Database', 'ok' => true, 'detail' => DB::connection()->getDatabaseName()];
        } catch (\Throwable $e) {
            $checks['db'] = ['label' => 'Database', 'ok' => false, 'detail' => $e->getMessage()];
        }

        try {
            cache()->put('health-check', 'ok', 10);
            $checks['cache'] = ['label' => 'Cache', 'ok' => cache()->get('health-check') === 'ok', 'detail' => config('cache.default')];
        } catch (\Throwable $e) {
            $checks['cache'] = ['label' => 'Cache', 'ok' => false, 'detail' => $e->getMessage()];
        }

        $checks['queue'] = ['label' => 'Queue ('.config('queue.default').')', 'ok' => true, 'detail' => 'driver: '.config('queue.default')];
        $checks['storage'] = ['label' => 'Storage writable', 'ok' => is_writable(storage_path()), 'detail' => storage_path()];
        $checks['failed_jobs'] = ['label' => 'Failed jobs', 'ok' => true, 'detail' => (string) DB::table('failed_jobs')->count().' failed'];

        $manifests = ModuleManifestValidator::loadFromDisk(base_path('Modules'));
        $checks['modules'] = ['label' => 'Modules', 'ok' => empty($manifests['errors']), 'detail' => count($manifests['manifests']).' manifests, '.count($manifests['errors']).' errors'];

        return view('platform.health', compact('checks'));
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
