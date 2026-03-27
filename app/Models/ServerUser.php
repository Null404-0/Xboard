<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerUser extends Model
{
    protected $table = 'v2_server_user';
    public $timestamps = false;

    protected $fillable = ['server_id', 'user_id'];
}
