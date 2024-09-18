<?php

namespace App\Http\Controllers\Api\V1\Password;

use App\Http\Controllers\ApiController;
use App\Mail\NotificacionClave;
use App\Models\CobranzaAlta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class RecoveryPasswordController extends ApiController
{
    //
    public function confirmCode(Request $request){
        $validator = Validator::make($request->all(), [
            'idsocio' => 'required',
            'dni'=>'required',
        ]);
        // \Log::debug($request->all());
        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        if(! CobranzaAlta::where(function($query) use ($request){
            $query->where('idsocio', $request->idsocio)
                    ->where("dni",$request->dni);
        })->first()){

            return $this->errorResponse("Credenciales no válidas", 201); // los datos enviados no corresponden

        }else{
            return $this->successResponse(CobranzaAlta::where(function($query) use ($request){
                $query->where('idsocio', $request->idsocio)
                        ->where("dni",$request->dni);
            })->first());
        }
    }
    public function confirmData(Request $request){
        $validator = Validator::make($request->all(), [
            'idsocio' => 'required',
            'dni'=>'required',
            'answer' => 'required',
        ]);
        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        if(! CobranzaAlta::where(function($query) use ($request){
            $query->where('idsocio', $request->idsocio)
                    ->where("dni",$request->dni)
                    ->where("secret_answer",$request->answer);
        })->first()){

            return $this->errorResponse("Credenciales no válidas", 201); // los datos enviados no corresponden

        }else{
            return $this->successResponse(CobranzaAlta::where(function($query) use ($request){
                $query->where('idsocio', $request->idsocio)
                        ->where("dni",$request->dni)
                        ->where("secret_answer",$request->answer);
            })->first());
        }
    }
    public function changePassword(Request $request){
        $validator = Validator::make($request->all(), [
            'idsocio' => 'required',
            'dni'=>'required',
            'new_password' => 'required|min:6|confirmed',
        ]);
        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->all(), 422);
        }

        if(! CobranzaAlta::where(function($query) use ($request){
            $query->where('idsocio', $request->idsocio)
                    ->where("dni",$request->dni);
        })->first()){

            return $this->errorResponse("Credenciales no válidas", 201); // los datos enviados no corresponden

        }else{
            //cambiamos la clave de usuario


            CobranzaAlta::where(function($query) use ($request){
                $query->where('idsocio', $request->idsocio)
                        ->where("dni",$request->dni);
            })->first()->update([
                "pswd"=>Hash::make($request->new_password)
            ]);

            /**enviar correo con cambio de contraseña */
            $socio_alta = CobranzaAlta::where(function($query) use ($request){
                $query->where('idsocio', $request->idsocio)
                        ->where("dni",$request->dni);
            })->first();

            try {
                Mail::to($socio_alta->email)->send(new NotificacionClave($socio_alta));


            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Error al enviar correo: ' . $e->getMessage());
                $this->successResponse(true);
            }

            return $this->successResponse(true);
        }
    }
}
