<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientFcmToken extends Model
{
    protected $fillable = ['client_id', 'fcm_token', 'device_name', 'last_used_at'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
