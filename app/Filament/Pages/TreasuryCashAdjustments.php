<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\GatedCashCountAdjustmentWriter;
use App\Services\TreasuryRegisterService;
use App\Services\TreasuryCashCountService;
use App\Support\SignedMinor;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Candidate transport only; no second settlement implementation. */
final class TreasuryCashAdjustments extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';
    protected static string | \UnitEnum | null $navigationGroup = 'الفوترة والتحصيل';
    protected static ?string $title = 'تسويات جرد الخزينة';
    protected string $view = 'filament.pages.treasury-cash-adjustments';

    public static function canAccess(): bool
    {
        $actor = Auth::guard('web')->user();
        return app()->environment(['testing', 'staging'])
            && (config('financial_candidate.cash_count_adjustments') === true
                || DB::table('financial_scopes')->where('scope_key', 'water')->exists())
            && $actor instanceof User && ! $actor->isCurrentlyDisabled() && $actor->hasRole('super_admin');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record_cash_count')
                ->label('تسجيل جرد نقدي')
                ->visible(fn (): bool => self::canAccess())
                ->form([
                    Select::make('account_id')->label('الخزينة النقدية')
                        ->options(fn (): array => $this->accountOptions())->searchable()->required(),
                    TextInput::make('counted_amount')->label('النقد المعدود فعلياً')->required()
                        ->rules(['regex:/^\\d{1,12}(?:\\.\\d{1,2})?$/D']),
                    TextInput::make('reference')->label('مرجع الجرد')->required()->minLength(3)->maxLength(150),
                    Textarea::make('evidence')->label('دليل الجرد')->required()->minLength(10)->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    abort_unless(self::canAccess(), 403);
                    try {
                        app(TreasuryCashCountService::class)->record(Auth::guard('web')->user(),
                            (int) $data['account_id'], (string) $data['counted_amount'], $data['reference'], $data['evidence']);
                    } catch (DomainException $error) {
                        throw ValidationException::withMessages(['data' => $error->getMessage()]);
                    }
                    Notification::make()->success()->title('تم تسجيل الجرد للمراجعة')->send();
                }),
            Action::make('review_cash_count')
                ->label('مراجعة الجرد')
                ->visible(fn (): bool => self::canAccess())
                ->form([
                    Select::make('count_id')->label('الجرد ونتيجة المقارنة والدليل')
                        ->options(fn (): array => $this->reviewOptions())->searchable()->required(),
                    Select::make('accept')->label('القرار')->options(['1' => 'قبول الجرد', '0' => 'رفض الجرد'])
                        ->required()->rules(['in:0,1']),
                    Textarea::make('evidence')->label('دليل المراجعة المستقلة')->required()->minLength(10)->maxLength(2000),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    abort_unless(self::canAccess(), 403);
                    try {
                        app(TreasuryCashCountService::class)->review(Auth::guard('web')->user(),
                            (int) $data['count_id'], (string) $data['accept'] === '1', $data['evidence']);
                    } catch (DomainException $error) {
                        throw ValidationException::withMessages(['data' => $error->getMessage()]);
                    }
                    Notification::make()->success()->title('تم تسجيل قرار المراجعة')->send();
                }),
            Action::make('adjust_cash_count')
                ->label('اعتماد تسوية الجرد')
                ->visible(fn (): bool => self::canAccess())
                ->form([
                    Hidden::make('operation_uuid')->default(fn () => (string) Str::uuid())->required()->rules(['uuid']),
                    Select::make('count_id')->label('الجرد المقبول — راجع الخزينة والفرق والدليل')
                        ->options(fn (): array => $this->countOptions())->searchable()->required()->rules(['integer', 'min:1']),
                    Select::make('classification')->label('تصنيف الفرق')->options([
                        'confirmed_cash_loss' => 'خسارة نقدية مثبتة',
                        'unattributed_cash_held' => 'فائض نقدي محتجز لغير معلوم المالك',
                    ])->required()->rules(['in:confirmed_cash_loss,unattributed_cash_held']),
                    TextInput::make('reference')->label('مرجع التسوية')->required()->minLength(3)->maxLength(150),
                    Textarea::make('reason')->label('سبب التسوية المثبت')->required()->minLength(20)->maxLength(500),
                    Textarea::make('evidence')->label('دليل التسوية')->required()->minLength(10)->maxLength(2000),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    abort_unless(self::canAccess(), 403);
                    $actor = Auth::guard('web')->user();
                    app(TreasuryRegisterService::class)->authorize($actor);
                    try {
                        app(GatedCashCountAdjustmentWriter::class)->adjust($actor, (int) $data['count_id'],
                            $data['reference'], $data['classification'], $data['reason'], $data['evidence'],
                            strtolower($data['operation_uuid']), now()->toDateTimeImmutable());
                    } catch (DomainException $error) {
                        throw ValidationException::withMessages(['data' => $error->getMessage()]);
                    }
                    Notification::make()->success()->title('تم اعتماد تسوية الجرد وترحيلها')->send();
                }),
        ];
    }

    private function accountOptions(): array
    {
        abort_unless(self::canAccess(), 403);
        return DB::table('treasury_accounts')->where('channel', 'cash')
            ->where('opening_on', '<=', now('Asia/Aden')->toDateString())->orderBy('code')
            ->get(['id', 'code', 'name'])->mapWithKeys(fn ($row) => [$row->id => $row->code.' — '.$row->name])->all();
    }

    private function reviewOptions(): array
    {
        abort_unless(self::canAccess(), 403);
        $actor = Auth::guard('web')->user();
        $rows = DB::table('treasury_cash_counts as c')
            ->join('treasury_accounts as a', 'a.id', '=', 'c.treasury_account_id')
            ->leftJoin('treasury_cash_count_reviews as r', 'r.treasury_cash_count_id', '=', 'c.id')
            ->where('a.channel', 'cash')->where('c.counted_by', '!=', $actor->id)
            ->whereNull('r.id')->orderByDesc('c.id')
            ->get(['c.id', 'c.reference', 'c.counted_minor', 'c.recorded_minor', 'c.variance_minor',
                'c.observed_at', 'c.evidence', 'a.code', 'a.name']);
        $options = [];
        foreach ($rows as $row) {
            $options[$row->id] = '#'.$row->id.' | '.$row->code.' — '.$row->name.' | '.$row->reference
                .' | المعدود: '.SignedMinor::decimal(SignedMinor::parse($row->counted_minor))
                .' | المسجل: '.SignedMinor::decimal(SignedMinor::parse($row->recorded_minor))
                .' | الفرق: '.SignedMinor::decimal(SignedMinor::parse($row->variance_minor))
                .' | '.$row->observed_at.' | '.$row->evidence;
        }
        return $options;
    }

    private function countOptions(): array
    {
        abort_unless(self::canAccess(), 403);
        $actor = Auth::guard('web')->user();
        $rows = DB::table('treasury_cash_counts as c')
            ->join('treasury_cash_count_reviews as r', 'r.treasury_cash_count_id', '=', 'c.id')
            ->join('treasury_accounts as a', 'a.id', '=', 'c.treasury_account_id')
            ->where('a.channel', 'cash')->where('r.accepted', true)
            ->where('c.counted_by', '!=', $actor->id)->whereColumn('c.counted_by', '!=', 'r.reviewed_by')
            ->where('c.variance_minor', '!=', 0)->orderByDesc('c.id')
            ->get(['c.id', 'c.reference', 'c.variance_minor', 'c.observed_at', 'c.evidence',
                'r.evidence as review_evidence', 'a.code', 'a.name']);
        $options = [];
        foreach ($rows as $row) {
            $options[$row->id] = '#'.$row->id.' | '.$row->code.' — '.$row->name.' | '.$row->reference
                .' | '.SignedMinor::decimal(SignedMinor::parse($row->variance_minor))
                .' | '.$row->observed_at.' | '.$row->evidence.' | '.$row->review_evidence;
        }
        return $options;
    }
}
