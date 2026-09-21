<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KitchenItem;
use App\Models\KitchenTicket;
use App\Models\RestaurantBooking;
use App\Models\SalesInvoice;
use App\Services\RestaurantService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    public function tickets()
    {
        $this->authorize('viewAny', KitchenTicket::class);

        return response()->json(['data' => KitchenTicket::query()->with(['items', 'table'])->orderByDesc('id')->limit(100)->get()]);
    }

    public function fire(Request $request, RestaurantService $restaurant)
    {
        $this->authorize('create', KitchenTicket::class);
        $data = $request->validate([
            'order_type' => 'required|in:dine_in,takeaway,delivery',
            'table_id' => 'nullable|integer', 'customer_name' => 'nullable|string|max:160',
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|gt:0',
        ]);

        return response()->json(['data' => $restaurant->fireOrder(TenantContext::idOrFail(), $data, $request->user()->id)->load('items')], 201);
    }

    public function advance(Request $request, RestaurantService $restaurant, int $ticket)
    {
        $model = KitchenTicket::query()->findOrFail($ticket);
        $this->authorize('manage', $model);
        $data = $request->validate(['to' => 'required|string']);

        return response()->json(['data' => $restaurant->advanceTicket($model, $data['to'], $request->user()->id)]);
    }

    public function close(Request $request, RestaurantService $restaurant, int $ticket)
    {
        $model = KitchenTicket::query()->findOrFail($ticket);
        $this->authorize('manage', $model);
        $data = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|string|max:32',
            'payments.*.amount' => 'required|numeric|gt:0',
        ]);
        $ticket = $restaurant->closeTicket($model, $data['payments'], $request->user()->id);
        $invoiceNo = SalesInvoice::withoutGlobalScopes()->find($ticket->sales_invoice_id)?->invoice_no;

        return response()->json(['data' => $ticket->fresh('items'), 'invoice_no' => $invoiceNo]);
    }

    public function book(Request $request, RestaurantService $restaurant)
    {
        $this->authorize('create', KitchenTicket::class);
        $data = $request->validate([
            'table_id' => 'required|integer', 'customer_name' => 'required|string|max:160',
            'phone' => 'nullable|string|max:64', 'starts_at' => 'required|date',
            'party_size' => 'nullable|integer|min:1',
        ]);

        return response()->json(['data' => $restaurant->bookTable(TenantContext::idOrFail(), $data, $request->user()->id)], 201);
    }

    public function transitionBooking(Request $request, RestaurantService $restaurant, int $booking)
    {
        $model = RestaurantBooking::query()->findOrFail($booking);
        $this->authorize('create', KitchenTicket::class);
        $data = $request->validate(['to' => 'required|string']);

        return response()->json(['data' => $restaurant->transitionBooking($model, $data['to'], $request->user()->id)]);
    }

    public function refire(Request $request, RestaurantService $restaurant, int $item)
    {
        $model = KitchenItem::query()->findOrFail($item);
        $this->authorize('manage', $model->ticket);
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return response()->json(['data' => $restaurant->refireItem($model, $data['reason'], $request->user()->id)]);
    }
}
