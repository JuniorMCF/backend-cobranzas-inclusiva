<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\ApiController;
use App\Http\Controllers\Controller;
use App\Models\Aporte;
use App\Models\Bancocuenta;
use App\Models\Credit;
use App\Models\Pagoahorro;
use App\Models\Saving;
use App\Models\Socio;
use App\Models\CobranzaAlta;
use App\Models\CobranzaMercado;
use App\Models\SocioBancocuenta;
use App\Models\TransferenciaAplic;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AppController extends ApiController
{

    public function searchSocio(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }
        $searchTerm = $request->search;
        /**buscamos al socio por dni o idsocio */
        $socioQuery = Socio::query()->where('idtipopersona', 1); // socios activos

        if (is_numeric($searchTerm)) {
            $socioQuery->where(function ($query) use ($searchTerm) {
                $query->where('dni', '=', $searchTerm)
                    ->orWhere('idsocio', '=', $searchTerm);
            });
        } else {
            $keywords = preg_split('/\s+/', trim($searchTerm)); // separar por espacio
            foreach ($keywords as $word) {
                $socioQuery->where(function ($query) use ($word) {
                    $query->where('nom', 'like', "%$word%")
                        ->orWhere('ap', 'like', "%$word%")
                        ->orWhere('am', 'like', "%$word%");
                });
            }
        }

        $socio = $socioQuery->first();

        $credits = [];

        if ($socio) {
            $credits = DB::select("
                SELECT DISTINCT cre.*,
                    per.nom AS nom,
                    tip.nom AS tipo_credito,
                    CASE
                        WHEN hc.fechacuotapendiente IS NOT NULL THEN hc.fechacuotapendiente
                        ELSE cre.fechavencim_original -- usar fechavencim_original si no hay registro en historico
                    END AS FEC_CUOTA_X_VENCER,
                    CASE
                        WHEN hc.numcuotapag IS NOT NULL THEN hc.numcuotapag + 1
                        ELSE 1
                    END AS CUOTA_X_VENCER,
                    COALESCE(DATEDIFF(DAY, hc.fechacuotapendiente, GETDATE()), 0) AS DIAS_ATRASO
                FROM credito AS cre
                LEFT JOIN periodicidad AS per ON per.idperiodicidad = cre.idperiodicidadpago
                LEFT JOIN tipocredito AS tip ON tip.idtipocredito = cre.idtipocredito
                LEFT JOIN (
                    SELECT idcredito, idsocio, fechacuotapendiente, numcuotapag
                    FROM historico_creditos
                    WHERE fechareporte = CONVERT(DATE, GETDATE())
                    AND escierre = 0
                    AND idsocio = ?
                ) AS hc ON hc.idcredito = cre.idcredito AND hc.idsocio = cre.idsocio
                WHERE cre.escancelado = 0
                AND cre.monto <> 0
                AND cre.esdesembolsado = 1
                AND cre.esextorno = 0
                AND cre.idsocio = ?
                ORDER BY cre.idsocio
            ", [$socio->idsocio, $socio->idsocio]);
            foreach ($credits as $credit) {
                $idcredito = $credit->idcredito;
                $numcrono = $credit->numcrono;
                $cron_credit = DB::select("
                SELECT *
                FROM credito_cronograma
                WHERE idcredito = ? and idsocio = ? and numcrono = ?
            ", [$idcredito, $socio->idsocio, $numcrono]);
                $credit->cronogramas = $cron_credit;
            }

            foreach ($credits as $credit) {
                $idcredito = $credit->idcredito;
                $movimiento = DB::select("
               SELECT
                    idcredito AS ID_CREDITO,
                    idsocio AS ID_SOCIO,
                    concepto AS CONCEPTO,
                    LEFT(DATENAME(WEEKDAY, fecha), 3) + ' ' +
                    CONVERT(varchar(2), DAY(fecha)) + ' ' +
                    LEFT(DATENAME(MONTH, fecha), 3) + ' ' +
                    CONVERT(varchar(4), YEAR(fecha)) AS FECHA_AMORTIZACION,
                    totalrecibo AS MONTO_AMORTIZACION,
                    CASE
                        WHEN LEFT(hora, 2) > '12' THEN RIGHT('0' + CAST(LEFT(hora, 2) - 12 AS VARCHAR(2)), 2) + ':' + SUBSTRING(hora, 4, 2) + ' pm'
                        ELSE RIGHT('0' + CAST(LEFT(hora, 2) AS VARCHAR(2)), 2) + ':' + SUBSTRING(hora, 4, 2) + ' am'
                    END AS HORA_AMORTIZACION,
                    fecha AS FECHA_RAW,
                    hora AS HORA_RAW,
                    tipomov
                FROM pagocredito
                WHERE idcredito = ? and idsocio = ?
                ORDER BY fecha DESC, hora DESC
            ", [$idcredito, $socio->idsocio]);
                $credit->movimientos = $movimiento;
            }
            $savers = Saving::leftJoin("tipoahorro", "ahorro.idtipoahorro", "=", "tipoahorro.idtipoahorro")
                ->leftJoin("periodicidad", "ahorro.idperiodic_pagointeres", "=", "periodicidad.idperiodicidad")
                ->where("ahorro.escancelado", 0)
                ->where("ahorro.idtipoahorro", 1) // ✅ solo tipo de ahorro 1
                ->where("ahorro.saldo", ">", 0)    // ✅ solo saldos positivos
                ->where("ahorro.idsocio", $socio->idsocio)
                ->select(
                    "ahorro.*",
                    "tipoahorro.nom as TIPO_AHORRO",
                    "tipoahorro.idtipoahorro",
                    "periodicidad.nom"
                )
                ->orderBy("ahorro.fechaapertura", "asc")
                ->get();

            foreach ($savers as $saver) {
                $idahorro = $saver->idahorro;

                $cron_saver = DB::select("
                    SELECT *
                    FROM ahorro_cronograma
                    WHERE idahorro = ? AND idsocio = ?
                ", [$idahorro, $socio->idsocio]);

                $saver->cronogramas = $cron_saver;

                $mov_saver = DB::select("
                    SELECT *
                    FROM pagoahorro
                    WHERE idahorro = ? AND idsocio = ?
                    ORDER BY fecha DESC, hora DESC
                ", [$idahorro, $socio->idsocio]);

                $saver->movimientos = $mov_saver;

                foreach ($saver->movimientos as $mov) {
                    $fecha = Carbon::parse($mov->fecha);
                    $hora = Carbon::parse($mov->hora);
                    $transaction = [
                        "tipo" => "Ahorro",
                        "tipomov" => $mov->tipomov,
                        "concepto" => ucfirst(strtolower($mov->concepto)),
                        "moneda" => $saver->moneda,
                        "saldo" => $mov->total,
                        "fecha" => $fecha->translatedFormat('D d M Y'),
                        "hora" => $hora->translatedFormat('h:i A'),
                        "fecha_carbon" => $fecha,
                        "hora_carbon" => $hora,
                    ];
                    $last_transactions[] = $transaction;
                }
            }
            return $this->successResponse([
                'socio' => $socio,
                'creditos' => $credits,
                'savers' => $savers,
            ]);
        }

        return $this->successResponse([
            'socio' => null,
            'creditos' => [],
            'savers' => [],
        ]);
    }
    public function registerCobranza(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'aporte' => 'required',
            'idcreditos' => 'required|array',
            'montos' => 'required|array',
            'idsocio' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        $authHeader = $request->header('Authorization');
        list($login, $password) = explode(':', base64_decode(substr($authHeader, 6)));

        $cobranzaalta = CobranzaAlta::where('dni', $login)->first();
        $usuariocobranza = Usuario::where('idsocio', $cobranzaalta->idsocio)->first();

        $aporte = $request->aporte;
        $idcreditos = $request->idcreditos;
        $montos = $request->montos;
        $idsocio = $request->idsocio;

        $totalRecibo = $aporte + array_sum($montos); // Suma total del recibo

        $cobranzas_mercado = [];
        $cobranza_aporte = null;
        $lastRecibo = 0; // Variable para almacenar el número de recibo

        try {
            DB::transaction(function () use ($request, $aporte, $idcreditos, $montos, $idsocio, $usuariocobranza, &$cobranzas_mercado, &$cobranza_aporte, &$lastRecibo, &$totalRecibo) {
                // Obtener el último valor de "item" y bloquear la tabla
                $lastItem = DB::table('cobranza_mercado')->lockForUpdate()->max('item') ?? 0;
                $lastItem = (int) $lastItem;
                $lastItem++;  // Incrementar el número de recibo de forma segura
                // Obtener el último número de recibo para el socio y bloquear la tabla
                $lastRecibo = CobranzaMercado::where('idsocio', $idsocio)
                    ->lockForUpdate()  // Asegurar que el valor está bloqueado para actualizar
                    ->max('recibo') ?? 0;
                $lastRecibo = (int) $lastRecibo;
                $lastRecibo++;  // Incrementar el número de recibo de forma segura

                // Guardar cobranza de créditos
                foreach ($idcreditos as $index => $idCredito) {
                    $monto = $montos[$index];

                    if ($monto != 0) {
                        $cobranzaCredito = new CobranzaMercado([
                            'fecha' => Carbon::now()->format('d/m/Y H:i:s'),
                            'item' => $lastItem,
                            'idsocio' => $idsocio,
                            'idcredito' => $idCredito,
                            'montocredito' => $monto,
                            'total' => $monto,  // Guardar solo el monto de este registro
                            'fecharegistro' => Carbon::now()->format('d/m/Y H:i:s'),
                            'idusuarioregistro' => $usuariocobranza->id_usuario,
                            'origenaplic' => 1,
                            'idoficinaregistro' => 1,
                            'esliquidado' => 0,
                            'eseliminado' => 0,
                            'ipregistro' => $request->ip() ?? $request->telefono,
                            'recibo' => $lastRecibo
                        ]);

                        $cobranzaCredito->save();
                        $lastItem++;
                        $cobranzas_mercado[] = $cobranzaCredito;  // Añadir a los detalles de la cobranza
                    }
                }

                // Guardar cobranza de aporte si es mayor que cero
                if ($aporte > 0) {
                    $cobranza_aporte = new CobranzaMercado([
                        'fecha' => Carbon::now()->format('d/m/Y H:i:s'),
                        'item' => $lastItem,
                        'idsocio' => $idsocio,
                        'montoaporte' => $aporte,
                        'total' => $aporte,  // Guardar el aporte como total
                        'fecharegistro' => Carbon::now()->format('d/m/Y H:i:s'),
                        'idusuarioregistro' => $usuariocobranza->id_usuario,
                        'origenaplic' => 1,
                        'idoficinaregistro' => 1,
                        'esliquidado' => 0,
                        'eseliminado' => 0,
                        'ipregistro' => $request->ip() ?? $request->telefono,
                        'recibo' => $lastRecibo
                    ]);

                    $cobranza_aporte->save();
                    $cobranzas_mercado[] = $cobranza_aporte;  // Añadir también el aporte a los detalles de la cobranza
                }
            });

            // Obtenemos el socio después de la transacción
            $socio = Socio::where('idsocio', $idsocio)->first();

            // Buscar el representante (usuario) por el campo idusuarioregistro
            $representante = Usuario::where('id_usuario', $cobranzas_mercado[0]->idusuarioregistro)
                ->select('nombrelargo as nom_representante')
                ->first()
                ->nom_representante ?? 'N/A';

            // Devolver los datos en el formato de la cobranza
            return $this->successResponse([
                'recibo' => $lastRecibo,
                'idsocio' => $socio->idsocio,
                'nom_socio' => $socio->nom ?? '',
                'ap_socio' => $socio->ap ?? '',
                'am_socio' => $socio->am ?? '',
                'tipo' => $aporte > 0 ? ($totalRecibo == $aporte ? 'APORTE' : 'CRÉDITO + APORTE') : 'CRÉDITO', // Tipo
                'moneda' => '1', // Puedes ajustar según la moneda que necesites (1: soles, 2: dólares)
                'total' => $totalRecibo, // Total del recibo
                'fecha' => Carbon::now()->format('d/m/Y H:i:s'),
                'representante' => $representante, // Nombre del representante extraído
                'fecharegistro' => Carbon::now()->format('d/m/Y H:i:s'),
                'detalles' => $cobranzas_mercado, // Detalles de las cobranzas, incluyendo créditos y aporte
                'socio' => $socio
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ocurrió un error al procesar la transacción: ' . $e->getMessage(), 500);
        }
    }
    public function registerAhorro(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'idahorros' => 'required|array',
            'montos' => 'required|array',
            'idtipoahorros' => 'required|array',
            'idsocio' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        $authHeader = $request->header('Authorization');
        list($login, $password) = explode(':', base64_decode(substr($authHeader, 6)));

        $cobranzaalta = CobranzaAlta::where('dni', $login)->first();
        $usuariocobranza = Usuario::where('idsocio', $cobranzaalta->idsocio)->first();

        $idahorros = $request->idahorros;
        $montos = $request->montos;
        $idtipoahorros = $request->idtipoahorros;
        $idsocio = $request->idsocio;

        $totalRecibo = array_sum($montos);
        $cobranzas_mercado = [];
        $lastRecibo = 0;

        try {
            DB::transaction(function () use ($request, $idahorros, $montos, $idtipoahorros, $idsocio, $usuariocobranza, &$cobranzas_mercado, &$lastRecibo, $totalRecibo) {
                $lastItem = DB::table('cobranza_mercado')->lockForUpdate()->max('item') ?? 0;
                $lastItem++;

                $lastRecibo = CobranzaMercado::where('idsocio', $idsocio)
                    ->lockForUpdate()
                    ->max('recibo') ?? 0;
                $lastRecibo++;

                foreach ($idahorros as $index => $idAhorro) {
                    $monto = $montos[$index];
                    $idtipoahorro = $idtipoahorros[$index];

                    if ($monto != 0) {
                        $cobranzaAhorro = new CobranzaMercado([
                            'fecha' => Carbon::now()->format('d/m/Y H:i:s'),
                            'item' => $lastItem,
                            'idsocio' => $idsocio,
                            'idahorro' => $idAhorro,
                            'idtipoahorro' => $idtipoahorro,
                            'montoahorro' => $monto,
                            'total' => $monto,
                            'fecharegistro' => Carbon::now()->format('d/m/Y H:i:s'),
                            'idusuarioregistro' => $usuariocobranza->id_usuario,
                            'origenaplic' => 1,
                            'idoficinaregistro' => 1,
                            'esliquidado' => 0,
                            'eseliminado' => 0,
                            'ipregistro' => $request->ip() ?? $request->telefono,
                            'recibo' => $lastRecibo
                        ]);

                        $cobranzaAhorro->save();
                        $cobranzas_mercado[] = $cobranzaAhorro;
                        $lastItem++;
                    }
                }
            });

            $socio = Socio::where('idsocio', $idsocio)->first();
            $representante = Usuario::where('id_usuario', $cobranzas_mercado[0]->idusuarioregistro)
                ->select('nombrelargo as nom_representante')
                ->first()
                ->nom_representante ?? 'N/A';

            return $this->successResponse([
                'recibo' => $lastRecibo,
                'idsocio' => $socio->idsocio,
                'nom_socio' => $socio->nom ?? '',
                'ap_socio' => $socio->ap ?? '',
                'am_socio' => $socio->am ?? '',
                'tipo' => 'AHORRO',
                'moneda' => '1',
                'total' => $totalRecibo,
                'fecha' => Carbon::now()->format('d/m/Y H:i:s'),
                'representante' => $representante,
                'fecharegistro' => Carbon::now()->format('d/m/Y H:i:s'),
                'detalles' => $cobranzas_mercado,
                'socio' => $socio
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse('Ocurrió un error al registrar el ahorro: ' . $e->getMessage(), 500);
        }
    }
    public function history(Request $request)
    {
        // Obtener el usuario desde el header Authorization
        $authHeader = $request->header('Authorization');
        list($login, $password) = explode(':', base64_decode(substr($authHeader, 6)));

        $cobranzaalta = CobranzaAlta::where('dni', $login)->first();
        $usuariocobranza = Usuario::where('idsocio', $cobranzaalta->idsocio)->first();

        // Obtener la fecha actual en formato compatible
        $fechaHoy = Carbon::now()->format('d/m/Y');

        // Traer los recibos del día actual hechos por el usuario
        $recibos = CobranzaMercado::whereDate(DB::raw("STR_TO_DATE(fecha, '%d/%m/%Y')"), Carbon::today())
            ->where('idusuarioregistro', $usuariocobranza->id_usuario)
            ->where('eseliminado', 0)
            ->select('recibo', 'idsocio', 'fecha', 'fecharegistro')
            ->distinct()
            ->orderBy('fecha', 'desc')
            ->limit(50)
            ->get();

        $historial = [];

        foreach ($recibos as $recibo) {
            $detallesCobranza = CobranzaMercado::leftJoin("credito", 'credito.idcredito', '=', "cobranza_mercado.idcredito")
                ->leftJoin("socio", 'socio.idsocio', '=', "cobranza_mercado.idsocio")
                ->leftJoin("usuario", 'usuario.id_usuario', '=', "cobranza_mercado.idusuarioregistro")
                ->where('cobranza_mercado.recibo', $recibo->recibo)
                ->where('cobranza_mercado.idsocio', $recibo->idsocio)
                ->where('cobranza_mercado.eseliminado', 0)
                ->select(
                    'cobranza_mercado.*',
                    'credito.moneda as moneda',
                    'socio.nom as nom_socio',
                    'socio.ap as ap_socio',
                    'socio.am as am_socio',
                    'usuario.nombrelargo as nom_representante'
                )
                ->orderBy('cobranza_mercado.item', 'desc')
                ->get();

            $tipoCobranza = $this->determinarTipoCobranza($detallesCobranza);
            $totalRecibo = $detallesCobranza->sum('total');

            $historial[] = [
                'recibo' => $recibo->recibo,
                'idsocio' => $recibo->idsocio,
                'nom_socio' => $detallesCobranza->first()->nom_socio ?? '',
                'ap_socio' => $detallesCobranza->first()->ap_socio ?? '',
                'am_socio' => $detallesCobranza->first()->am_socio ?? '',
                'tipo' => $tipoCobranza,
                'moneda' => $detallesCobranza->first()->moneda ?? '1',
                'total' => $totalRecibo,
                'fecha' => $recibo->fecha,
                'representante' => $detallesCobranza->first()->nom_representante ?? 'N/A',
                'fecharegistro' => $detallesCobranza->first()->fecharegistro ?? null,
                'detalles' => $detallesCobranza,
            ];
        }

        return $this->successResponse($historial);
    }




    public function searchCobranza(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        $searchTerm = $request->search;

        // Buscar al socio por dni o idsocio
        $socio = Socio::where(function ($query) use ($searchTerm) {
            $query->where('dni', '=', $searchTerm)
                ->orWhere('idsocio', '=', $searchTerm);
        })
            ->where('idtipopersona', 1) // socios
            ->first();

        $cobranzas_agrupadas = [];

        if ($socio) {
            // Obtener todos los recibos únicos agrupados por idsocio y recibo
            $recibos = CobranzaMercado::where('cobranza_mercado.idsocio', $socio->idsocio)
                ->where('cobranza_mercado.eseliminado', 0) // Filtrar registros no eliminados
                ->select('recibo', 'idsocio', 'fecha', 'fecharegistro') // Seleccionar recibo, idsocio, fecha y fecharegistro
                ->distinct()
                ->orderBy('fecha', 'desc')
                ->limit(100)
                ->get();

            // Recorrer los recibos y obtener los detalles de las cobranzas asociadas a cada uno
            foreach ($recibos as $recibo) {
                $detallesCobranza = CobranzaMercado::leftJoin("credito", 'credito.idcredito', '=', "cobranza_mercado.idcredito")
                    ->leftJoin("socio", 'socio.idsocio', '=', "cobranza_mercado.idsocio")
                    ->leftJoin("usuario", 'usuario.id_usuario', '=', "cobranza_mercado.idusuarioregistro")
                    ->where('cobranza_mercado.recibo', $recibo->recibo)
                    ->where('cobranza_mercado.idsocio', $recibo->idsocio)
                    ->where('cobranza_mercado.eseliminado', 0)
                    ->select(
                        'cobranza_mercado.*',
                        'credito.moneda as moneda',
                        'socio.nom as nom_socio',
                        'socio.ap as ap_socio',
                        'socio.am as am_socio',
                        'usuario.nombrelargo as nom_representante' // Aquí ya tenemos el representante
                    )
                    ->orderBy('cobranza_mercado.item', 'desc')
                    ->get();

                // Calcular el tipo de cobranza
                $tipoCobranza = $this->determinarTipoCobranza($detallesCobranza);

                // Sumar los totales de los registros asociados al recibo
                $totalRecibo = $detallesCobranza->sum('total');

                // Guardar el recibo, el socio, los detalles de la cobranza asociados, el tipo, el total y el representante
                $cobranzas_agrupadas[] = [
                    'recibo' => $recibo->recibo,
                    'idsocio' => $recibo->idsocio,
                    'nom_socio' => $detallesCobranza->first()->nom_socio ?? '',
                    'ap_socio' => $detallesCobranza->first()->ap_socio ?? '',
                    'am_socio' => $detallesCobranza->first()->am_socio ?? '',
                    'tipo' => $tipoCobranza, // Tipo de cobranza (Crédito, Aporte o ambos)
                    'moneda' => $detallesCobranza->first()->moneda ?? 'N/A', // Moneda del primer registro
                    'total' => $totalRecibo, // Sumar el total de los registros asociados al recibo
                    'representante' => $detallesCobranza->first()->nom_representante ?? 'N/A', // Representante
                    'fecha' => $recibo->fecha, // Fecha
                    'fecharegistro' => $detallesCobranza->first()->fecharegistro ?? null, // Fecha de registro
                    'detalles' => $detallesCobranza, // Detalles de las cobranzas
                ];
            }
        }

        return $this->successResponse($cobranzas_agrupadas);
    }

    public function deleteCobranza(Request $request)
    {
        // Validamos que se envíen los campos recibo, idsocio y fecha
        $validator = Validator::make($request->all(), [
            'recibo' => 'required',
            'idsocio' => 'required|exists:socio,idsocio', // Validamos que el idsocio exista en la tabla socio
            'fecha' => 'required', // Validamos que la fecha sea un campo de tipo fecha
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        try {
            // Obtener el usuario autenticado
            $authHeader = $request->header('Authorization');
            list($login, $password) = explode(':', base64_decode(substr($authHeader, 6)));

            // Obtener información del usuario y cobranza
            $cobranzaalta = CobranzaAlta::where('dni', $login)->first();
            if (!$cobranzaalta) {
                return $this->errorResponse('Usuario no encontrado', 404);
            }

            $usuariocobranza = Usuario::where('idsocio', $cobranzaalta->idsocio)->first();
            if (!$usuariocobranza) {
                return $this->errorResponse('Información del usuario no encontrada', 404);
            }

            // Parámetros para la actualización
            $recibo = $request->recibo;
            $idsocio = $request->idsocio;
            $fecha = $request->fecha;
            $idusuariomodifica = $usuariocobranza->id_usuario;

            // Actualizamos las cobranzas que coincidan con los parámetros
            $updatedRows = CobranzaMercado::where('recibo', $recibo)
                ->where('idsocio', $idsocio)
                ->whereDate('fecha',  Carbon::parse($fecha)->format('Y-m-d H:i:s'))
                ->update([
                    'eseliminado' => 1,
                    'idusuariomodifica' => $idusuariomodifica,
                    //'fechamodifica' => Carbon::now()->format('Y-m-d H:i:s'),
                    'ipmodifica' => $request->ip()
                ]);

            // Si no se actualizan filas, lanzamos un error
            if ($updatedRows > 0) {
                Log::info("Cobranza actualizada correctamente", [
                    'recibo' => $recibo,
                    'idsocio' => $idsocio,
                    'fecha' => $fecha,
                    'filas_actualizadas' => $updatedRows
                ]);

                return $this->successResponse('Todas las cobranzas con recibo ' . $recibo . ', socio ' . $idsocio . ' y fecha ' . $fecha . ' fueron marcadas como eliminadas.');
            } else {
                Log::error("No se encontraron cobranzas para actualizar.", [
                    'recibo' => $recibo,
                    'idsocio' => $idsocio,
                    'fecha' => $fecha
                ]);
                return $this->errorResponse('No se encontraron cobranzas para actualizar.', 404);
            }
        } catch (\Exception $e) {
            // En caso de excepción, la capturamos y retornamos un error
            Log::error("Error al intentar eliminar la cobranza", [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Error al intentar eliminar la cobranza.', 500);
        }
    }

    // Función para determinar el tipo de cobranza
    private function determinarTipoCobranza($detallesCobranza)
    {
        $hayCredito = false;
        $hayAporte = false;
        $hayAhorro = false;

        foreach ($detallesCobranza as $detalle) {
            if (!empty($detalle->montocredito) && $detalle->montocredito > 0) {
                $hayCredito = true;
            }
            if (!empty($detalle->montoaporte) && $detalle->montoaporte > 0) {
                $hayAporte = true;
            }
            if (!empty($detalle->montoahorro) && $detalle->montoahorro > 0) {
                $hayAhorro = true;
            }
        }

        if ($hayCredito && $hayAporte) {
            return 'CRÉDITO + APORTE';
        } elseif ($hayCredito && $hayAhorro) {
            return 'CRÉDITO + AHORRO';
        } elseif ($hayAporte && $hayAhorro) {
            return 'APORTE + AHORRO';
        } elseif ($hayCredito) {
            return 'CRÉDITO';
        } elseif ($hayAporte) {
            return 'APORTE';
        } elseif ($hayAhorro) {
            return 'AHORRO';
        }

        return 'N/A';
    }
}
