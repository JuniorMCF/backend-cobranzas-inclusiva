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
        $socio = Socio::where(function ($query) use ($searchTerm) {
            $query->where('dni', '=',  $searchTerm)
                ->orWhere('idsocio', '=', $searchTerm);
        })
            ->where('idtipopersona', 1) // socios
            ->first();

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
        }



        return $this->successResponse([
            'socio' => $socio,
            'creditos' => $credits
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
                $lastItem++;

                // Obtener el último número de recibo para el socio
                $lastRecibo = CobranzaMercado::where('idsocio', $idsocio)->max('recibo') ?? 0;
                $lastRecibo++;

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

    public function history(Request $request)
    {
        // Obtener el usuario desde el header Authorization
        $authHeader = $request->header('Authorization');
        list($login, $password) = explode(':', base64_decode(substr($authHeader, 6)));

        // Obtener información del usuario y cobranza
        $cobranzaalta = CobranzaAlta::where('dni', $login)->first();
        $usuariocobranza = Usuario::where('idsocio', $cobranzaalta->idsocio)->first();

        // Obtener todos los recibos únicos agrupados por idsocio y recibo
        $recibos = CobranzaMercado::where('cobranza_mercado.idusuarioregistro', $usuariocobranza->id_usuario)
            ->where('cobranza_mercado.eseliminado', 0)
            ->select('recibo', 'idsocio', 'fecha', 'fecharegistro')
            ->distinct()
            ->orderBy('cobranza_mercado.fecha', 'desc')
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

            // Sumar los totales de los registros asociados al recibo
            $totalRecibo = $detallesCobranza->sum('total');

            $historial[] = [
                'recibo' => $recibo->recibo,
                'idsocio' => $recibo->idsocio,
                'nom_socio' => $detallesCobranza->first()->nom_socio ?? '',
                'ap_socio' => $detallesCobranza->first()->ap_socio ?? '',
                'am_socio' => $detallesCobranza->first()->am_socio ?? '',
                'tipo' => $tipoCobranza,
                'moneda' => $detallesCobranza->first()->moneda ?? '1', // Moneda del primer registro
                'total' => $totalRecibo, // Total del recibo
                'fecha' => $recibo->fecha,
                'representante' => $detallesCobranza->first()->nom_representante ?? 'N/A', // Representante
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
            'fecha' => 'required|date', // Validamos que la fecha sea un campo de tipo fecha

        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }


        $authHeader = $request->header('Authorization');
        list($login, $password) = explode(':', base64_decode(substr($authHeader, 6)));

        // Obtener información del usuario y cobranza
        $cobranzaalta = CobranzaAlta::where('dni', $login)->first();
        $usuariocobranza = Usuario::where('idsocio', $cobranzaalta->idsocio)->first();

        $recibo = $request->recibo;
        $idsocio = $request->idsocio;
        $fecha = $request->fecha;
        $idusuariomodifica = $usuariocobranza->id_usuario;

        // Buscar todas las cobranzas que coincidan con el recibo, el idsocio y la fecha
        $cobranzas = CobranzaMercado::where('recibo', $recibo)
            ->where('idsocio', $idsocio)
            ->whereDate('fecha', $fecha) // Aseguramos que la fecha coincida exactamente
            ->get();

        if ($cobranzas->isEmpty()) {
            return $this->errorResponse('Cobranza no encontrada para este socio, recibo y fecha', 404);
        }

        // Marcar todas las cobranzas con el mismo recibo, idsocio y fecha como eliminadas
        foreach ($cobranzas as $cobranza) {
            $cobranza->eseliminado = 1;
            $cobranza->idusuariomodifica = $idusuariomodifica; // Guardar el usuario que realiza la modificación
            $cobranza->fechamodifica = Carbon::now()->format('Y-m-d H:i:s'); // Actualizar la fecha de modificación
            $cobranza->ipmodifica = $request->ip(); // Guardar la IP del cliente que realiza la modificación
            $cobranza->save();
        }

        // Retornar respuesta exitosa
        return $this->successResponse('Todas las cobranzas con recibo ' . $recibo . ', socio ' . $idsocio . ' y fecha ' . $fecha . ' fueron marcadas como eliminadas.');
    }

    // Función para determinar el tipo de cobranza
    private function determinarTipoCobranza($detallesCobranza)
    {
        $hayCredito = false;
        $hayAporte = false;

        foreach ($detallesCobranza as $detalle) {
            if ($detalle->montocredito > 0) {
                $hayCredito = true;
            }
            if ($detalle->montoaporte > 0) {
                $hayAporte = true;
            }
        }

        if ($hayCredito && $hayAporte) {
            return 'CREDITO + APORTE';
        } elseif ($hayCredito) {
            return 'CREDITO';
        } elseif ($hayAporte) {
            return 'APORTE';
        }

        return 'N/A'; // Si no hay ni crédito ni aporte
    }
}
