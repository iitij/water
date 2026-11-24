<?php

namespace App\Filament\Pages;

use App\Models\Invoice;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use App\Services\DataExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportDataPage extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static ?string $title = 'تصدير البيانات';

    protected static ?string $navigationLabel = 'تصدير البيانات';

    protected static ?string $slug = 'export-data';

    protected static ?int $navigationSort = 14;

    protected string $view = 'filament.pages.export-data';

    // فلتر المشتركين
    public ?string $subscribers_square_id = '';
    public ?string $subscribers_status = '';

    // فلتر الفواتير
    public ?string $invoices_square_id = '';
    public ?string $invoices_from_date = '';
    public ?string $invoices_to_date = '';
    public ?string $invoices_status = '';

    // فلتر القراءات
    public ?string $readings_square_id = '';
    public ?string $readings_from_date = '';
    public ?string $readings_to_date = '';
    public ?string $readings_is_invoiced = '';

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 3])->schema([
                Section::make('قائمة المشتركين')
                    ->icon('heroicon-o-users')
                    ->description('بيانات المشتركين، العدادات والأرصدة.')
                    ->schema([
                        $this->squareFilter('subscribers_square_id'),
                        Select::make('subscribers_status')
                            ->label('حالة المشترك')
                            ->options(['active' => 'نشط فقط', 'inactive' => 'غير نشط', 'suspended' => 'موقوف'])
                            ->placeholder('جميع الحالات')
                            ->live(),
                        Actions::make([
                            Action::make('downloadSubscribers')
                                ->label('تنزيل ملف المشتركين (Excel)')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->action(fn () => $this->exportSubscribers()),
                            Action::make('directSubscribers')
                                ->label('تحميل مباشر')
                                ->color('gray')
                                ->url(fn () => route('admin.export.subscribers', ['square_id' => $this->subscribers_square_id, 'status' => $this->subscribers_status]))
                                ->openUrlInNewTab(),
                        ]),
                    ]),
                Section::make('فواتير المشتركين')
                    ->icon('heroicon-o-document-currency-dollar')
                    ->description('الاستهلاك والرسوم والمبالغ المسددة.')
                    ->schema([
                        $this->squareFilter('invoices_square_id'),
                        $this->dateFilters('invoices'),
                        Select::make('invoices_status')
                            ->label('حالة الفاتورة')
                            ->options(['paid' => 'مسددة بالكامل', 'partially_paid' => 'مسددة جزئياً', 'issued' => 'صادرة (مستحقة)', 'overdue' => 'متأخرة'])
                            ->placeholder('جميع الحالات')
                            ->live(),
                        Actions::make([
                            Action::make('downloadInvoices')
                                ->label('تنزيل الفواتير (Excel)')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->action(fn () => $this->exportInvoices()),
                            Action::make('directInvoices')
                                ->label('تحميل مباشر')
                                ->color('gray')
                                ->url(fn () => route('admin.export.invoices', ['square_id' => $this->invoices_square_id, 'from_date' => $this->invoices_from_date, 'to_date' => $this->invoices_to_date, 'status' => $this->invoices_status]))
                                ->openUrlInNewTab(),
                        ]),
                    ]),
                Section::make('قراءات العدادات')
                    ->icon('heroicon-o-chart-bar')
                    ->description('سجل القراءات والاستهلاك المعتمد.')
                    ->schema([
                        $this->squareFilter('readings_square_id'),
                        $this->dateFilters('readings'),
                        Select::make('readings_is_invoiced')
                            ->label('حالة الفوترة')
                            ->options(['1' => 'مفوترة فقط', '0' => 'غير مفوترة (جديدة)'])
                            ->placeholder('جميع القراءات')
                            ->live(),
                        Actions::make([
                            Action::make('downloadReadings')
                                ->label('تنزيل القراءات (Excel)')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->action(fn () => $this->exportReadings()),
                            Action::make('directReadings')
                                ->label('تحميل مباشر')
                                ->color('gray')
                                ->url(fn () => route('admin.export.readings', ['square_id' => $this->readings_square_id, 'from_date' => $this->readings_from_date, 'to_date' => $this->readings_to_date, 'is_invoiced' => $this->readings_is_invoiced]))
                                ->openUrlInNewTab(),
                        ]),
                    ]),
            ]),
        ]);
    }

    private function squareFilter(string $name): Select
    {
        return Select::make($name)
            ->label('تصفية بحسب المربع السكني')
            ->options(fn () => Square::orderBy('name')->pluck('name', 'id')->all())
            ->placeholder('جميع المربعات')
            ->searchable()
            ->live();
    }

    private function dateFilters(string $prefix): Grid
    {
        return Grid::make(2)->schema([
            DatePicker::make($prefix.'_from_date')->label('من تاريخ')->native(false)->live(),
            DatePicker::make($prefix.'_to_date')->label('إلى تاريخ')->native(false)->live(),
        ]);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        if ($user->hasRole('super_admin') || $user->hasRole('admin')) {
            return true;
        }

        return $user->can('View:ExportDataPage');
    }

    public function getViewData(): array
    {
        $squares = Square::orderBy('name')->get();

        return [
            'squares' => $squares,
            'totalSubscribers' => Subscriber::count(),
            'totalInvoices' => Invoice::count(),
            'totalReadings' => Reading::count(),
            'totalSquares' => $squares->count(),
        ];
    }

    /**
     * تصدير بيانات المشتركين عبر Livewire
     */
    public function exportSubscribers(): BinaryFileResponse
    {
        try {
            $service = new DataExportService();
            $squareId = !empty($this->subscribers_square_id) ? (int)$this->subscribers_square_id : null;
            $status = !empty($this->subscribers_status) ? $this->subscribers_status : null;

            $filePath = $service->exportSubscribers($squareId, $status);
            $fileName = 'المشتركون_' . now()->format('Y-m-d') . '.xlsx';

            Notification::make()
                ->title('تم تصدير ملف المشتركين بنجاح')
                ->success()
                ->send();

            return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('خطأ في تصدير المشتركين')
                ->body($e->getMessage())
                ->danger()
                ->send();
            throw $e;
        }
    }

    /**
     * تصدير فواتير المشتركين عبر Livewire
     */
    public function exportInvoices(): BinaryFileResponse
    {
        try {
            $service = new DataExportService();
            $squareId = !empty($this->invoices_square_id) ? (int)$this->invoices_square_id : null;
            $fromDate = !empty($this->invoices_from_date) ? $this->invoices_from_date : null;
            $toDate = !empty($this->invoices_to_date) ? $this->invoices_to_date : null;
            $status = !empty($this->invoices_status) ? $this->invoices_status : null;

            $filePath = $service->exportInvoices($squareId, $fromDate, $toDate, $status);
            $fileName = 'فواتير_المشتركين_' . now()->format('Y-m-d') . '.xlsx';

            Notification::make()
                ->title('تم تصدير ملف الفواتير بنجاح')
                ->success()
                ->send();

            return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('خطأ في تصدير الفواتير')
                ->body($e->getMessage())
                ->danger()
                ->send();
            throw $e;
        }
    }

    /**
     * تصدير قراءات العدادات عبر Livewire
     */
    public function exportReadings(): BinaryFileResponse
    {
        try {
            $service = new DataExportService();
            $squareId = !empty($this->readings_square_id) ? (int)$this->readings_square_id : null;
            $fromDate = !empty($this->readings_from_date) ? $this->readings_from_date : null;
            $toDate = !empty($this->readings_to_date) ? $this->readings_to_date : null;

            $isInvoiced = null;
            if ($this->readings_is_invoiced !== '' && $this->readings_is_invoiced !== null) {
                $isInvoiced = (bool)(int)$this->readings_is_invoiced;
            }

            $filePath = $service->exportReadings($squareId, $fromDate, $toDate, $isInvoiced);
            $fileName = 'قراءات_العدادات_' . now()->format('Y-m-d') . '.xlsx';

            Notification::make()
                ->title('تم تصدير ملف قراءات العدادات بنجاح')
                ->success()
                ->send();

            return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('خطأ في تصدير قراءات العدادات')
                ->body($e->getMessage())
                ->danger()
                ->send();
            throw $e;
        }
    }
}
