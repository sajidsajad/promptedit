<?php

namespace App\Http\Controllers;

use App\Services\Wallet\WalletCreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    /**
     * Credit packs a user can "buy". No real payment is taken.
     */
    public const PACKS = ['5', '10', '25', '50'];

    /**
     * Fake purchase: adds credits to the user's wallet.
     *
     * The client sends a one-time token per purchase attempt, so a double
     * click or a retry cannot credit the wallet twice.
     */
    public function purchase(
        Request $request,
        WalletCreditService $credits
    ): RedirectResponse {
        $input = $request->validate([
            'amount' => ['required', 'string', Rule::in(self::PACKS)],
            'token' => ['required', 'uuid'],
        ]);

        $credits->credit(
            $request->user()->wallet,
            $input['amount'],
            "purchase:{$request->user()->id}:{$input['token']}",
            'Credits purchased (demo, no payment taken).'
        );

        return back()->with('status', "\${$input['amount']} of credits added.");
    }
}
