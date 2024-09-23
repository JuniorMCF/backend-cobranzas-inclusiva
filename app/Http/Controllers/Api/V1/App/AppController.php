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
        $ususariocobranza = Usuario::where('idsocio', $cobranzaalta->idsocio)->first();

        $aporte = $request->aporte;
        $idcreditos = $request->idcreditos;
        $montos = $request->montos;
        $idsocio = $request->idsocio;

        // Calculamos el total de la cobranza (sumando aportes y montos de créditos)
        $totalCobranza = $aporte + array_sum($montos);

        $cobranzas_mercado = [];
        $cobranza_aporte = null;

        try {
            DB::transaction(function () use ($request, $aporte, $idcreditos, $montos, $idsocio, $ususariocobranza, $totalCobranza, &$cobranzas_mercado, &$cobranza_aporte) {
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
                            'total' => $totalCobranza,  // Guardar el total en cada registro
                            'fecharegistro' => Carbon::now()->format('d/m/Y H:i:s'),
                            'idusuarioregistro' => $ususariocobranza->id_usuario,
                            'origenaplic' => 1,
                            'idoficinaregistro' => 1,
                            'esliquidado' => 0,
                            'eseliminado' => 0,
                            'ipregistro' => $request->ip() ?? $request->telefono,
                            'recibo' => $lastRecibo
                        ]);

                        $cobranzaCredito->save();

                        // Incrementar el item para el siguiente registro
                        $lastItem++;

                        $cobranzas_mercado[] = $cobranzaCredito;  // Guardamos el registro en el array para devolverlo
                    }
                }

                // Guardar cobranza de aporte si es mayor que cero
                if ($aporte > 0) {
                    $cobranza_aporte = new CobranzaMercado([
                        'fecha' => Carbon::now()->format('d/m/Y H:i:s'),
                        'item' => $lastItem,
                        'idsocio' => $idsocio,
                        'montoaporte' => $aporte,
                        'total' => $totalCobranza,  // Guardar el total en el aporte
                        'fecharegistro' => Carbon::now()->format('d/m/Y H:i:s'),
                        'idusuarioregistro' => $ususariocobranza->id_usuario,
                        'origenaplic' => 1,
                        'idoficinaregistro' => 1,
                        'esliquidado' => 0,
                        'eseliminado' => 0,
                        'ipregistro' => $request->ip() ?? $request->telefono,
                        'recibo' => $lastRecibo
                    ]);

                    $cobranza_aporte->save();  // Guardamos el registro para devolverlo
                }
            });

            // Obtenemos el socio después de la transacción
            $socio = Socio::where('idsocio', $idsocio)->first();

            return $this->successResponse([
                'cobranzas_mercado' => $cobranzas_mercado,
                'cobranza_aporte' => $cobranza_aporte,
                'socio' => $socio
            ]);
        } catch (\Exception $e) {
            // Si hay algún error, hacemos rollback y devolvemos un mensaje de error
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
        $ususariocobranza = Usuario::where('idsocio', $cobranzaalta->idsocio)->first();

        // Obtener todos los recibos únicos agrupados por idsocio y recibo
        $recibos = CobranzaMercado::where('cobranza_mercado.idusuarioregistro', $ususariocobranza->id_usuario)
            ->where('cobranza_mercado.eseliminado', 0) // Filtrar registros no eliminados
            ->select('recibo', 'idsocio', 'fecha') // Seleccionar recibo e idsocio
            ->distinct() // Asegurarse de no repetir
            ->orderBy('cobranza_mercado.fecha', 'desc') // Ordenar por la fecha más reciente
            ->limit(50) // Limitar a los 50 recibos más recientes
            ->get();

        // Inicializar array para almacenar el historial por recibo
        $historial = [];

        // Recorrer los recibos y obtener los detalles de las cobranzas asociadas a cada uno
        foreach ($recibos as $recibo) {
            $detallesCobranza = CobranzaMercado::leftJoin("credito", 'credito.idcredito', '=', "cobranza_mercado.idcredito")
                ->leftJoin("socio", 'socio.idsocio', '=', "cobranza_mercado.idsocio")
                ->where('cobranza_mercado.recibo', $recibo->recibo) // Filtrar por recibo
                ->where('cobranza_mercado.idsocio', $recibo->idsocio) // Filtrar por socio
                ->where('cobranza_mercado.eseliminado', 0) // Filtrar registros no eliminados
                ->select(
                    'cobranza_mercado.*',
                    'credito.moneda as moneda',
                    'socio.nom as nom_socio',
                    'socio.ap as ap_socio',
                    'socio.am as am_socio'
                )
                ->orderBy('cobranza_mercado.item', 'desc')
                ->get();

            // Calcular el tipo de cobranza
            $tipoCobranza = $this->determinarTipoCobranza($detallesCobranza);

            // Obtener la moneda y el total desde cualquier registro válido
            $moneda = $detallesCobranza->first()->moneda ?? '1'; // Moneda del primer registro  1 : soles 2 : dolares
            $total = $detallesCobranza->first()->total ?? 0; // Total del primer registro

            // Guardar el recibo, el socio, los detalles de la cobranza asociados, el tipo y el total
            $historial[] = [
                'recibo' => $recibo->recibo,
                'idsocio' => $recibo->idsocio,
                'nom_socio' => $detallesCobranza->first()->nom_socio ?? '',
                'ap_socio' => $detallesCobranza->first()->ap_socio ?? '',
                'am_socio' => $detallesCobranza->first()->am_socio ?? '',
                'tipo' => $tipoCobranza, // Tipo de cobranza (Crédito, Aporte o ambos)
                'moneda' => $moneda, // Moneda
                'total' => $total, // Total
                'detalles' => $detallesCobranza, // Detalles de las cobranzas
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
            $query->where('dni', '=',  $searchTerm)
                ->orWhere('idsocio', '=', $searchTerm);
        })
            ->where('idtipopersona', 1) // socios
            ->first();

        $cobranzas_agrupadas = [];

        if ($socio) {
            // Obtener todos los recibos únicos agrupados por idsocio y recibo
            $recibos = CobranzaMercado::where('cobranza_mercado.idsocio', $socio->idsocio)
                ->where('cobranza_mercado.eseliminado', 0) // Filtrar registros no eliminados
                ->select('recibo', 'idsocio', 'fecha') // Seleccionar recibo, idsocio y fecha
                ->distinct() // Asegurarse de no repetir recibos
                ->orderBy('fecha', 'desc') // Ordenar por la fecha más reciente
                ->limit(100) // Limitar a los 100 recibos más recientes
                ->get();

            // Recorrer los recibos y obtener los detalles de las cobranzas asociadas a cada uno
            foreach ($recibos as $recibo) {
                $detallesCobranza = CobranzaMercado::leftJoin("credito", 'credito.idcredito', '=', "cobranza_mercado.idcredito")
                    ->leftJoin("socio", 'socio.idsocio', '=', "cobranza_mercado.idsocio")
                    ->leftJoin("usuario", 'usuario.id_usuario', '=', "cobranza_mercado.idusuarioregistro")
                    ->where('cobranza_mercado.recibo', $recibo->recibo) // Filtrar por recibo
                    ->where('cobranza_mercado.idsocio', $recibo->idsocio) // Filtrar por socio
                    ->where('cobranza_mercado.eseliminado', 0) // Filtrar registros no eliminados
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

                // Calcular el tipo de cobranza
                $tipoCobranza = $this->determinarTipoCobranza($detallesCobranza);

                // Obtener la moneda y el total desde cualquier registro válido
                $moneda = $detallesCobranza->first()->moneda ?? 'N/A'; // Moneda del primer registro
                $total = $detallesCobranza->first()->total ?? 0; // Total del primer registro

                // Guardar el recibo, el socio, los detalles de la cobranza asociados, el tipo y el total
                $cobranzas_agrupadas[] = [
                    'recibo' => $recibo->recibo,
                    'idsocio' => $recibo->idsocio,
                    'nom_socio' => $detallesCobranza->first()->nom_socio ?? '',
                    'ap_socio' => $detallesCobranza->first()->ap_socio ?? '',
                    'am_socio' => $detallesCobranza->first()->am_socio ?? '',
                    'tipo' => $tipoCobranza, // Tipo de cobranza (Crédito, Aporte o ambos)
                    'moneda' => $moneda, // Moneda
                    'total' => $total, // Total
                    'detalles' => $detallesCobranza, // Detalles de las cobranzas
                ];
            }
        }

        return $this->successResponse($cobranzas_agrupadas);
    }


    public function deleteCobranza(Request $request)
    {
        // Validamos que se envíen los campos idsocio, idcredito, item e idusuariomodifica
        $validator = Validator::make($request->all(), [
            'idsocio' => 'required|exists:socio,idsocio',
            'idcredito' => 'required|exists:credito,idcredito',
            'item' => 'required|numeric',
            'idusuariomodifica' => 'required|exists:usuarios,id_usuario'
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        $idsocio = $request->idsocio;
        $idcredito = $request->idcredito;
        $item = $request->item;
        $idusuariomodifica = $request->idusuariomodifica;

        // Buscar la cobranza por idsocio, idcredito e item
        $cobranza = CobranzaMercado::where('idsocio', $idsocio)
            ->where('idcredito', $idcredito)
            ->where('item', $item)
            ->first();

        if (!$cobranza) {
            return $this->errorResponse('Cobranza no encontrada', 404);
        }

        // Obtenemos el recibo de la cobranza
        $recibo = $cobranza->recibo;

        // Sumamos el monto que se va a eliminar (monto total registrado en ese ítem)
        $montoEliminar = $cobranza->montocredito ?? $cobranza->montoaporte;

        // Cambiar el estado de eseliminado a 1 y actualizar la información de modificación
        $cobranza->eseliminado = 1;
        $cobranza->idusuariomodifica = $idusuariomodifica; // Guardar el usuario que realiza la modificación
        $cobranza->fechamodifica = Carbon::now()->format('d/m/Y H:i:s'); // Actualizar la fecha de modificación
        $cobranza->ipmodifica = $request->ip(); // Guardar la IP del cliente que realiza la modificación
        $cobranza->save();

        // Actualizamos el total en todos los registros asociados al mismo recibo
        CobranzaMercado::where('recibo', $recibo)
            ->where('idsocio', $idsocio)
            ->where('eseliminado', 0) // Sólo registros activos
            ->update(['total' => DB::raw("total - $montoEliminar")]);

        return $this->successResponse('Cobranza eliminada exitosamente');
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
