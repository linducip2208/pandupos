<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiTokenController extends Controller
{
    public const ABILITIES = ['sales:write', 'catalog:write', 'purchase:write', 'inventory:write', 'reports:read', 'sync:write'];

    public function index(Request $request): View
    {
        return view('tokens.index', [
            'tokens' => $request->user()->tokens()->latest()->get(),
            'abilities' => self::ABILITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'in:'.implode(',', self::ABILITIES)],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
        $plain = $request->user()->createToken(
            $data['name'],
            $data['abilities'] ?? ['sales:write'],
            now()->addDays((int) ($data['expires_days'] ?? 30))
        )->plainTextToken;

        return back()->with('token', $plain)->with('status', 'Token dibuat. Salin sekarang — token penuh tidak ditampilkan lagi.');
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return back()->with('status', 'Token dicabut.');
    }
}
