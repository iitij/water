<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\CompletedCashRefundRequest;
use App\Services\TreasuryRegisterService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Staging-only transport. The service owns all financial and evidence checks. */
final class SignedCashRefundController extends ApiController
{
    private function input(Request $request, array $rules): array
    {
        abort_unless(app()->environment(['testing', 'staging']), 503);
        CompletedCashRefundRequest::authenticated($request->user());
        app(TreasuryRegisterService::class)->authorize($request->user());
        // Do not accept query parameters, multipart content, actor overrides,
        // financial values or unknown fields. Evidence bytes are base64 only.
        abort_unless($request->isJson(), 415);
        if ($request->query->count() !== 0 || $request->files->count() !== 0) {
            throw ValidationException::withMessages(['request' => 'Only JSON body fields are accepted.']);
        }
        $payload = $request->json()->all();
        if (array_diff(array_keys($payload), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected fields.']);
        }
        return Validator::make($payload, $rules)->validate();
    }

    private function positive(mixed $value): int
    {
        if (! is_int($value) || $value <= 0) {
            throw ValidationException::withMessages(['id' => 'A positive JSON integer is required.']);
        }
        return $value;
    }

    private function bytes(string $encoded): string
    {
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || base64_encode($bytes) !== $encoded
            || strlen($bytes) < 20 || strlen($bytes) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['document_base64' => 'Invalid canonical document encoding or size.']);
        }
        return $bytes;
    }

    private function respond(callable $operation)
    {
        try {
            return response()->json($operation());
        } catch (DomainException $error) {
            throw new ConflictHttpException($error->getMessage(), $error);
        }
    }

    public function recordDocument(Request $request, string $uuid, CompletedCashRefundRequest $service)
    {
        $d = $this->input($request, [
            'proposal_id' => ['required', 'integer', 'min:1'],
            'document_reference' => ['required', 'string', 'min:3', 'max:150'],
            'document_base64' => ['required', 'string', 'max:6990508'],
        ]);
        return $this->respond(fn () => $service->recordResolutionDocument($request->user(), strtolower($uuid),
            $this->positive($d['proposal_id']), $d['document_reference'], $this->bytes($d['document_base64']),
            now()->toDateTimeImmutable()));
    }

    public function reviewDocument(Request $request, string $uuid, CompletedCashRefundRequest $service)
    {
        $d = $this->input($request, [
            'proposal_id' => ['required', 'integer', 'min:1'],
            'document_id' => ['required', 'integer', 'min:1'],
            'document_reference' => ['required', 'string', 'min:3', 'max:150'],
            'document_base64' => ['required', 'string', 'max:6990508'],
            'confirmation' => ['required', 'string', 'min:20', 'max:2000'],
        ]);
        return $this->respond(fn () => $service->reviewResolutionDocument($request->user(), strtolower($uuid),
            $this->positive($d['proposal_id']), $this->positive($d['document_id']), $d['document_reference'],
            $this->bytes($d['document_base64']), $d['confirmation'], now()->toDateTimeImmutable()));
    }

    public function attestSource(Request $request, string $uuid, CompletedCashRefundRequest $service)
    {
        $d = $this->input($request, [
            'proposal_id' => ['required', 'integer', 'min:1'],
            'document_id' => ['required', 'integer', 'min:1'],
            'issuer' => ['required', 'string', 'min:3', 'max:64'],
            'source_reference' => ['required', 'string', 'min:3', 'max:150'],
            'signature' => ['required', 'string', 'size:88'],
            'substantive_review' => ['required', 'string', 'min:20', 'max:2000'],
        ]);
        return $this->respond(fn () => $service->attestResolutionSource($request->user(), strtolower($uuid),
            $this->positive($d['proposal_id']), $this->positive($d['document_id']), $d['issuer'],
            $d['source_reference'], $d['signature'], $d['substantive_review'], now()->toDateTimeImmutable()));
    }

    private function selections(array $members, bool $signed): array
    {
        if (! array_is_list($members)) {
            throw ValidationException::withMessages(['members' => 'A JSON list is required.']);
        }
        $proposals = $attestations = [];
        foreach ($members as $member) {
            $uuid = $member['operation_uuid'];
            if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid)
                || isset($proposals[$uuid])) {
                throw ValidationException::withMessages(['members' => 'Invalid or duplicate claim identity.']);
            }
            $proposals[$uuid] = $this->positive($member['proposal_id']);
            if ($signed) $attestations[$uuid] = $this->positive($member['attestation_id']);
        }
        if (count(array_unique($proposals)) !== count($proposals)
            || count(array_unique($attestations)) !== count($attestations)) {
            throw ValidationException::withMessages(['members' => 'Duplicate evidence identity.']);
        }
        return [$proposals, $attestations];
    }

    private function groupInput(Request $request, bool $signed, string $action): array
    {
        abort_unless(app()->environment(['testing', 'staging']), 503);
        // Validate the top-level payload first, then validate nested fields
        // explicitly: Laravel's validated array must not retain unknown keys.
        $rules = ['account_id' => ['required', 'integer', 'min:1'],
            'members' => ['required', 'array', 'min:'.($signed ? 1 : 2), 'max:50']];
        if ($action === 'post') $rules['confirmation'] = ['required', 'string', 'min:20', 'max:2000'];
        if ($action === 'renew') {
            $rules['count_id'] = ['required', 'integer', 'min:1'];
            $rules['evidence'] = ['required', 'string', 'min:20', 'max:2000'];
        }
        $d = $this->input($request, $rules);
        $nested = ['members.*' => ['required', 'array:operation_uuid,proposal_id'.($signed ? ',attestation_id' : '')],
            'members.*.operation_uuid' => ['required', 'string', 'uuid'],
            'members.*.proposal_id' => ['required', 'integer', 'min:1']];
        if ($signed) $nested['members.*.attestation_id'] = ['required', 'integer', 'min:1'];
        Validator::make($d, $nested)->validate();
        [$proposals, $attestations] = $this->selections($d['members'], $signed);
        return [$d, $this->positive($d['account_id']), $proposals, $attestations];
    }

    public function readiness(Request $request, CompletedCashRefundRequest $service)
    {
        [, $account, $proposals, $attestations] = $this->groupInput($request, true, 'read');
        return $this->respond(fn () => $service->mixedSourceReadiness($request->user(), $account,
            $proposals, $attestations, now()->toDateTimeImmutable()));
    }

    public function settle(Request $request, CompletedCashRefundRequest $service)
    {
        [$d, $account, $proposals, $attestations] = $this->groupInput($request, true, 'post');
        return $this->respond(fn () => $service->postMixedResolution($request->user(), $account,
            $proposals, $attestations, $d['confirmation'], now()->toDateTimeImmutable()));
    }

    public function renew(Request $request, CompletedCashRefundRequest $service)
    {
        [$d, $account, $proposals] = $this->groupInput($request, false, 'renew');
        return $this->respond(fn () => $service->renewResolutionGroup($request->user(), $account,
            $proposals, $this->positive($d['count_id']), $d['evidence'], now()->toDateTimeImmutable()));
    }
}
