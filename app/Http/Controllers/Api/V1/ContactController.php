<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\UsageLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $q = Contact::orderBy('name');
        if ($type = $request->get('type')) {
            $q->where('type', $type);
        }
        if ($s = $request->get('search')) {
            $q->where('name', 'like', "%{$s}%");
        }

        return response()->json($q->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request, UsageLimitService $usage)
    {
        $data = $request->validate([
            'type' => 'required|in:customer,supplier,both',
            'name' => 'required|string|max:255',
            'company' => 'nullable|string', 'email' => 'nullable|email',
            'phone' => 'nullable|string', 'credit_limit' => 'nullable|numeric|min:0',
        ]);

        $tenantId = TenantContext::id();
        // A "both" contact consumes one slot from each meter.
        if ($data['type'] === 'both') {
            $usage->assertCanCreate($tenantId, 'customers.max');
            $usage->assertCanCreate($tenantId, 'suppliers.max');
        } else {
            $usage->assertCanCreate($tenantId, $data['type'] === 'customer' ? 'customers.max' : 'suppliers.max');
        }

        return response()->json(Contact::create($data), 201);
    }
}
