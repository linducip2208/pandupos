<?php

namespace App\Http\Controllers;

use App\Models\AiProviderConfig;
use App\Models\AiUsage;
use App\Services\AiService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AiWorkspaceController extends Controller
{
    public function index(AiService $ai)
    {
        $this->authorize('viewAny', AiProviderConfig::class);
        $tenantId = TenantContext::idOrFail();

        return view('ai.index', [
            'configs' => AiProviderConfig::query()->orderBy('id')->get(),
            'budget' => $ai->budget($tenantId),
            'usages' => AiUsage::query()->orderByDesc('id')->limit(100)->get(),
            'anomalies' => $ai->detectAnomalies($tenantId),
            'features' => AiService::FEATURES,
        ]);
    }

    public function storeConfig(Request $request, AiService $ai)
    {
        $this->authorize('create', AiProviderConfig::class);
        $data = $request->validate([
            'provider' => 'required|string', 'model' => 'required|string|max:128',
            'api_key' => 'required|string', 'base_url' => 'nullable|url|max:255',
            'monthly_token_cap' => 'nullable|integer|min:1',
        ]);
        $ai->configure(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Provider '.$data['provider'].' tersimpan (kunci terenkripsi).');
    }

    public function ask(Request $request, AiService $ai)
    {
        $this->authorize('create', AiProviderConfig::class);
        $data = $request->validate([
            'feature' => 'required|string', 'prompt' => 'required|string|max:4000',
        ]);
        $answer = $ai->ask(TenantContext::idOrFail(), $data['feature'], [
            ['role' => 'system', 'content' => 'You are PanduPOS assistant. Answer concisely in Indonesian.'],
            ['role' => 'user', 'content' => $data['prompt']],
        ], $request->user()->id);

        return back()->with('status', 'Jawaban AI:')->with('ai_answer', $answer)->with('ai_feature', $data['feature']);
    }
}
