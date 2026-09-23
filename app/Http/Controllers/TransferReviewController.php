<?php

namespace App\Http\Controllers;

use App\Actions\Transfers\ReviewTransfer;
use App\Enums\TransferStatus;
use App\Models\Transfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TransferReviewController extends Controller
{
    public function approve(Request $request, Transfer $transfer, ReviewTransfer $action): RedirectResponse
    {
        Gate::authorize('approve', $transfer);
        $request->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => 'Confirmez la vérification du transfert.']);
        $action->handle($request->user(), $transfer, TransferStatus::Approved, ip: $request->ip());

        return redirect()->route('transfers.show', $transfer)->with('status', 'Transfert approuvé. Il est désormais visible par l’agence de retrait.');
    }

    public function reject(Request $request, Transfer $transfer, ReviewTransfer $action): RedirectResponse
    {
        Gate::authorize('reject', $transfer);
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
        $action->handle($request->user(), $transfer, TransferStatus::Rejected, $data['rejection_reason'], $request->ip());

        return redirect()->route('transfers.show', $transfer)->with('status', 'Transfert rejeté. Le dépôt en caisse est conservé jusqu’au remboursement effectif du client.');
    }
}
