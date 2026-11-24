@php
$user = filament()->auth()->user();
@endphp

<x-filament-widgets::widget>
    <div class="grid gap-4">
        <div class="fi-account-widget">
            <x-filament::section>
        <x-filament-panels::avatar.user
            size="lg"
            :user="$user"
            loading="lazy" />

        <div class="fi-account-widget-main">
            <h2 class="fi-account-widget-heading">
                {{ __('filament-panels::widgets/account-widget.welcome', ['app' => config('app.name')]) }}
            </h2>

            <p class="fi-account-widget-user-name">
                {{ filament()->getUserName($user) }}
            </p>
        </div>

        <form
            action="{{ filament()->getLogoutUrl() }}"
            method="post"
            class="fi-account-widget-logout-form">
            @csrf

            <x-filament::button
                color="gray"
                :icon="\Filament\Support\Icons\Heroicon::ArrowLeftEndOnRectangle"
                :icon-alias="\Filament\View\PanelsIconAlias::WIDGETS_ACCOUNT_LOGOUT_BUTTON"
                labeled-from="sm"
                tag="button"
                type="submit">
                {{ __('filament-panels::widgets/account-widget.actions.logout.label') }}
            </x-filament::button>
        </form>
            </x-filament::section>
        </div>
        @if(in_array($user->role ?? '', ['admin', 'meter_reader', 'collector']))
            <x-filament::section>
                <x-slot name="heading">العمليات السريعة</x-slot>
                <div class="flex flex-wrap items-center gap-3">
            @if(in_array($user->role ?? '', ['admin', 'meter_reader']))
                {{ $this->createReadingAction }}
                {{ $this->createSubscriberAction }}
            @endif
            @if(in_array($user->role ?? '', ['admin', 'collector']))
                {{ $this->createPaymentAction }}
            @endif
        </div>

            </x-filament::section>
            <x-filament-actions::modals />
        @endif
    </div>
</x-filament-widgets::widget>