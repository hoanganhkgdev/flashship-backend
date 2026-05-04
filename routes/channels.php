<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\Order\Models\Order;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Driver nhận offer đơn hàng
Broadcast::channel('driver.{driverId}', function ($user, $driverId) {
    return (int) $user->id === (int) $driverId && $user->user_type === 'driver';
});

// Customer theo dõi trạng thái đơn
Broadcast::channel('order.{orderCode}', function ($user, $orderCode) {
    $order = Order::where('code', $orderCode)->first();
    if (!$order) return false;
    return (int) $user->id === (int) $order->sender_platform_id
        || (int) $user->id === (int) $order->delivery_man_id;
});
