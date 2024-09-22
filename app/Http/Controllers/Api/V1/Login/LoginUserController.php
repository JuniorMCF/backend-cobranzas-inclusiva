<?php

namespace App\Http\Controllers\Api\V1\Login;

use App\Http\Controllers\ApiController;
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
            'login' => 'required|string', // dni o ruc
            'password' => 'required|string', // clave
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        // Verificar si el usuario existe por dni o ruc en el campo dni
        $user = Usuario::where('dni', $request->login)->first();

        // Si no se encuentra el usuario
        if (!$user) {
            return $this->errorResponse("Credenciales no válidas", 404);
        }

        // Verificar si el usuario tiene acceso permitido a la aplicación
        $usuarioPermisos = Usuario::where('idsocio', $user->idsocio)
            ->where(function ($query) {
                $query->where('escobranzaaplic', 1)
                    ->orWhere('esreportegerencial', 1);
            })->first();

        // Si no tiene permisos, se le permitirá el acceso pero se ajustarán los permisos a 0
        if (!$usuarioPermisos) {
            Usuario::where('idsocio', $user->idsocio)->update([
                'escobranzaaplic' => 0,
                'esreportegerencial' => 0,
            ]);
        }

        // Verificar si el usuario ha completado su registro
        if (!CobranzaAlta::where("dni", $request->login)->exists()) {
            return $this->errorResponse("El usuario no ha completado su registro", 403);
        }

        // Verificar si el usuario ha sido dado de baja
        if (!CobranzaAlta::where("dni", $request->login)->where('status', 1)->exists()) {
            return $this->errorResponse("El usuario ha sido dado de baja", 404);
        }

        // Verificar la clave
        $user = CobranzaAlta::where("dni", $request->login)->first();
        if (Hash::check($request->password, $user->pswd)) {
            // Traer los datos de socio y algunos campos específicos del usuario
            $data = Socio::leftJoin('usuario', 'usuario.idsocio', '=', 'socio.idsocio')
                ->where('socio.idsocio', $user->idsocio)
                ->select('socio.*', 'usuario.escobranzaaplic', 'usuario.esreportegerencial', 'usuario.nombre', 'usuario.nombrelargo')
                ->first();

            return $this->successResponse($data);
        } else {
            return $this->errorResponse("Credenciales no válidas", 404);
        }
    }
}
