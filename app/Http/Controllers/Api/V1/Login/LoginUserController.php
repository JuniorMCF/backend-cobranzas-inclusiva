<?php

namespace App\Http\Controllers\Api\V1\Login;

use App\Http\Controllers\ApiController;
use App\Models\CustomUser;
use App\Models\Socio;
use App\Models\CobranzaAlta;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class LoginUserController extends ApiController
{
    //

    public function signIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login' => 'required|string', //dni
            'password' => 'required|string', //codigo - 1er apellido
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }
        // Verificar si el usuario existe por dni o ruc
        $user = Socio::where('dni', $request->login)
            ->orWhere('ruc', $request->login)
            ->first();

        if (!$user) {
            return $this->errorResponse("Credenciales no válidas", 404);
        }
        /**
         * si el usuario existe tenemos que verificar si cumple con los requisitos para usar la aplicacion
         * es decir que escobranzaaplic  o  esreportegerencial  este en 1
         */

        if (!Usuario::where('idsocio', optional($user)->idsocio)
            ->when(function ($query) {
                return $query->where('escobranzaaplic', 1)
                    ->orWhere('esreportegerencial', 1);
            })
            ->first()) {

            return $this->errorResponse("Usuario no autorizado", 404);
        }


        if (!CobranzaAlta::where("dni", $request->login)->first()) {
            return $this->errorResponse("El usuario no ha completado su registro", 403);
        }

        if (!CobranzaAlta::where("dni", $request->login)->where('status',1)->first()) {
            return $this->errorResponse("El usuario ha sido dado de baja", 404);
        }

        /**si existe el usuario verificamos que coincida con la clave*/
        $user = CobranzaAlta::where("dni", $request->login)->first();



        if (Hash::check($request->password, $user->pswd)) {

            $data = Socio::leftJoin('usuario', 'usuario.idsocio', '=', 'socio.idsocio')
                ->where(function ($query) use ($user) {
                    $query->where('socio.idsocio', $user->idsocio);
                })
                ->select('socio.*', 'usuario.escobranzaaplic', 'usuario.esreportegerencial','usuario.nombre','usuario.nombrelargo')
                ->first();

            if (!isset($data->estado)) {
                $data->setAttribute('estado', 'A');
            }

            return $this->successResponse($data);
        } else {
            return $this->errorResponse("Credenciales no válidas", 404);
        }
    }

}
