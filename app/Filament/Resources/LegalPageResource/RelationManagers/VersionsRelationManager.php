<?php

namespace App\Filament\Resources\LegalPageResource\RelationManagers;

use App\Services\LegalPageService;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Modules\Core\Models\User;
use Modules\Admin\Models\PageVersion;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Lịch sử phiên bản';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Lưu lúc')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('title')->label('Tiêu đề bản cũ'),
                Tables\Columns\TextColumn::make('size')->label('Độ dài')->state(fn (PageVersion $v) => number_format(mb_strlen(strip_tags($v->content))).' ký tự'),
                Tables\Columns\TextColumn::make('who')->label('Bản này được thay bởi')
                    ->state(fn (PageVersion $v) => $v->performed_by ? (User::find($v->performed_by)?->name ?? 'Quản trị viên') : 'Không rõ'),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\Action::make('view')->label('Xem')->icon('heroicon-o-eye')
                    ->modalHeading(fn (PageVersion $v) => 'Bản lưu ngày '.$v->created_at->format('d/m/Y H:i'))
                    ->modalContent(fn (PageVersion $v) => new HtmlString('<div style="padding:1rem;max-height:60vh;overflow:auto">'.$v->content.'</div>'))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Đóng'),
                Tables\Actions\Action::make('restore')->label('Khôi phục')->icon('heroicon-o-arrow-uturn-left')->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Nội dung hiện tại sẽ được lưu vào lịch sử, rồi thay bằng bản này.')
                    ->action(function (PageVersion $record): void {
                        LegalPageService::restore($record, auth()->id());
                        Notification::make()->success()->title('Đã khôi phục phiên bản')->send();
                        $this->redirect(request()->header('Referer') ?: url()->current());
                    }),
            ])
            ->paginated([10, 25]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
