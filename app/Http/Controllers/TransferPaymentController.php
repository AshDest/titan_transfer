<?php

namespace App\Http\Controllers;

use App\Actions\Transfers\PayTransfer;
use App\Models\Transfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransferPaymentController extends Controller
{
    public function store(Request $request, Transfer $transfer, PayTransfer $action): RedirectResponse
    {
        $action->handle($request->user(), $transfer, $request->all(), $request->ip());

        return redirect()->route('transfers.show', $transfer)->with('status', 'Paiement enregistré. La caisse de retrait a été débitée.')
            ->with('print_transfer', $transfer->uuid);
    }
}
