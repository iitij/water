<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\BankReconciliationService;
use App\Services\GatedBankFeeWriter;
use App\Services\ProspectiveSettlementEntry;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use App\Support\SignedMinor;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Candidate administration transport; all evidence and posting logic lives in services. */
final class TreasuryBankReconciliation extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-library';
    protected static string | \UnitEnum | null $navigationGroup = 'الفوترة والتحصيل';
    protected static ?string $title = 'مطابقة كشوف البنك';
    protected string $view = 'filament.pages.treasury-bank-reconciliation';

    public ?int $statementId = null;

    public static function canAccess(): bool
    {
        $actor = Auth::guard('web')->user();
        return app()->environment(['testing', 'staging'])
            && self::enabled('bank_evidence')
            && $actor instanceof User && ! $actor->isCurrentlyDisabled() && $actor->hasRole('super_admin');
    }

    private static function enabled(string $flag): bool
    {
        return config('financial_candidate.'.$flag) === true
            || DB::table('financial_scopes')->where('scope_key', 'water')->exists();
    }

    private function actor(): User
    {
        abort_unless(self::canAccess(), 403);
        return Auth::guard('web')->user();
    }

    private function amount(string $field, string $label): TextInput
    {
        return TextInput::make($field)->label($label)->required()
            ->rules(['regex:/^-?\d{1,12}(?:\.\d{1,2})?$/D']);
    }

    private function evidence(): Textarea
    {
        return Textarea::make('evidence')->label('الدليل المستقل')->required()->minLength(10)->maxLength(2000);
    }

    private function bankAction(callable $action, string $message): void
    {
        $actor = $this->actor();
        try {
            $action($actor);
        } catch (DomainException | ConflictHttpException $error) {
            throw ValidationException::withMessages(['data' => $error->getMessage()]);
        }
        Notification::make()->success()->title($message)->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inspect_statement')->label('عرض نتيجة المطابقة')
                ->visible(fn (): bool => self::canAccess())
                ->form([
                    Select::make('statement_id')->label('الكشف البنكي')->options(fn (): array => $this->statementOptions())
                        ->searchable()->required()->rules(['integer', 'min:1']),
                ])
                ->action(function (array $data): void {
                    app(BankReconciliationService::class)->inspect($this->actor(), (int) $data['statement_id']);
                    $this->statementId = (int) $data['statement_id'];
                }),
            $this->collectionAction(false),
            $this->collectionAction(true),
            Action::make('import_statement')->label('تسجيل كشف بنكي')
                ->visible(fn (): bool => self::canAccess())
                ->form([
                    Select::make('account_id')->label('الحساب البنكي')->options(fn (): array => $this->accountOptions())
                        ->searchable()->required()->rules(['integer', 'min:1']),
                    TextInput::make('reference')->label('مرجع الكشف')->required()->minLength(3)->maxLength(150),
                    DatePicker::make('from_date')->label('من تاريخ')->required()->maxDate(today()),
                    DatePicker::make('to_date')->label('إلى تاريخ')->required()->maxDate(today())->afterOrEqual('from_date'),
                    $this->amount('opening_amount', 'رصيد بداية الكشف'),
                    $this->amount('closing_amount', 'رصيد نهاية الكشف'),
                    $this->evidence(),
                    Repeater::make('lines')->label('الحركات الواردة في الكشف')->defaultItems(0)->maxItems(1000)
                        ->schema([
                            TextInput::make('bank_reference')->label('مرجع الحركة')->required()->maxLength(150),
                            DatePicker::make('booked_on')->label('تاريخ الحركة')->required()->maxDate(today()),
                            $this->amount('amount', 'المبلغ الموقّع (السالب خصم)'),
                            TextInput::make('description')->label('البيان')->maxLength(500),
                        ])->columns(2),
                ])
                ->action(fn (array $data) => $this->bankAction(function (User $actor) use ($data): void {
                    app(BankReconciliationService::class)->import($actor, (int) $data['account_id'], [
                        'reference' => $data['reference'], 'from_date' => $data['from_date'], 'to_date' => $data['to_date'],
                        'opening_amount' => $data['opening_amount'], 'closing_amount' => $data['closing_amount'],
                        'evidence' => $data['evidence'], 'lines' => array_values($data['lines'] ?? []),
                    ]);
                }, 'تم تسجيل الكشف البنكي')),
            Action::make('match_line')->label('مطابقة حركة بقيد قائم')
                ->visible(fn (): bool => self::canAccess())
                ->form([
                    Select::make('line_id')->label('الحركة البنكية')->options(fn (): array => $this->lineOptions(false))
                        ->searchable()->required()->rules(['integer', 'min:1']),
                    Select::make('posting_id')->label('قيد الخزينة القائم')->options(fn (): array => $this->postingOptions())
                        ->searchable()->required()->rules(['integer', 'min:1']),
                    $this->evidence(),
                ])
                ->requiresConfirmation()
                ->action(fn (array $data) => $this->bankAction(function (User $actor) use ($data): void {
                    $line = $this->line((int) $data['line_id']);
                    app(BankReconciliationService::class)->matchLine($actor, (int) $line->bank_statement_id,
                        (int) $line->id, (int) $data['posting_id'], $data['evidence']);
                }, 'تمت مطابقة الحركة دون إنشاء قيد جديد')),
            Action::make('record_fee')->label('اعتماد رسوم بنكية')
                ->visible(fn (): bool => self::canAccess() && self::enabled('bank_fees'))
                ->form([
                    Hidden::make('operation_uuid')->default(fn () => (string) Str::uuid())->required()->rules(['uuid']),
                    Select::make('line_id')->label('حركة الخصم البنكي')->options(fn (): array => $this->lineOptions(true))
                        ->searchable()->required()->rules(['integer', 'min:1']),
                    TextInput::make('reference')->label('مرجع الرسوم')->required()->minLength(3)->maxLength(150),
                    Textarea::make('reason')->label('سبب الرسوم الفعلية')->required()->minLength(10)->maxLength(500),
                    $this->evidence(),
                ])
                ->requiresConfirmation()
                ->action(fn (array $data) => $this->bankAction(function (User $actor) use ($data): void {
                    abort_unless(self::enabled('bank_fees'), 403);
                    $line = $this->line((int) $data['line_id']);
                    app(GatedBankFeeWriter::class)->record($actor, (int) $line->bank_statement_id,
                        (int) $line->id, $line->booked_on, $data['reference'], $data['reason'], $data['evidence'],
                        strtolower($data['operation_uuid']), now()->toDateTimeImmutable());
                }, 'تم اعتماد الرسوم وترحيلها')),
        ];
    }

    private function collectionAction(bool $late): Action
    {
        $fields = [
            Select::make('claim_id')->label($late ? 'طلب التحصيل المتأخر' : 'طلب التحصيل غير النقدي')
                ->options(fn (): array => $this->claimOptions($late))->searchable()->required()->rules(['integer', 'min:1']),
            Select::make('line_id')->label('حركة الإيداع البنكي')->options(fn (): array => $this->lineOptions(false, true))
                ->searchable()->required()->rules(['integer', 'min:1']),
            TextInput::make('reference')->label('مرجع إثبات وصول المبلغ')->required()->minLength(3)->maxLength(150),
            $this->evidence(),
        ];
        if ($late) $fields[] = TextInput::make('review_reference')->label('مرجع مراجعة السند المتأخر')
            ->required()->minLength(3)->maxLength(150);
        return Action::make($late ? 'clear_late_bank' : 'clear_claim')
            ->label($late ? 'اعتماد التحصيل البنكي المتأخر' : 'اعتماد التحصيل غير النقدي')
            ->visible(fn (): bool => self::canAccess() && self::enabled('noncash_settlements'))
            ->form($fields)->requiresConfirmation()
            ->action(fn (array $data) => $this->bankAction(function (User $actor) use ($data, $late): void {
                abort_unless(self::enabled('noncash_settlements'), 403);
                $line = $this->line((int) $data['line_id']);
                $common = ['statement_id' => (int) $line->bank_statement_id, 'line_id' => (int) $line->id,
                    'evidence' => $data['evidence']];
                $entry = app(ProspectiveSettlementEntry::class);
                if ($late) {
                    $entry->clearBank($actor, (int) $data['claim_id'], $common + [
                        'evidence_reference' => $data['review_reference'], 'bank_clearance_reference' => $data['reference']]);
                } else {
                    $entry->clearClaim($actor, (int) $data['claim_id'], $common + ['reference' => $data['reference']]);
                }
                $this->statementId = (int) $line->bank_statement_id;
            }, 'تم اعتماد التحصيل وترحيله'));
    }

    private function claimOptions(bool $late): array
    {
        $actor = $this->actor();
        $table = $late ? 'late_receipt_reviews' : 'noncash_collection_claims';
        $query = DB::table($table)->whereIn('payment_method', ['bank_transfer', 'check'])
            ->where($late ? 'collector_id' : 'submitted_by', '!=', $actor->id)
            ->where(function ($q) use ($late, $actor) {
                $q->where('status', 'pending')->orWhere(function ($q) use ($late, $actor) {
                    $q->where('status', $late ? 'approved' : 'cleared')->where('decided_by', $actor->id);
                    if ($late) $q->where('settlement_method', 'verified_non_cash');
                });
            })->orderByDesc('id');
        return $query->get()->mapWithKeys(function ($row) use ($late) {
            $minor = $late ? \App\Support\CashCustodyAmounts::positiveStored($row->amount, DB::getDriverName(), \App\Support\DecimalDomain::D12)
                : SignedMinor::parse($row->amount_minor);
            return [$row->id => '#'.$row->id.' | مشترك #'.$row->subscriber_id.' | '.($late ? $row->reference_number : $row->instrument_reference)
                .' | '.SignedMinor::decimal($minor).' | '.$row->status];
        })->all();
    }

    private function statementOptions(): array
    {
        $this->actor();
        return DB::table('bank_statements as b')->join('treasury_accounts as a', 'a.id', '=', 'b.treasury_account_id')
            ->orderByDesc('b.id')->get(['b.id', 'b.reference', 'b.from_date', 'b.to_date', 'a.code'])
            ->mapWithKeys(fn ($row) => [$row->id => $row->code.' | '.$row->reference.' | '.$row->from_date.' — '.$row->to_date])->all();
    }

    public function selectedReport(): ?array
    {
        $actor = $this->actor();
        return $this->statementId === null ? null : app(BankReconciliationService::class)->inspect($actor, $this->statementId);
    }

    private function line(int $id): object
    {
        $this->actor();
        $line = DB::table('bank_statement_lines')->find($id);
        abort_unless($line, 404);
        return $line;
    }

    private function accountOptions(): array
    {
        $this->actor();
        return DB::table('treasury_accounts')->where('channel', 'bank')->orderBy('code')
            ->get(['id', 'code', 'name'])->mapWithKeys(fn ($row) => [$row->id => $row->code.' — '.$row->name])->all();
    }

    private function lineOptions(bool $debit, bool $credit = false): array
    {
        $actor = $this->actor();
        $query = DB::table('bank_statement_lines as l')->join('bank_statements as b', 'b.id', '=', 'l.bank_statement_id')
            ->join('treasury_accounts as a', 'a.id', '=', 'l.treasury_account_id')
            ->where('b.imported_by', '!=', $actor->id)->where('a.channel', 'bank');
        if ($debit) $query->where('l.amount_minor', '<', 0);
        if ($credit) $query->where('l.amount_minor', '>', 0);
        // Include already matched lines for exact replay; the service refuses conflicting re-use.
        return $query->orderByDesc('l.id')->get(['l.id', 'l.bank_reference', 'l.booked_on', 'l.amount_minor',
            'b.reference', 'b.from_date', 'b.to_date', 'a.code'])
            ->mapWithKeys(fn ($row) => [$row->id => $row->code.' | '.$row->reference.' | '.$row->bank_reference
                .' | '.$row->booked_on.' | '.SignedMinor::decimal(SignedMinor::parse($row->amount_minor))
                .' | '.$row->from_date.' — '.$row->to_date])->all();
    }

    private function postingOptions(): array
    {
        $this->actor();
        return DB::table('treasury_postings as p')->join('treasury_accounts as a', 'a.id', '=', 'p.treasury_account_id')
            ->where('a.channel', 'bank')->orderByDesc('p.id')
            ->get(['p.id', 'p.effective_on', 'p.amount_minor', 'p.evidence_reference', 'a.code'])
            ->mapWithKeys(fn ($row) => [$row->id => $row->code.' | '.$row->effective_on.' | '.$row->evidence_reference
                .' | '.SignedMinor::decimal(SignedMinor::parse($row->amount_minor))])->all();
    }
}
