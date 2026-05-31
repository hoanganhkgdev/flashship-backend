<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="[
                \Filament\Actions\Action::make('save')
                    ->label('Lưu cài đặt')
                    ->submit('save')
                    ->color('primary'),
            ]"
        />
    </x-filament-panels::form>
</x-filament-panels::page>
