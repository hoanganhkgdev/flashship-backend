<?php

namespace App\Filament\Resources\ScoreResetRequestResource\Pages;

use App\Filament\Resources\ScoreResetRequestResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;
use Modules\Driver\Models\DriverScoreResetRequest;

class ListScoreResetRequests extends ListRecords
{
    protected static string $resource = ScoreResetRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tất cả'),
            'pending' => Tab::make('Chờ duyệt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge(DriverScoreResetRequest::where('status', 'pending')->count())
                ->badgeColor('warning'),
            'approved' => Tab::make('Đã duyệt')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'approved')),
            'rejected' => Tab::make('Từ chối')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
        ];
    }
}
