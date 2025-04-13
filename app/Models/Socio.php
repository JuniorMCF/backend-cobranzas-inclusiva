<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Socio extends Model
{
    use HasFactory;

    protected $connection = 'sqlsrv';
    protected $table = "socio";

    protected $primaryKey = 'dni';

    public $timestamps = false;
}
