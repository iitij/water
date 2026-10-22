@php
$user = filament()->auth()->user();
@endphp

<x-filament-widgets::widget class="fi-account-widget">
    <style>
        .fi-account-widget .fi-section-content {
            flex-wrap: wrap !important;
        }

        @media (max-width: 640px) {
            .custom-actions-container {
                width: 100% !important;
                justify-content: flex-end !important;
                order: 10 !important;
                margin-top: 20px;
            }
        }
    </style>
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

        @if(in_array($user->role ?? '', ['admin', 'meter_reader', 'collector']))
        <div class="custom-actions-container flex flex-wrap items-center gap-3 my-auto">
            @if(in_array($user->role ?? '', ['admin', 'meter_reader']))
                {{ $this->createReadingAction }}
                {{ $this->createSubscriberAction }}
            @endif
            @if(in_array($user->role ?? '', ['admin', 'collector']))
                {{ $this->createPaymentAction }}
            @endif
        </div>
        <x-filament-actions::modals />
        @endif

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
</x-filament-widgets::widget>