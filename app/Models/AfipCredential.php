<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AfipCredential extends Model
{
    protected $table = 'afip_credential';
    protected $primaryKey = 'id';
    public $timestamps = true;
    
    use HasFactory;
}
