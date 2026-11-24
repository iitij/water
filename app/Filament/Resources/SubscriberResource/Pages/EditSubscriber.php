<?php

namespace App\Filament\Resources\SubscriberResource\Pages;

use App\Filament\Resources\SubscriberResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubscriber extends EditRecord
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('ledger_statement')
                ->label('كشف الحساب')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => SubscriberResource::canView($this->record))
                ->url(fn (): string => SubscriberResource::getUrl('ledger-statement', ['record' => $this->record])),
            Actions\DeleteAction::make(),
        ];
    }
}





