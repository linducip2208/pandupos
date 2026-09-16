<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\TenantModule;
use App\Support\ModuleManifestValidator;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePlatform($request, 'platform.modules.manage');
        $loaded = ModuleManifestValidator::loadFromDisk(base_path('Modules'));
        $modules = Module::orderBy('slug')->get()->map(function ($m) {
            $m->enabled_tenants = TenantModule::withoutGlobalScopes()->where('module_id', $m->id)->where('enabled', true)->count();

            return $m;
        });

        return view('platform.modules.index', ['modules' => $modules, 'manifests' => $loaded['manifests'], 'errors' => $loaded['errors']]);
    }

    protected function authorizePlatform(Request $request, string $permission): void
    {
        if ($request->user()->is_platform_admin) {
            return;
        }
        abort_unless($request->user()->can($permission), 403);
    }
}
