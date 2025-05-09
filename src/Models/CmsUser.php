<?php

namespace twa\cmsv2\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CmsUser extends Model
{
    use HasFactory;

    protected $casts = [
        'attributes' => 'array',
        'roles'=>'array'
    ];
    
} 
