<?php

namespace App\Services;

use App\Models\HmsAppointment;
use App\Models\HmsDoctor;
use App\Models\HmsInvoice;
use App\Models\HmsPatient;
use App\Models\HmsRecord;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HMS: patients, doctors, conflict-guarded appointments, medical records,
 * invoices with consultation + pharmacy lines, and payment that dispenses
 * pharmacy stock exactly once at paid-in-full.
 */
final class HmsService
{
    public function __construct(private StockService $stock, private AuditService $audit) {}

    public function registerPatient(int $tenantId, array $data, ?int $actorId = null): HmsPatient
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Patient name is required.');
        $code = strtoupper(trim((string) ($data['code'] ?? ''))) ?: $this->nextPatientCode($tenantId);
        abort_if(HmsPatient::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->exists(), 422, 'Patient code already exists.');

        $patient = HmsPatient::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
            'birth_date' => $data['birth_date'] ?? null, 'gender' => $data['gender'] ?? null,
            'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null,
            'blood_type' => $data['blood_type'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'hms.patient.registered', HmsPatient::class, $patient->id, null, ['code' => $code]);

        return $patient;
    }

    public function registerDoctor(int $tenantId, array $data, ?int $actorId = null): HmsDoctor
    {
        $name = trim((string) ($data['name'] ?? ''));
        abort_if($name === '', 422, 'Doctor name is required.');
        $fee = round((float) ($data['consultation_fee'] ?? 0), 2);
        abort_if($fee < 0, 422, 'Consultation fee cannot be negative.');

        $doctor = HmsDoctor::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'name' => $name, 'specialization' => $data['specialization'] ?? null,
            'consultation_fee' => $fee, 'user_id' => $data['user_id'] ?? null, 'is_active' => true,
        ]);
        $this->audit->log($tenantId, $actorId, 'hms.doctor.registered', HmsDoctor::class, $doctor->id, null, ['name' => $name]);

        return $doctor;
    }

    public function schedule(int $tenantId, int $patientId, int $doctorId, string $scheduledAt, int $durationMinutes = 30, ?int $actorId = null, ?string $notes = null): HmsAppointment
    {
        HmsPatient::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($patientId);
        $doctor = HmsDoctor::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($doctorId);
        abort_if(! $doctor->is_active, 422, 'Doctor is not active.');
        abort_if(strtotime($scheduledAt) < time() - 60, 422, 'Appointments cannot be scheduled in the past.');
        $durationMinutes = max(5, $durationMinutes);
        $start = strtotime($scheduledAt);
        $end = $start + $durationMinutes * 60;
        $conflict = HmsAppointment::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('doctor_id', $doctorId)
            ->whereIn('status', [HmsAppointment::SCHEDULED, HmsAppointment::CHECKED_IN])->get()
            ->first(function ($a) use ($start, $end) {
                $s = strtotime($a->scheduled_at);
                $e = $s + ((int) $a->duration_minutes) * 60;

                return $start < $e && $s < $end;
            });
        abort_if($conflict, 422, 'Doctor already has an appointment overlapping this slot.');

        $appointment = HmsAppointment::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'patient_id' => $patientId, 'doctor_id' => $doctorId,
            'scheduled_at' => date('Y-m-d H:i:s', $start), 'duration_minutes' => $durationMinutes,
            'status' => HmsAppointment::SCHEDULED, 'notes' => $notes,
        ]);
        $this->audit->log($tenantId, $actorId, 'hms.appointment.scheduled', HmsAppointment::class, $appointment->id, null, ['at' => $scheduledAt]);

        return $appointment;
    }

    public function transitionAppointment(HmsAppointment $appointment, string $to, ?int $actorId = null): HmsAppointment
    {
        $allowed = [
            HmsAppointment::SCHEDULED => [HmsAppointment::CHECKED_IN, HmsAppointment::CANCELLED, HmsAppointment::NO_SHOW],
            HmsAppointment::CHECKED_IN => [HmsAppointment::COMPLETED, HmsAppointment::CANCELLED],
            HmsAppointment::COMPLETED => [],
            HmsAppointment::CANCELLED => [HmsAppointment::SCHEDULED],
            HmsAppointment::NO_SHOW => [HmsAppointment::SCHEDULED],
        ];
        abort_unless(in_array($to, [HmsAppointment::SCHEDULED, HmsAppointment::CHECKED_IN, HmsAppointment::COMPLETED, HmsAppointment::CANCELLED, HmsAppointment::NO_SHOW], true), 422, 'Invalid appointment status.');
        abort_unless(in_array($to, $allowed[$appointment->status] ?? [], true), 422, "Appointment cannot move from [{$appointment->status}] to [{$to}].");
        $before = $appointment->toArray();
        $appointment->update(['status' => $to]);
        $this->audit->log($appointment->tenant_id, $actorId, 'hms.appointment.transitioned', HmsAppointment::class, $appointment->id, $before, $appointment->fresh()->toArray());

        return $appointment->fresh();
    }

    public function createRecord(int $tenantId, int $appointmentId, array $data, ?int $actorId = null): HmsRecord
    {
        $appointment = HmsAppointment::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($appointmentId);
        abort_unless(in_array($appointment->status, [HmsAppointment::CHECKED_IN, HmsAppointment::COMPLETED], true), 422, 'Records require a checked-in or completed visit.');
        abort_if(HmsRecord::withoutGlobalScopes()->where('appointment_id', $appointment->id)->exists(), 422, 'This visit already has a medical record.');
        $diagnosis = trim((string) ($data['diagnosis'] ?? ''));
        abort_if($diagnosis === '', 422, 'Diagnosis is required.');

        $record = HmsRecord::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'appointment_id' => $appointment->id,
            'patient_id' => $appointment->patient_id, 'doctor_id' => $appointment->doctor_id,
            'diagnosis' => $diagnosis, 'prescription' => $data['prescription'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);
        $this->audit->log($tenantId, $actorId, 'hms.record.created', HmsRecord::class, $record->id, null, ['appointment_id' => $appointment->id]);

        return $record;
    }

    /**
     * @param  array<int, array{variant_id:int,quantity:float,unit_price?:float}>  $pharmacy
     */
    public function createInvoice(int $tenantId, int $patientId, array $data, ?int $actorId = null): HmsInvoice
    {
        return DB::transaction(function () use ($tenantId, $patientId, $data, $actorId) {
            HmsPatient::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($patientId);
            $appointmentId = isset($data['appointment_id']) ? (int) $data['appointment_id'] : null;
            if ($appointmentId) {
                HmsAppointment::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($appointmentId);
            }
            $consult = round((float) ($data['consultation_fee'] ?? 0), 2);
            abort_if($consult < 0, 422, 'Consultation fee cannot be negative.');
            $discount = round((float) ($data['discount'] ?? 0), 2);
            abort_if($discount < 0, 422, 'Discount cannot be negative.');
            $warehouseId = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->value('id');
            $pharmacyTotal = 0.0;
            $resolved = [];
            foreach ($data['pharmacy'] ?? [] as $row) {
                $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail((int) ($row['variant_id'] ?? 0));
                $qty = round((float) ($row['quantity'] ?? 0), 3);
                abort_if($qty <= 0, 422, 'Pharmacy quantity must be positive.');
                $price = isset($row['unit_price']) ? round((float) $row['unit_price'], 2) : round((float) $variant->sell_price, 2);
                $available = $this->stock->onHand($tenantId, $warehouseId, $variant->id);
                abort_if($available + 0.0005 < $qty, 422, "Insufficient pharmacy stock for [{$variant->sku}].");
                $pharmacyTotal = round($pharmacyTotal + $qty * $price, 2);
                $resolved[] = ['variant_id' => $variant->id, 'quantity' => $qty, 'price' => $price];
            }
            $total = round($consult + $pharmacyTotal - min($discount, $consult + $pharmacyTotal), 2);
            $invoice = HmsInvoice::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'number' => $this->nextNumber(), 'patient_id' => $patientId,
                'appointment_id' => $appointmentId, 'consultation_fee' => $consult,
                'pharmacy_total' => $pharmacyTotal, 'discount' => $discount, 'total' => $total,
                'paid' => 0, 'balance' => $total, 'status' => $total == 0.0 ? 'paid' : 'unpaid',
            ]);
            foreach ($resolved as $row) {
                $invoice->lines()->create([
                    'tenant_id' => $tenantId, 'product_variant_id' => $row['variant_id'],
                    'quantity' => $row['quantity'], 'unit_price' => $row['price'],
                ]);
            }
            $this->audit->log($tenantId, $actorId, 'hms.invoice.created', HmsInvoice::class, $invoice->id, null, ['number' => $invoice->number, 'total' => $total]);

            return $invoice->load('lines');
        });
    }

    /** Partial payments allowed; pharmacy dispenses once at paid-in-full. */
    public function payInvoice(HmsInvoice $invoice, float $amount, ?int $actorId = null): HmsInvoice
    {
        return DB::transaction(function () use ($invoice, $amount, $actorId) {
            $locked = HmsInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            $amount = round($amount, 2);
            abort_if($amount <= 0 || $amount > (float) $locked->balance + 0.005, 422, 'Payment must be positive and cannot exceed the balance.');
            $paid = round((float) $locked->paid + $amount, 2);
            $balance = round((float) $locked->total - $paid, 2);
            $fullyPaid = $balance <= 0.005;
            if ($fullyPaid && $locked->status !== 'paid') {
                $warehouseId = Warehouse::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->orderBy('id')->value('id');
                foreach ($locked->lines as $line) {
                    $this->stock->decrease($locked->tenant_id, $warehouseId, $line->product_variant_id, (float) $line->quantity, 'hms_dispense', $locked->id);
                }
            }
            $before = $locked->toArray();
            $locked->update(['paid' => $paid, 'balance' => max(0, $balance), 'status' => $fullyPaid ? 'paid' : 'partial']);
            $this->audit->log($locked->tenant_id, $actorId, 'hms.invoice.paid', HmsInvoice::class, $locked->id, $before, $locked->fresh()->toArray());

            return $locked->fresh('lines');
        });
    }

    private function nextPatientCode(int $tenantId): string
    {
        $n = HmsPatient::withoutGlobalScopes()->where('tenant_id', $tenantId)->count() + 1;

        return 'PASIEN-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    private function nextNumber(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $no = 'HMS-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
            if (! HmsInvoice::withoutGlobalScopes()->where('number', $no)->exists()) {
                return $no;
            }
        }

        return 'HMS-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
