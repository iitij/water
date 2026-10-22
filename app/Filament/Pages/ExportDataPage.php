<?php

namespace App\Filament\Pages;

use App\Models\Invoice;
use App\Models\Reading;
use App\Models\Square;
use App\Models\Subscriber;
use App\Services\DataExportService;
use Filament\Notifications\Notification;
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
