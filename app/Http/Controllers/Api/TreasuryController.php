<?php

namespace App\Http\Controllers\Api;

use App\Services\TreasuryRegisterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TreasuryController extends ApiController
{
    public function index(Request $request, TreasuryRegisterService $service)
    {
        $service->authorize($request->user());
        return response()->json(['accounts' => DB::table('treasury_accounts')
            ->orderBy('code')->get(['id', 'code', 'name', 'channel', 'opening_on']),
            'certified_balance' => false]);
    }

    public function store(Request $request, TreasuryRegisterService $service)
    {
        $service->authorize($request->user());
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'channel' => ['required', 'in:cash,bank'],
            'opening_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'opening_amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
            'opening_reference' => ['required', 'string', 'max:150'],
            'opening_evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'operation_uuid' => ['nullable', 'uuid'],
        ]);
        return $this->openingFundingEntry($request, $data, fn ($uuid, $input) =>
            ['account' => app(\App\Services\GatedTreasuryOpeningFundingWriter::class)->open(
                $request->user(), $input, $uuid, now()->toDateTimeImmutable())['account'], 'certified_balance' => false],
            fn ($input) => ['account' => $service->open($request->user(), $input), 'certified_balance' => false]);
    }

    public function show(Request $request, int $id, TreasuryRegisterService $service)
    {
        return response()->json($service->inspect($request->user(), $id));
    }

    public function fundCollector(Request $request, int $id, TreasuryRegisterService $service, \App\Services\CashSessionService $sessions)
    {
        $service->authorize($request->user());
        $data = $request->validate([
            'collector_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
            'reference' => ['required', 'string', 'max:150'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'square_id' => ['nullable', 'integer', 'exists:squares,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'operation_uuid' => ['nullable', 'uuid'],
            'issued_on' => ['prohibited'],
        ]);
        $collector = \App\Models\User::findOrFail($data['collector_id']);
        return $this->openingFundingEntry($request, $data, function ($uuid, $input) use ($collector, $request, $id, $sessions) {
            $result = app(\App\Services\GatedTreasuryOpeningFundingWriter::class)->fund(
                $collector, $request->user(), $id, (string) $input['amount'], $input['reference'], $input['evidence'],
                $uuid, now()->toDateTimeImmutable(), $input['square_id'] ?? null, $input['notes'] ?? null);
            return ['cash_session' => $sessions->summary($result['session']), 'certified_balance' => false];
        }, function ($input) use ($collector, $request, $id, $sessions) {
            $session = $sessions->openFunded($collector, $request->user(), $id, (string) $input['amount'],
                $input['reference'], $input['evidence'], $input['square_id'] ?? null, $input['notes'] ?? null);
            return ['cash_session' => $sessions->summary($session), 'certified_balance' => false];
        });
    }

    private function openingFundingEntry(Request $request, array $data, callable $candidate, callable $legacy)
    {
        try {
            $uuid = $data['operation_uuid'] ?? null;
            unset($data['operation_uuid']);
            if (config('financial_candidate.treasury_opening_funding') === true
                || DB::table('financial_scopes')->where('scope_key', 'water')->exists()) {
                abort_unless(app()->environment(['testing', 'staging']), 503);
                $request->validate(['operation_uuid' => ['required', 'uuid']]);
                return response()->json($candidate(strtolower($uuid), $data));
            }
            if ($uuid !== null) throw new \DomainException('gated_treasury_opening_funding_entry_disabled');
            return response()->json($legacy($data));
        } catch (\DomainException $error) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function dispatchTransfer(Request $request, \App\Services\TreasuryTransferService $service)
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'integer', 'min:1'],
            'to_account_id' => ['required', 'integer', 'min:1', 'different:from_account_id'],
            'amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
            'reference' => ['required', 'string', 'max:150'],
            'dispatched_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'operation_uuid' => ['nullable', 'uuid'],
        ]);
        return $this->transferEntry($request, $data, fn ($uuid, $input) =>
            app(\App\Services\GatedTreasuryTransferWriter::class)->dispatch($request->user(),
                $input, $uuid, now()->toDateTimeImmutable())['transfer'],
            fn ($input) => $service->dispatch($request->user(), $input));
    }

    public function receiveTransfer(Request $request, int $id, \App\Services\TreasuryTransferService $service)
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
            'reference' => ['required', 'string', 'max:150'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'operation_uuid' => ['nullable', 'uuid'],
        ]);
        return $this->transferEntry($request, $data, fn ($uuid, $input) =>
            app(\App\Services\GatedTreasuryTransferWriter::class)->receive($request->user(), $id,
                $input, $uuid, now()->toDateTimeImmutable())['transfer'],
            fn ($input) => $service->receive($request->user(), $id, $input));
    }

    private function transferEntry(Request $request, array $data, callable $candidate, callable $legacy)
    {
        app(TreasuryRegisterService::class)->authorize($request->user());
        try {
            $uuid = $data['operation_uuid'] ?? null;
            unset($data['operation_uuid']);
            if (config('financial_candidate.treasury_transfers') === true
                || DB::table('financial_scopes')->where('scope_key', 'water')->exists()) {
                abort_unless(app()->environment(['testing', 'staging']), 503);
                $request->validate(['operation_uuid' => ['required', 'uuid']]);
                return response()->json(['transfer' => $candidate(strtolower($uuid), $data)]);
            }
            if ($uuid !== null) throw new \DomainException('gated_treasury_transfer_entry_disabled');
            return response()->json(['transfer' => $legacy($data)]);
        } catch (\DomainException $error) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function transfers(Request $request, \App\Services\TreasuryTransferService $service)
    {
        return response()->json($service->overview($request->user()));
    }

    public function post(Request $request, int $id, TreasuryRegisterService $service)
    {
        $service->authorize($request->user());
        $data = $request->validate([
            'kind' => ['required', 'in:cash_handover,payment_refund'],
            'source_id' => ['required', 'integer', 'min:1'],
            'amount' => ['prohibited'], 'effective_on' => ['prohibited'],
        ]);
        try {
            return response()->json(['posting' => $service->post($request->user(), $id, $data['kind'], (int) $data['source_id']),
                'certified_balance' => false]);
        } catch (\DomainException $error) {
            throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException($error->getMessage(), $error);
        }
    }
}
