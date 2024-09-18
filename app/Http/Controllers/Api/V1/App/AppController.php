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
                SELECT cre.*,
                per.nom AS nom,
                tip.nom AS tipo_credito,
                tc.fecha AS FEC_CUOTA_X_VENCER,
                WC.Num_cuota as CUOTA_X_VENCER,
                coalesce(DATEDIFF(DAY, (tc.fecha), getdate()), 0) as DIAS_ATRASO
                FROM credito AS cre
                LEFT JOIN periodicidad AS per ON per.idperiodicidad = cre.idperiodicidadpago
                LEFT JOIN tipocredito AS tip ON tip.idtipocredito = cre.idtipocredito
                LEFT JOIN ((select idcredito as idcredito, min(fechacuota) as fecha from credito_por_vencer group by idcredito))  as TC on  TC.idcredito = cre.idcredito
                LEFT JOIN ((select idcredito as idcredito, min(numcuota) as Num_cuota from credito_por_vencer group by idcredito))  as WC on  WC.idcredito = cre.idcredito
                WHERE cre.escancelado = 0   and cre.monto <> 0 and cre.esdesembolsado = 1  and cre.esextorno = 0 and cre.idsocio = ?  order by cre.idsocio
            ", [$socio->idsocio]);
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

        $cobranzas_mercado = [];

        // Obtener el último recibo para el idsocio
        $lastRecibo = CobranzaMercado::where('idsocio', $idsocio)->max('item') ?? 0;

        // Incrementar el último recibo para el nuevo registro
        $lastRecibo++;

        // Guardar cobranza de créditos
        foreach ($idcreditos as $index => $idCredito) {
            $monto = $montos[$index];

            if ($monto != 0) {
                $cobranzaCredito = new CobranzaMercado([
                    'fecha' => now(),
                    'item' => $lastRecibo, // Usar el último recibo guardado
                    'idsocio' => $idsocio,
                    'idcredito' => $idCredito,
                    'montocredito' => $monto,
                    'fecharegistro' => Carbon::now(),
                    'idusuarioregistro' => $ususariocobranza->id_usuario,
                    'origenaplic' => 1
                ]);

                $cobranzaCredito->save();


                $credito = Credit::where('idcredito', $idCredito)->first();
                if ($credito) {
                    $cobranzaCredito->moneda = $credito->moneda;
                }


                $cobranzas_mercado[] = $cobranzaCredito;

                // Actualizar el último recibo
                $lastRecibo++;
            }
        }
        $cobranza_aporte = null;
        // Verificar y guardar cobranza de aporte si es mayor que cero
        if ($aporte > 0) {
            $cobranza_aporte = new CobranzaMercado([
                'fecha' => now(),
                'item' => $lastRecibo, // Usar el último recibo guardado
                'idsocio' => $idsocio,
                'montoaporte' => $aporte,
                'fecharegistro' => Carbon::now(),
                'idusuarioregistro' => $ususariocobranza->id_usuario,
                'origenaplic' => 1
            ]);

            $cobranza_aporte->save();


            $cobranza_aporte->moneda = 1; //soles

        }

        $socio = Socio::where('idsocio', $idsocio)->first();

        return $this->successResponse([
            'cobranzas_mercado' => $cobranzas_mercado,
            'cobranza_aporte' => $cobranza_aporte,
            'socio' => $socio
        ]);
    }
    public function history(Request $request)
    {

        $authHeader = $request->header('Authorization');

        list($login, $password) = explode(':', base64_decode(substr($authHeader, 6)));


        $cobranzaalta = CobranzaAlta::where('dni', $login)->first();

        $ususariocobranza = Usuario::where('idsocio', $cobranzaalta->idsocio)->first();


        $cobranzas_mercado = CobranzaMercado::leftJoin("credito", 'credito.idcredito', '=', "cobranza_mercado.idcredito")
            ->leftJoin("socio", 'socio.idsocio', '=', "cobranza_mercado.idsocio")
            ->where('cobranza_mercado.idusuarioregistro', $ususariocobranza->id_usuario)
            ->select(
                'cobranza_mercado.*',
                'credito.moneda as moneda',
                'socio.nom as nom_socio',
                'socio.ap as ap_socio',
                'socio.am as am_socio'
            )
            ->orderBy('cobranza_mercado.fecha','desc')
            ->orderBy('cobranza_mercado.item', 'desc')
            ->limit(50)
            ->get();




        return $this->successResponse($cobranzas_mercado);
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

        /**buscamos al socio por dni o idsocio */
        $socio = Socio::where(function ($query) use ($searchTerm) {
            $query->where('dni', '=',  $searchTerm)
                ->orWhere('idsocio', '=', $searchTerm);
        })
            ->where('idtipopersona', 1) // socios
            ->first();

        $cobranzas_mercado = [];

        if($socio){
            $cobranzas_mercado = CobranzaMercado::leftJoin("credito", 'credito.idcredito', '=', "cobranza_mercado.idcredito")
                ->leftJoin("socio", 'socio.idsocio', '=', "cobranza_mercado.idsocio")
                ->leftJoin("usuario", 'usuario.id_usuario', '=', "cobranza_mercado.idusuarioregistro")
                ->where('cobranza_mercado.idsocio', $socio->idsocio)
                ->select(
                    'cobranza_mercado.*',
                    'credito.moneda as moneda',
                    'socio.nom as nom_socio',
                    'socio.ap as ap_socio',
                    'socio.am as am_socio',
                    'usuario.nombrelargo as nom_representante'
                )
                ->orderBy('cobranza_mercado.fecha','desc')
                ->orderBy('cobranza_mercado.item', 'desc')
                ->limit(100)
                ->get();
        }


        return $this->successResponse($cobranzas_mercado);
    }
}
