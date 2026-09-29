<x-filament-panels::page>
    {{-- Enter trong ô nhập cũng đi qua hộp xác nhận, không lưu thẳng. --}}
    <x-filament-panels::form wire:submit="mountAction('save')">
        {{ $this->form }}

        <x-filament-panels::form.actions :actions="[$this->getAction('save')]" />
    </x-filament-panels::form>
</x-filament-panels::page>
