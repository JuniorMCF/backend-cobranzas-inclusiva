<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CobranzaAlta extends Model
{
    use HasFactory;

    protected $connection = 'sqlsrv';
    protected $table = "cobranzas_alta";
    protected $primaryKey = 'id';


    protected $fillable = [
        'idsocio',
        'nom',
        'ap',
        'am',
        'dni',
        'fec_nac',
        'email',
        'pswd',
        'secret_question',
        'secret_answer',
        'status'
    ];

    protected $hidden = [
        'pswd',
    ];
    public $timestamps = false;
}
