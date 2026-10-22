<?php

namespace App\Filament\Resources\Transactions\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('subscriber_id')
                    ->relationship('subscriber', 'name')
                    ->required(),
                TextInput::make('type')
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('description'),
                TextInput::make('reference_type'),
                TextInput::make('reference_id')
                    ->numeric(),
            ]);
    }
}
