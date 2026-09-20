<?php

namespace App\Http\Controllers;

use App\Models\GymMember;
use App\Models\GymMembership;
use App\Models\GymPackage;
use App\Models\GymTrainer;
use App\Services\GymService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class GymWorkspaceController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', GymMembership::class);

        return view('gym.index', [
            'memberships' => GymMembership::query()->with(['member', 'package'])->orderByDesc('id')->limit(100)->get(),
            'members' => GymMember::query()->where('status', 'active')->orderBy('code')->limit(200)->get(),
            'packages' => GymPackage::query()->where('is_active', true)->orderBy('name')->get(),
            'trainers' => GymTrainer::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storePackage(Request $request, GymService $gym)
    {
        $this->authorize('create', GymMembership::class);
        $data = $request->validate([
            'name' => 'required|string|max:128', 'duration_days' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0', 'visits_limit' => 'nullable|integer|min:1',
        ]);
        $gym->createPackage(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Paket '.$data['name'].' dibuat.');
    }

    public function storeMember(Request $request, GymService $gym)
    {
        $this->authorize('create', GymMembership::class);
        $data = $request->validate(['name' => 'required|string|max:160', 'phone' => 'nullable|string|max:64']);
        $gym->registerMember(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Member '.$data['name'].' terdaftar.');
    }

    public function subscribe(Request $request, GymService $gym)
    {
        $this->authorize('create', GymMembership::class);
        $data = $request->validate([
            'member_id' => 'required|integer', 'package_id' => 'required|integer',
            'starts_on' => 'required|date',
        ]);
        $gym->subscribe(TenantContext::idOrFail(), (int) $data['member_id'], (int) $data['package_id'], $data['starts_on'], $request->user()->id);

        return back()->with('status', 'Langganan dibuat.');
    }

    public function pay(Request $request, GymService $gym, int $membership)
    {
        $model = GymMembership::query()->findOrFail($membership);
        $this->authorize('manage', $model);
        $data = $request->validate(['amount' => 'required|numeric|gt:0']);
        $gym->recordPayment($model, (float) $data['amount'], $request->user()->id);

        return back()->with('status', 'Pembayaran dicatat.');
    }

    public function checkIn(Request $request, GymService $gym, int $membership)
    {
        $model = GymMembership::query()->findOrFail($membership);
        $this->authorize('manage', $model);
        $data = $request->validate(['trainer_id' => 'nullable|integer']);
        $gym->checkIn($model, isset($data['trainer_id']) ? (int) $data['trainer_id'] : null, $request->user()->id);

        return back()->with('status', 'Check-in tercatat.');
    }

    public function cancel(Request $request, GymService $gym, int $membership)
    {
        $model = GymMembership::query()->findOrFail($membership);
        $this->authorize('manage', $model);
        $gym->cancel($model, $request->user()->id);

        return back()->with('status', 'Langganan dibatalkan.');
    }
}
