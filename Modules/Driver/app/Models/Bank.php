<?php
namespace Modules\Driver\Models;
use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    protected $fillable = ['user_id', 'bank_code', 'bank_name', 'account_number', 'account_name'];
}
