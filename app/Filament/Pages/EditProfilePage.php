<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Auth;
use BackedEnum;

class EditProfilePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-user-circle';

    protected string $view = 'filament.pages.edit-profile-page';

    protected static ?string $title = 'الملف الشخصي';

    protected static ?string $navigationLabel = 'تعديل الملف الشخصي';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    public ?array $data = [];

    public function mount(): void
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $this->form->fill([
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Section::make('المعلومات الشخصية')
                    ->schema([
                        FileUpload::make('avatar')
                            ->label('الصورة الشخصية')
                            ->image()
                            ->disk('public')
                            ->directory('avatars')
                            ->avatar()
                            ->columnSpanFull(),
                        TextInput::make('name')
                            ->label('الاسم')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('البريد الإلكتروني')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(table: 'users', ignorable: Auth::user()),
                    ])->columns(2),

                Section::make('تغيير كلمة المرور')
                    ->schema([
                        TextInput::make('password')
                            ->label('كلمة المرور الجديدة')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->rule(Password::default()),
                        TextInput::make('passwordConfirmation')
                            ->label('تأكيد كلمة المرور')
                            ->password()
                            ->revealable()
                            ->same('password')
                            ->dehydrated(false),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (filled($data['password'] ?? null)) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        Notification::make()
            ->success()
            ->title('تم التحديث')
            ->body('تم تحديث بيانات الملف الشخصي بنجاح.')
            ->send();
    }
}
