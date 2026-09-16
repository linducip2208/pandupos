<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Integrations\Adapters\OpenAICompatibleAdapter;
use App\Models\IntegrationFeatureAssignment;
use App\Models\IntegrationProvider;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrationProviderController extends Controller
{
    public function index()
    {
        return view('platform.integrations.index', [
            'providers' => IntegrationProvider::with('tenant')->latest()->paginate(20),
            'allProviders' => IntegrationProvider::with('tenant')->orderBy('name')->get(),
            'assignments' => IntegrationFeatureAssignment::withoutGlobalScopes()->with(['tenant', 'provider'])->latest()->get(),
            'tenants' => Tenant::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['is_active'] = $request->boolean('is_active');
        $data['extra_headers'] = $this->json($data['extra_headers'] ?? null);
        $data['settings'] = $this->json($data['settings'] ?? null);
        IntegrationProvider::create($data);

        return back()->with('status', 'Provider integrasi ditambahkan.');
    }

    public function update(Request $request, IntegrationProvider $provider)
    {
        $data = $this->validated($request, $provider);
        $data['is_active'] = $request->boolean('is_active');
        $data['extra_headers'] = $this->json($data['extra_headers'] ?? null);
        $data['settings'] = $this->json($data['settings'] ?? null);
        if (blank($data['api_key_encrypted'] ?? null)) {
            unset($data['api_key_encrypted']);
        }
        $provider->update($data);

        return back()->with('status', 'Provider integrasi diperbarui.');
    }

    public function destroy(IntegrationProvider $provider)
    {
        $provider->delete();

        return back()->with('status', 'Provider integrasi dihapus.');
    }

    public function discover(IntegrationProvider $provider)
    {
        abort_unless($provider->api_format === 'openai_compatible', 422, 'Discovery model hanya tersedia untuk format OpenAI-compatible.');
        $models = app(OpenAICompatibleAdapter::class, ['provider' => $provider])->discoverModels();

        return back()->with('status', count($models).' model ditemukan.')->with('discovered_models', $models);
    }

    public function assign(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'feature_key' => ['required', 'string', 'max:120'],
            'integration_provider_id' => ['required', 'exists:integration_providers,id'],
            'model_name' => ['nullable', 'string', 'max:255'],
            'input_rate' => ['nullable', 'numeric', 'min:0'],
            'output_rate' => ['nullable', 'numeric', 'min:0'],
        ]);
        $provider = IntegrationProvider::findOrFail($data['integration_provider_id']);
        abort_if($provider->tenant_id && (int) $provider->tenant_id !== (int) $data['tenant_id'], 422, 'Provider bukan milik tenant yang dipilih.');
        IntegrationFeatureAssignment::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $data['tenant_id'], 'feature_key' => $data['feature_key']],
            $data
        );

        return back()->with('status', 'Provider fitur diperbarui.');
    }

    private function validated(Request $request, ?IntegrationProvider $provider = null): array
    {
        return $request->validate([
            'tenant_id' => ['nullable', 'exists:tenants,id'],
            'integration_type' => ['required', Rule::in(['payment', 'notification', 'storage', 'webhook', 'ai'])],
            'name' => ['required', 'string', 'max:120', Rule::unique('integration_providers')->where(fn ($query) => $query->where('tenant_id', $request->input('tenant_id'))->where('integration_type', $request->input('integration_type')))->ignore($provider)],
            'api_format' => ['required', Rule::in(['redirect', 'embedded', 'qr', 'rest', 'openai_compatible', 'anthropic_format', 'gemini_format'])],
            'base_url' => ['nullable', 'url', 'max:2048'],
            'api_key_encrypted' => ['nullable', 'string', 'max:4096'],
            'extra_headers' => ['nullable', 'json'],
            'settings' => ['nullable', 'json'],
        ]);
    }

    private function json(?string $value): ?array
    {
        return filled($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : null;
    }
}
