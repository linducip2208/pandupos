@extends('layouts.tabler')
@section('title', 'AI Assistant')
@section('header', 'AI · Provider, Budget & Anomali')
@section('content')
@if(session('status'))<div class="alert alert-success">{{ session('status') }}@if(session('ai_answer'))<hr><strong>[{{ session('ai_feature') }}]</strong> {{ session('ai_answer') }}@endif</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<div class="row row-cards">
<div class="col-12 col-xl-5"><div class="card"><div class="card-header"><h3 class="card-title">Provider (budget {{ $budget['used'] }}{{ $budget['cap'] ? '/'.$budget['cap'] : '' }} token bulan ini)</h3></div><div class="table-responsive"><table class="table card-table"><tbody>
@foreach($configs as $c)<tr><td><strong>{{ $c->provider }}</strong><div class="small text-secondary">{{ $c->model }}{{ $c->monthly_token_cap ? ' · cap '.$c->monthly_token_cap : '' }}</div></td><td>{{ $c->is_active ? 'Aktif' : 'Nonaktif' }}</td></tr>@endforeach
</tbody></table></div><div class="card-body border-top"><form method="POST" action="{{ route('ai.config.store') }}" class="row g-2">@csrf
<div class="col-6"><label class="form-label">Provider</label><select name="provider" class="form-select">@foreach(['openai'=>'OpenAI','anthropic'=>'Anthropic','google'=>'Google','openrouter'=>'OpenRouter','custom'=>'Custom (OpenAI-compatible)'] as $v=>$l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
<div class="col-6"><label class="form-label">Model</label><input name="model" class="form-control" placeholder="gpt-4o-mini" required></div>
<div class="col-6"><label class="form-label">API key</label><input name="api_key" type="password" class="form-control" required autocomplete="off"></div>
<div class="col-6"><label class="form-label">Base URL (bila perlu)</label><input name="base_url" class="form-control" placeholder="https://..."></div>
<div class="col-12"><button class="btn btn-primary w-100">Simpan (terenkripsi)</button></div></form></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Tanya asisten</h3></div><div class="card-body"><form method="POST" action="{{ route('ai.ask') }}" class="row g-2">@csrf
<div class="col-12"><label class="form-label">Fitur</label><select name="feature" class="form-select">@foreach($features as $f)<option value="{{ $f }}">{{ $f }}</option>@endforeach</select></div>
<div class="col-12"><label class="form-label">Prompt</label><textarea name="prompt" rows="3" class="form-control" required maxlength="4000"></textarea></div>
<div class="col-12"><button class="btn btn-primary w-100">Kirim</button></div></form></div></div></div>
<div class="col-12 col-xl-7"><div class="card"><div class="card-header"><h3 class="card-title">Deteksi anomali (deterministik, tanpa LLM)</h3></div><div class="table-responsive"><table class="table card-table"><tbody>
@forelse($anomalies as $a)<tr><td><span class="badge">{{ $a['type'] }}</span></td><td>{{ $a['message'] }}</td></tr>
@empty<tr><td class="text-center text-secondary">Tidak ada anomali terdeteksi.</td></tr>@endforelse
</tbody></table></div></div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Pemakaian (100 terbaru)</h3></div><div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Waktu</th><th>Fitur</th><th class="text-end">Token</th></tr></thead><tbody>
@forelse($usages as $u)<tr><td class="small">{{ $u->created_at->toDateTimeString() }}</td><td>{{ $u->feature }} <span class="text-secondary small">{{ $u->provider }}</span></td><td class="text-end">{{ $u->tokens_in + $u->tokens_out }}</td></tr>
@empty<tr><td colspan="3" class="text-center text-secondary">Belum ada pemakaian.</td></tr>@endforelse
</tbody></table></div></div></div>
</div>
@endsection
