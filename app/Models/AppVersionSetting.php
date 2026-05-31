<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppVersionSetting extends Model
{
    protected $fillable = [
        'min_version',
        'latest_version',
        'android_url',
        'ios_url',
        'force_update',
        'force_message',
    ];

    protected $casts = [
        'force_update' => 'boolean',
    ];

    public static function current(): self
    {
        return static::firstOrCreate([], [
            'min_version'    => '1.0.0',
            'latest_version' => '1.0.0',
            'force_update'   => false,
            'force_message'  => 'Vui lòng cập nhật ứng dụng để tiếp tục sử dụng.',
        ]);
    }
}
