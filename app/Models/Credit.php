<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Credit extends Model
{
    use HasFactory;
    protected $connection = 'sqlsrv';
    protected $table = "credito";


    // public function cronogramas()
    // {
    //     return $this->hasMany(CreditSchedule::class, 'idcredito');
    // }
}
