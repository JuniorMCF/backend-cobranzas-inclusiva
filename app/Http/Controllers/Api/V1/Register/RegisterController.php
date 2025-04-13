<?php

namespace App\Http\Controllers\Api\V1\Register;

use App\Http\Controllers\ApiController;

use App\Mail\Registro;
use App\Models\CobranzaAlta;
use App\Models\Usuario;
use Illuminate\Http\Request;
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

        // Verificar si el usuario existe solo por el campo dni en la tabla Usuario
        if (!Usuario::where('dni', $request->dni)->first()) {
            return $this->errorResponse("Usuario no encontrado", 201);
        } else if (!CobranzaAlta::where("dni", $request->dni)->first()) {
            return $this->errorResponse("Aún no valida su correo electrónico", 202);
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

        // Buscar usuario por dni en la tabla Usuario
        $usuario = Usuario::where('dni', $request->dni)->first();

        if (!$usuario) {
            return $this->errorResponse("Usuario no encontrado", 201);
        } else {
            // Verificar si este correo ya está siendo usado por otro usuario
            $existingUser = CobranzaAlta::where('email', $request->email)
                ->where('idsocio', '!=', $usuario->idsocio)
                ->first();

            if ($existingUser) {
                return $this->errorResponse("El correo electrónico ya está registrado para otro usuario", 202);
            } else {
                return $this->successResponse($usuario);
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

        // Buscar en la tabla Usuario por dni
        $usuario = Usuario::where('dni', $request->dni)->first();

        if (!$usuario) {
            return $this->errorResponse("Usuario no encontrado", 201);
        } else {
            if ($usuario->idsocio == $request->idsocio && ($request->phone_number == $usuario->telmovil || $request->phone_number == $usuario->telfijo)) {
                return $this->successResponse($usuario);
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

        // Buscar en la tabla Usuario por dni
        $usuario = Usuario::where('dni', $request->dni)->first();

        if (!$usuario) {
            return $this->errorResponse("Usuario no encontrado", 201);
        } else {
            // Verificar si el usuario ya tiene registro en CobranzaAlta
            if (!CobranzaAlta::where('idsocio', $usuario->idsocio)->first()) {
                $usuario_alta = CobranzaAlta::create([
                    'idsocio' => $usuario->idsocio,
                    'nom' => $usuario->nombre,
                    'ap' => $usuario->ap,
                    'am' => $usuario->am,
                    'fec_nac' => $usuario->fnac,
                    'dni' => $request->dni,
                    'email' => $request->email,
                    'secret_question' => $request->question,
                    'secret_answer' => $request->answer,
                    'pswd' => Hash::make($request->password),
                    'status' => 1
                ]);

                // Enviar correo electrónico de bienvenida
                try {
                    Mail::to($request->email)->send(new Registro($usuario_alta));

                    // Opcionalmente, actualiza el correo del usuario en la base de datos
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error al enviar correo: ' . $e->getMessage());
                }

                return $this->successResponse($usuario_alta);
            } else {
                return $this->errorResponse("Este usuario ya se encuentra registrado", 202);
            }
        }
    }
}
