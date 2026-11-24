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
