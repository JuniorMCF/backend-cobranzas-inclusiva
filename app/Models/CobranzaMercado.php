<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CobranzaMercado extends Model
{
    use HasFactory;
    //cobranza_mercado

    protected $connection = 'sqlsrv';
    protected $table = "cobranza_mercado";

    protected $primaryKey = null;
    public $incrementing = false; // No se usa auto-incremento

    protected $fillable = [
        'fecha',
        'item',
        'idsocio',
        'idoficina',
        'idcredito',
        'idahorro',
        'turno',
        'montocredito',
        'montoaporte',
        'montoahorro',
        'total',
        'idusuarioregistro',
        'idusuariomodifica',
        'fecharegistro',
        'fechamodifica',
        'idoficinaregistro',
        'idoficinamodifica',
        'esliquidado',
        'eseliminado',
        'fechaliquidacion',
        'itemliquidacion',
        'ipregistro',
        'ipmodifica',
        'idasientoliquida',
        'anoliquida',
        'idtipoahorro',
        'idtipocredito',
        'entregacaja',
        'itemcontrol_entregacaja',
        'fecha_entregacaja',
        'recibo',
        'idusuario_regentregacaja',
        'idusuario_regliquida',
        'cajatemporal',
        'origenaplic'
    ];

    public $timestamps = false;
}
