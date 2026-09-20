<?php

namespace App\Http\Controllers;

use App\Models\HmsAppointment;
use App\Models\HmsDoctor;
use App\Models\HmsInvoice;
use App\Models\HmsPatient;
use App\Models\ProductVariant;
use App\Services\HmsService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class HmsWorkspaceController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', HmsPatient::class);

        return view('hms.index', [
            'appointments' => HmsAppointment::query()->with(['patient', 'doctor'])->orderBy('scheduled_at')->limit(100)->get(),
            'patients' => HmsPatient::query()->orderByDesc('id')->limit(100)->get(),
            'doctors' => HmsDoctor::query()->where('is_active', true)->orderBy('name')->get(),
            'invoices' => HmsInvoice::query()->orderByDesc('id')->limit(50)->get(),
            'variants' => ProductVariant::query()->orderBy('sku')->limit(300)->get(),
        ]);
    }

    public function storePatient(Request $request, HmsService $hms)
    {
        $this->authorize('create', HmsPatient::class);
        $data = $request->validate([
            'name' => 'required|string|max:160', 'phone' => 'nullable|string|max:64',
            'birth_date' => 'nullable|date', 'gender' => 'nullable|string|max:16',
        ]);
        $hms->registerPatient(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Pasien '.$data['name'].' terdaftar.');
    }

    public function storeDoctor(Request $request, HmsService $hms)
    {
        $this->authorize('create', HmsPatient::class);
        $data = $request->validate([
            'name' => 'required|string|max:160', 'specialization' => 'nullable|string|max:128',
            'consultation_fee' => 'required|numeric|min:0',
        ]);
        $hms->registerDoctor(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Dokter '.$data['name'].' terdaftar.');
    }

    public function schedule(Request $request, HmsService $hms)
    {
        $this->authorize('create', HmsPatient::class);
        $data = $request->validate([
            'patient_id' => 'required|integer', 'doctor_id' => 'required|integer',
            'scheduled_at' => 'required|date', 'duration_minutes' => 'nullable|integer|min:5',
        ]);
        $hms->schedule(TenantContext::idOrFail(), (int) $data['patient_id'], (int) $data['doctor_id'], $data['scheduled_at'], (int) ($data['duration_minutes'] ?? 30), $request->user()->id);

        return back()->with('status', 'Jadwal kunjungan dibuat.');
    }

    public function transitionAppointment(Request $request, HmsService $hms, int $appointment)
    {
        $model = HmsAppointment::query()->findOrFail($appointment);
        $this->authorize('manage', $model->patient);
        $data = $request->validate(['to' => 'required|string']);
        $hms->transitionAppointment($model, $data['to'], $request->user()->id);

        return back()->with('status', 'Status kunjungan diperbarui.');
    }

    public function storeRecord(Request $request, HmsService $hms, int $appointment)
    {
        $model = HmsAppointment::query()->findOrFail($appointment);
        $this->authorize('manage', $model->patient);
        $data = $request->validate(['diagnosis' => 'required|string', 'prescription' => 'nullable|string']);
        $hms->createRecord(TenantContext::idOrFail(), $model->id, $data, $request->user()->id);

        return back()->with('status', 'Rekam medis tersimpan.');
    }

    public function storeInvoice(Request $request, HmsService $hms)
    {
        $this->authorize('create', HmsPatient::class);
        $data = $request->validate([
            'patient_id' => 'required|integer', 'appointment_id' => 'nullable|integer',
            'consultation_fee' => 'required|numeric|min:0', 'discount' => 'nullable|numeric|min:0',
            'pharmacy' => 'nullable|array', 'pharmacy.*.variant_id' => 'required_with:pharmacy|integer',
            'pharmacy.*.quantity' => 'required_with:pharmacy|numeric|gt:0',
        ]);
        $hms->createInvoice(TenantContext::idOrFail(), (int) $data['patient_id'], $data, $request->user()->id);

        return back()->with('status', 'Tagihan dibuat.');
    }

    public function payInvoice(Request $request, HmsService $hms, int $invoice)
    {
        $model = HmsInvoice::query()->findOrFail($invoice);
        $this->authorize('manage', $model->patient);
        $data = $request->validate(['amount' => 'required|numeric|gt:0']);
        $hms->payInvoice($model, (float) $data['amount'], $request->user()->id);

        return back()->with('status', 'Pembayaran dicatat.');
    }
}
