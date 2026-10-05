<?php

namespace App\Services;

use App\Jobs\SendNotificationCampaignJob;
use App\Models\NotificationCampaign;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\FCMService;
use Modules\Core\Services\RTDBService;

/**
 * Gửi thông báo chiến dịch cho khách hàng.
 *  - Hộp thư trong app: ghi cho MỌI khách đang hoạt động trong phạm vi (không phụ thuộc có token hay không).
 *  - Push: chỉ gửi cho thiết bị có token.
 * Chạy nền qua hàng đợi nên không bị giới hạn thời gian của một yêu cầu web.
 */
class NotificationCampaignService
{
    private const CHUNK = 500;

    /** Khách đang hoạt động trong phạm vi. $cityId = null khi gửi toàn hệ thống. */
    public function customers(string $scope, ?int $cityId): Builder
    {
        return DB::table('users')
            ->where('user_type', 'customer')
            ->where('status', 1)
            ->when($scope !== 'all' && $cityId, fn ($q) => $q->where('city_id', $cityId))
            ->when($scope !== 'all' && ! $cityId, fn ($q) => $q->whereRaw('1 = 0'));
    }

    /** @return array{inbox: int, push: int} */
    public function counts(string $scope, ?int $cityId): array
    {
        $base = $this->customers($scope, $cityId);

        return [
            'inbox' => (clone $base)->count(),
            'push' => (clone $base)->whereNotNull('fcm_token')->where('fcm_token', '!=', '')->distinct()->count('fcm_token'),
        ];
    }

    /** Chuyển các chiến dịch hẹn giờ đã tới hạn sang hàng đợi. Gọi mỗi phút từ bộ lập lịch. */
    public function dispatchDue(): int
    {
        $due = NotificationCampaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->pluck('id');
        $dispatched = 0;
        foreach ($due as $id) {
            // Đổi trạng thái bằng một câu lệnh có điều kiện để hai tiến trình không cùng gửi một chiến dịch.
            if (NotificationCampaign::where('id', $id)->where('status', 'scheduled')->update(['status' => 'queued'])) {
                SendNotificationCampaignJob::dispatch($id);
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /** Gửi chiến dịch. Chỉ chạy khi đang chờ gửi, nên chạy lại cùng một chiến dịch sẽ không gửi trùng. */
    public function deliver(NotificationCampaign $campaign): void
    {
        $campaign->refresh();
        if ($campaign->status !== 'queued') {
            return;
        }
        $campaign->update(['status' => 'sending', 'started_at' => now()]);

        try {
            $inbox = 0;
            $tokens = [];

            $this->customers($campaign->scope, $campaign->city_id)->select('id', 'fcm_token')->orderBy('id')
                ->chunkById(self::CHUNK, function ($users) use ($campaign, &$inbox, &$tokens): void {
                    DB::table('customer_notifications')->insert($users->map(fn ($u) => [
                        'user_id' => $u->id, 'title' => $campaign->title, 'body' => $campaign->body,
                        'type' => 'general', 'order_code' => null, 'campaign_id' => $campaign->id, 'created_at' => now(),
                    ])->all());
                    $inbox += $users->count();

                    foreach ($users as $u) {
                        if (filled($u->fcm_token)) {
                            $tokens[] = $u->fcm_token;
                        }
                    }
                    $this->ping($users->pluck('id')->all());
                }, 'id');

            $tokens = array_values(array_unique($tokens));
            $campaign->update(['inbox_count' => $inbox, 'push_target' => count($tokens)]);

            $result = $tokens ? $this->push($tokens, $campaign->title, $campaign->body) : ['sent' => 0, 'failed' => 0];
            $campaign->update([
                'push_sent' => (int) $result['sent'], 'push_failed' => (int) $result['failed'],
                'status' => 'sent', 'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $campaign->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()]);
            report($e);
        }
    }

    /** @return array{sent: int, failed: int} */
    protected function push(array $tokens, string $title, string $body): array
    {
        return FCMService::getInstance()->broadcast($tokens, $title, $body);
    }

    /** Báo cho app biết có thông báo mới (ghi lên Firebase từng khách). Lỗi từng khách không làm hỏng cả chiến dịch. */
    protected function ping(array $userIds): void
    {
        foreach ($userIds as $id) {
            RTDBService::pingCustomerNotification((int) $id);
        }
    }
}
