<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HmsAppointment;
use App\Models\HmsInvoice;
use App\Models\HmsPatient;
use App\Services\HmsService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class HmsController extends Controller
{
    public function patients()
    {
        $this->authorize('viewAny', HmsPatient::class);

        return response()->json(['data' => HmsPatient::query()->orderByDesc('id')->limit(200)->get()]);
    }

    public function storePatient(Request $request, HmsService $hms)
    {
        $this->authorize('create', HmsPatient::class);
        $data = $request->validate([
            'name' => 'required|string|max:160', 'phone' => 'nullable|string|max:64',
            'birth_date' => 'nullable|date', 'gender' => 'nullable|string|max:16',
        ]);

        return response()->json(['data' => $hms->registerPatient(TenantContext::idOrFail(), $data, $request->user()->id)], 201);
    }

    public function schedule(Request $request, HmsService $hms)
    {
        $this->authorize('create', HmsPatient::class);
        $data = $request->validate([
            'patient_id' => 'required|integer', 'doctor_id' => 'required|integer',
            'scheduled_at' => 'required|date', 'duration_minutes' => 'nullable|integer|min:5',
        ]);

        return response()->json(['data' => $hms->schedule(TenantContext::idOrFail(), (int) $data['patient_id'], (int) $data['doctor_id'], $data['scheduled_at'], (int) ($data['duration_minutes'] ?? 30), $request->user()->id)], 201);
    }

    public function transitionAppointment(Request $request, HmsService $hms, int $appointment)
    {
        $model = HmsAppointment::query()->findOrFail($appointment);
        $this->authorize('manage', $model->patient);
        $data = $request->validate(['to' => 'required|string']);

        return response()->json(['data' => $hms->transitionAppointment($model, $data['to'], $request->user()->id)]);
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

        return response()->json(['data' => $hms->createInvoice(TenantContext::idOrFail(), (int) $data['patient_id'], $data, $request->user()->id)->load('lines')], 201);
    }

    public function payInvoice(Request $request, HmsService $hms, int $invoice)
    {
        $model = HmsInvoice::query()->findOrFail($invoice);
        $this->authorize('manage', $model->patient);
        $data = $request->validate(['amount' => 'required|numeric|gt:0']);

        return response()->json(['data' => $hms->payInvoice($model, (float) $data['amount'], $request->user()->id)->load('lines')]);
    }

    public function history(HmsPatient $patient)
    {
        $model = HmsPatient::query()->findOrFail($patient->id);
        $this->authorize('manage', $model);

        return response()->json(['data' => $model->load(['appointments.doctor', 'records'])]);
    }
}
