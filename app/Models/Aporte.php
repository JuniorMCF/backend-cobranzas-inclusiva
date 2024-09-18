<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Aporte extends Model
{
    use HasFactory;
    protected $connection = 'sqlsrv';
    protected $table = "aporte_saldo";
}
