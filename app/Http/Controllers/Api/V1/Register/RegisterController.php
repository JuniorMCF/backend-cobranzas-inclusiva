<?php

namespace App\Http\Controllers\Api\V1\Register;

use App\Http\Controllers\ApiController;

use App\Mail\Registro;
use App\Models\Socio;
use App\Models\CobranzaAlta;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class RegisterController extends ApiController
{
    //
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'dni' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        //verificamos que exista el usuario
        if (!Socio::where(function ($query) use ($request) {
            $query->where('dni', $request->dni)
                ->orWhere('ruc', $request->dni); // Buscar coincidencias en dni o ruc
        })->where(function ($query) {
            $query->where('idtipopersona', 2);//trabajador

        })->first()) {
            return $this->errorResponse("Usuario no encontrado", 201);
        } else if (!CobranzaAlta::where("dni", $request->dni)->first()) {
            return $this->errorResponse("Aún no válida su correo electrónico", 202);
        } else {
            return $this->successResponse(CobranzaAlta::where("dni", $request->dni)->first());
        }
    }
    public function confirmEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'dni' => 'required'
        ]);
        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        // Buscar usuario por dni o ruc en la tabla Socio
        $socio = Socio::where(function ($query) use ($request) {
            $query->where('dni', $request->dni)
                ->orWhere('ruc', $request->dni); // Buscar coincidencias en dni o ruc
        })->where(function ($query) {
            $query->where('idtipopersona', 2); // trabajador
        })->first();

        if (!$socio) {
            return $this->errorResponse("Usuario no encontrado", 201);
        } else {
            /** Verificar si este correo está siendo usado por otro usuario */
            $existingUser = CobranzaAlta::where('email', $request->email)
                ->where('idsocio', '!=', $socio->idsocio)
                ->first();

            if ($existingUser) {
                return $this->errorResponse("El correo electrónico ya está registrado para otro usuario", 202);
            } else {
                return $this->successResponse($socio);
            }
        }
    }
    public function confirmBorn(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'idsocio' => 'required',
            'dni' => 'required',
            'phone_number' => 'required',
        ]);
        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        /**buscaremos el socio por el dni,
         * luego verificamos que coincida con el id de socio y la fecha de cumpleaños enviado
         * si alguno falla devolvemos un error 201
         *
         */
        $socio = Socio::where(function ($query) use ($request) {
            $query->where('dni', $request->dni)
                ->orWhere('ruc', $request->dni); // Buscar coincidencias en dni o ruc
        })->where(function ($query) {
            $query->where('idtipopersona', 2); // trabajador
        })->first();

        // \Log::debug(print_r($socio,true));
        // \Log::debug(print_r($request->all(),true));
        if (!$socio) {
            return $this->errorResponse("Usuario no encontrado", 201);
        } else {
            if ($socio->idsocio == $request->idsocio && ($request->phone_number == $socio->telmovil || $request->phone_number == $socio->telfijo)) {
                return $this->successResponse($socio);
            } else {
                return $this->errorResponse("Credenciales no válidas", 202);
            }
        }
    }
    public function confirmPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'dni' => 'required',
            'question' => 'required',
            'answer' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6|regex:/^(?=.*[A-Z])(?=.*\d).+$/',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }
        /**procedemos a almacenar estos datos en un nuevo registro */

        $socio = Socio::where(function ($query) use ($request) {
            $query->where('dni', $request->dni)
                ->orWhere('ruc', $request->dni); // Buscar coincidencias en dni o ruc
        })->where(function ($query) {
            $query->where('idtipopersona', 2); // trabajador

        })->first();

        if (!$socio) {
            return $this->errorResponse("Usuario no encontrado", 201); // el usuario no se encuentra registrado
        } else {

            if (!CobranzaAlta::where('idsocio', $socio->idsocio)->first()) {

                $socio_alta = CobranzaAlta::create([
                    'idsocio' => $socio->idsocio,
                    'nom' => $socio->nom,
                    'ap' => $socio->ap,
                    'am' => $socio->am,
                    'fec_nac' => $socio->fnac,
                    'dni' => $request->dni,
                    'email' => $request->email,
                    'secret_question' => $request->question,
                    'secret_answer' => $request->answer,
                    'pswd' => Hash::make($request->password),
                    'status'=>1
                ]);

                /**aqui debemos enviar correo electronico de bienvenida */

                try {
                    Mail::to($request->email)->send(new Registro($socio_alta));

                    // Actualizar el correo del socio en la base de datos original dentro de una transacción
                    // DB::transaction(function () use ($socio, $request) {
                    //     $socio->email = $request->email;
                    //     $socio->save();
                    // });
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error al enviar correo: ' . $e->getMessage());
                    $this->successResponse($socio_alta);
                }

                return $this->successResponse($socio_alta);
            } else {
                return $this->errorResponse("Este usuario ya se encuentra registrado", 202);
            }
        }
    }
}
