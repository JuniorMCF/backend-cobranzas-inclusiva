<?php

namespace App\Http\Middleware;

use App\Models\Socio;
use App\Models\CobranzaAlta;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class CoopMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        list($login, $password) = explode(':', base64_decode(substr($authHeader, 6)));

        $socio = CobranzaAlta::where("dni",$login)->first();

        if($socio){
            if (Hash::check($password, $socio->pswd)) {
                return $next($request);
            }
        }


        return response()->json(['error' => 'Unauthorized'], 401);
    }
}
