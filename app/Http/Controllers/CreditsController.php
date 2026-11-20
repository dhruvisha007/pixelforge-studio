<?php

namespace App\Http\Controllers;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditsController
{
    /** Balance, the fake checkout and the user's ledger, newest first. */
    public function index(Request $request): Response
    {
        return Inertia::render('Credits', [
            'packs' => config('studio.credit_packs_usd'),
            'transactions' => $request->user()->creditTransactions()
                ->latest('id')
                ->paginate(20)
                ->through(fn (CreditTransaction $transaction) => [
                    'id' => $transaction->id,
                    'type' => $transaction->type->value,
                    'description' => $transaction->description,
                    'amount' => Money::format($transaction->amount_nanodollars),
                    'amount_exact' => Money::toUsd($transaction->amount_nanodollars),
                    'created_at' => $transaction->created_at->toIso8601String(),
                ]),
        ]);
    }

    /** Fake checkout: adds the credit straight away. */
    public function purchase(Request $request): RedirectResponse
    {
        // Only the fixed packs from config/studio.php, never an amount from the browser.
        $validated = $request->validate([
            'pack' => ['required', 'string', Rule::in(config('studio.credit_packs_usd'))],
        ]);

        $request->user()->creditTransactions()->create([
            'type' => CreditTransactionType::Purchase,
            'amount_nanodollars' => Money::fromUsd($validated['pack']),
            'description' => CreditTransactionType::Purchase->label(),
        ]);

        return back()->with('success', 'Added $'.$validated['pack'].' of credit.');
    }
}
