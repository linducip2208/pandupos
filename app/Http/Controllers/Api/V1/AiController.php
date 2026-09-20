<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiProviderConfig;
use App\Services\AiService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AiController extends Controller
{
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

        return response()->json(['data' => ['answer' => $answer, 'budget' => $ai->budget(TenantContext::idOrFail())]]);
    }

    public function usage(AiService $ai)
    {
        $this->authorize('viewAny', AiProviderConfig::class);

        return response()->json(['data' => $ai->budget(TenantContext::idOrFail())]);
    }

    public function anomalies(AiService $ai)
    {
        $this->authorize('viewAny', AiProviderConfig::class);

        return response()->json(['data' => $ai->detectAnomalies(TenantContext::idOrFail())]);
    }
}
