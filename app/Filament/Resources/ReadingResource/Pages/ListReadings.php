<?php
namespace App\Filament\Resources\ReadingResource\Pages;
use App\Filament\Resources\ReadingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
class ListReadings extends ListRecords
{
    protected static string $resource = ReadingResource::class;
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['created_by'] = \Illuminate\Support\Facades\Auth::id();
                    return $data;
                })
                ->after(function (\App\Models\Reading $record, array $data) {
                    if (\App\Models\Setting::get('auto_issue_invoice_on_reading', '0') == '1') {
                        try {
                            $invoiceService = new \App\Services\InvoiceService();
                            
                            $invoiceService->generateInvoice(
                                subscriber: $record->meter->subscriber,
                                meter: $record->meter,
                                notes: null,
                            );
                            \Filament\Notifications\Notification::make()
                                ->title('تم إصدار الفاتورة تلقائياً')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('حدث خطأ أثناء إصدار الفاتورة تلقائياً')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }
                })
        ];
    }
}





