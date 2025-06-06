<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Saving extends Model
{
    use HasFactory;

    protected $connection = 'sqlsrv';
    protected $table = "ahorro";
    protected $primaryKey = 'idsocio';

    public $timestamps = false;
}
