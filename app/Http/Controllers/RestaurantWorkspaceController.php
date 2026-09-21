<?php

namespace App\Http\Controllers;

use App\Models\KitchenItem;
use App\Models\KitchenTicket;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\RestaurantBooking;
use App\Models\RestaurantFloor;
use App\Models\SalesInvoice;
use App\Services\RestaurantService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class RestaurantWorkspaceController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', KitchenTicket::class);

        return view('restaurant.index', [
            'floors' => RestaurantFloor::query()->with('tables')->orderBy('sort_order')->get(),
            'bookings' => RestaurantBooking::query()->with(['table'])->orderBy('starts_at')->limit(100)->get(),
            'tickets' => KitchenTicket::query()->whereIn('status', ['queued', 'preparing', 'ready'])->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function kitchen()
    {
        $this->authorize('viewAny', KitchenTicket::class);
        $tickets = KitchenTicket::query()->with(['items.variant', 'table'])->whereIn('status', ['queued', 'preparing', 'ready'])->orderBy('id')->get();

        return view('restaurant.kitchen', ['tickets' => $tickets]);
    }

    public function modifiers()
    {
        $this->authorize('viewAny', KitchenTicket::class);

        return view('restaurant.modifiers', [
            'groups' => ModifierGroup::query()->with('options')->orderBy('name')->get(),
            'products' => Product::query()->orderBy('name')->limit(200)->get(),
        ]);
    }

    public function storeFloor(Request $request, RestaurantService $restaurant)
    {
        $this->authorize('create', KitchenTicket::class);
        $data = $request->validate(['name' => 'required|string|max:128']);
        $restaurant->createFloor(TenantContext::idOrFail(), $data['name'], $request->user()->id);

        return back()->with('status', 'Lantai ditambahkan.');
    }

    public function storeTable(Request $request, RestaurantService $restaurant)
    {
        $this->authorize('create', KitchenTicket::class);
        $data = $request->validate([
            'code' => 'required|string|max:32', 'name' => 'nullable|string|max:128',
            'floor_id' => 'nullable|integer', 'seats' => 'nullable|integer|min:1',
        ]);
        $restaurant->createTable(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Meja '.$data['code'].' ditambahkan.');
    }

    public function storeBooking(Request $request, RestaurantService $restaurant)
    {
        $this->authorize('create', KitchenTicket::class);
        $data = $request->validate([
            'table_id' => 'required|integer', 'customer_name' => 'required|string|max:160',
            'phone' => 'nullable|string|max:64', 'starts_at' => 'required|date',
            'party_size' => 'nullable|integer|min:1',
        ]);
        $restaurant->bookTable(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Booking dicatat.');
    }

    public function transitionBooking(Request $request, RestaurantService $restaurant, int $booking)
    {
        $model = RestaurantBooking::query()->findOrFail($booking);
        $this->authorize('create', KitchenTicket::class);
        $data = $request->validate(['to' => 'required|string']);
        $restaurant->transitionBooking($model, $data['to'], $request->user()->id);

        return back()->with('status', 'Booking diperbarui.');
    }

    public function storeModifierGroup(Request $request, RestaurantService $restaurant)
    {
        $this->authorize('create', KitchenTicket::class);
        $data = $request->validate([
            'name' => 'required|string|max:128',
            'min_select' => 'nullable|integer|min:0', 'max_select' => 'nullable|integer|min:1',
        ]);
        $restaurant->createModifierGroup(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Grup modifier dibuat.');
    }

    public function storeModifier(Request $request, RestaurantService $restaurant, int $group)
    {
        $this->authorize('create', KitchenTicket::class);
        $groupModel = ModifierGroup::query()->findOrFail($group);
        $data = $request->validate(['name' => 'required|string|max:128', 'price_delta' => 'required|numeric']);
        $restaurant->createModifier(TenantContext::idOrFail(), $groupModel->id, $data, $request->user()->id);

        return back()->with('status', 'Modifier ditambahkan.');
    }

    public function linkProduct(Request $request, RestaurantService $restaurant)
    {
        $this->authorize('create', KitchenTicket::class);
        $data = $request->validate(['product_id' => 'required|integer', 'group_id' => 'required|integer']);
        $restaurant->linkProduct(TenantContext::idOrFail(), (int) $data['product_id'], (int) $data['group_id'], $request->user()->id);

        return back()->with('status', 'Grup ditautkan ke produk.');
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
        $ticket = $restaurant->fireOrder(TenantContext::idOrFail(), $data, $request->user()->id);

        return back()->with('status', 'Order '.$ticket->number.' masuk dapur.');
    }

    public function advance(Request $request, RestaurantService $restaurant, int $ticket)
    {
        $model = KitchenTicket::query()->findOrFail($ticket);
        $this->authorize('manage', $model);
        $data = $request->validate(['to' => 'required|string']);
        $restaurant->advanceTicket($model, $data['to'], $request->user()->id);

        return back()->with('status', 'Tiket diperbarui.');
    }

    public function refire(Request $request, RestaurantService $restaurant, int $item)
    {
        $model = KitchenItem::query()->findOrFail($item);
        $this->authorize('manage', $model->ticket);
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $restaurant->refireItem($model, $data['reason'], $request->user()->id);

        return back()->with('status', 'Item dimasak ulang.');
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

        return back()->with('status', 'Tiket ditutup. Invoice '.$invoiceNo.'.');
    }
}
