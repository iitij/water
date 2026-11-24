<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::deactivateAction(),
        ];
    }

    // Filament saves role relationships before mutateFormDataBeforeSave.
    // Lock before any relationship is written and keep that lock until commit.
    protected ?bool $hasDatabaseTransactions = true;

    protected function beforeValidate(): void
    {
        $record = \App\Models\User::query()->whereKey($this->record->id)->lockForUpdate()->firstOrFail();
        abort_if($record->isDisabled(), 409, 'الحساب معطل ولا يمكن تعديله.');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_if($this->record->fresh()->isDisabled(), 409, 'الحساب معطل ولا يمكن تعديله.');
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        return $data;
    }
}
