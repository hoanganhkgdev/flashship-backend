<x-filament-panels::page>
    <div class="grid gap-3 md:grid-cols-3">
        @foreach ($this->versionSummaries as $app)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">App {{ $app['label'] }}</p>
                        <p class="mt-1 text-xl text-gray-950 dark:text-white">Android {{ $app['android_latest'] }}</p>
                    </div>
                    <span class="text-sm {{ $app['forced'] ? 'text-danger-600' : 'text-gray-500' }}">{{ $app['forced'] ? 'Bắt buộc' : 'Không bắt buộc' }}</span>
                </div>
                <p class="mt-2 text-sm text-gray-500">iOS {{ $app['ios_latest'] }} · Tối thiểu {{ $app['minimum'] }}</p>
                <p class="mt-1 text-sm text-gray-400">Cập nhật {{ $app['updated_at'] ?: '—' }}</p>
            </div>
        @endforeach
    </div>

    <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
        Khi bật bắt buộc cập nhật, người dùng có phiên bản thấp hơn mức tối thiểu có thể bị chặn sử dụng ứng dụng. Hãy kiểm tra liên kết tải trước khi lưu.
    </div>

    <x-filament-panels::form wire:submit="save" wire:confirm="Lưu chính sách phiên bản mới? Nếu bật bắt buộc cập nhật, người dùng app cũ có thể bị chặn ngay.">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="[
                \Filament\Actions\Action::make('save')
                    ->label('Lưu cài đặt')
                    ->icon('heroicon-o-check')
                    ->submit('save')
                    ->color('primary'),
            ]"
        />
    </x-filament-panels::form>
</x-filament-panels::page>
