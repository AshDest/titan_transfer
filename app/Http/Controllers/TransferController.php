<?php

namespace App\Http\Controllers;

use App\Actions\Transfers\CreateTransfer;
use App\Enums\RecordStatus;
use App\Enums\TransferStatus;
use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Transfer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TransferController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Transfer::class);
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::enum(TransferStatus::class)]]);
        $records = Transfer::visibleTo($request->user())->with(['sourceBranch', 'destinationBranch'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('q'), fn ($query) => $query->where('reference', 'like', '%'.$request->string('q').'%'))
            ->latest('id')->paginate(15)->withQueryString();

        return view('transfers.index', compact('records'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('transfers.create');
        $destinations = Branch::where('status', RecordStatus::Active)->orderBy('name')->get();
        $sources = $destinations->filter(fn (Branch $branch) => $request->user()->canAccessBranch($branch));
        $selected = [];
        foreach (['sender' => Customer::class, 'beneficiary' => Beneficiary::class] as $party => $model) {
            $id = $request->old($party.'_id');
            $selected[$party] = is_scalar($id) ? $model::where('status', RecordStatus::Active)
                ->when(! $request->user()->can('branches.view-all'), fn ($q) => $q->where('branch_id', $request->user()->branch_id))->find($id) : null;
        }

        return view('transfers.create', compact('sources', 'destinations', 'selected'));
    }

    public function people(Request $request): JsonResponse
    {
        Gate::authorize('transfers.create');
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'], 'kind' => ['required', 'in:sender,beneficiary'],
            'q' => ['nullable', 'string', 'max:100'], 'sender_id' => ['nullable', 'integer'],
        ]);
        $branch = Branch::findOrFail($data['branch_id']);
        Gate::authorize('create', [Transfer::class, $branch]);
        $query = $data['kind'] === 'sender' ? Customer::query() : Beneficiary::query();
        $query->where('branch_id', $branch->id)->where('status', RecordStatus::Active);
        if ($data['kind'] === 'beneficiary') {
            $sender = Customer::where('branch_id', $branch->id)->where('status', RecordStatus::Active)->find($data['sender_id'] ?? null);
            $query->where(fn ($q) => $q->whereNull('customer_id')->when($sender, fn ($q) => $q->orWhere('customer_id', $sender->id)));
        }
        $search = trim($data['q'] ?? '');
        $records = $query->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', '%'.$search.'%')
            ->orWhere('last_name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')))
            ->orderBy('last_name')->limit(20)->get(['id', 'first_name', 'last_name', 'phone']);

        return response()->json($records->map(fn ($record) => ['id' => $record->id, 'label' => $record->first_name.' '.$record->last_name.' · '.$record->phone]));
    }

    public function preview(Request $request, CreateTransfer $action): View
    {
        $review = $action->review($request->user(), $request->all());
        $token = Crypt::encryptString(json_encode([
            'actor' => $request->user()->id, 'data' => $review['data'], 'fingerprint' => $review['fingerprint'],
            'submission_key' => (string) Str::uuid(), 'expires_at' => now()->addMinutes(10)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return view('transfers.review', [...$review, 'token' => $token]);
    }

    public function revise(Request $request): RedirectResponse
    {
        try {
            $payload = $this->payload($request);
        } catch (ValidationException $exception) {
            return redirect()->route('transfers.create')->withErrors($exception->errors());
        }

        return redirect()->route('transfers.create')->withInput($payload['data']);
    }

    public function store(Request $request, CreateTransfer $action): RedirectResponse
    {
        try {
            $payload = $this->payload($request);
        } catch (ValidationException $exception) {
            return redirect()->route('transfers.create')->withErrors($exception->errors());
        }
        try {
            if ($payload['expires_at'] < now()->timestamp) {
                throw ValidationException::withMessages(['review_token' => 'Ce récapitulatif a expiré. Vérifiez à nouveau les tarifs.']);
            }
            $request->validate(['cash_received' => ['accepted']]);
            $transfer = $action->handle($request->user(), $payload['data'], $payload['fingerprint'], $payload['submission_key'], $request->ip());
        } catch (ValidationException $exception) {
            return redirect()->route('transfers.create')->withInput($payload['data'])->withErrors($exception->errors());
        }

        return redirect()->route('transfers.show', $transfer)
            ->with('status', 'Opération enregistrée. Le transfert est en attente de validation.')
            ->with('print_transfer', $transfer->uuid);
    }

    public function show(Request $request, Transfer $transfer): View
    {
        Gate::authorize('view', $transfer);
        $transfer->load(['sourceBranch', 'destinationBranch', 'events.actor', 'movements', 'approvedBy', 'rejectedBy']);
        $printReceipt = $request->session()->get('print_transfer') === $transfer->uuid;
        if ($printReceipt) {
            $request->session()->forget('print_transfer');
        }

        return view('transfers.show', compact('transfer', 'printReceipt'));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        Gate::authorize('transfers.create');
        $data = $request->validate(['review_token' => ['required', 'string', 'max:30000']]);
        try {
            $payload = json_decode(Crypt::decryptString($data['review_token']), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['review_token' => 'Ce récapitulatif est invalide. Recommencez l’opération.']);
        }
        abort_unless($payload['actor'] === $request->user()->id, 403);

        return $payload;
    }
}
