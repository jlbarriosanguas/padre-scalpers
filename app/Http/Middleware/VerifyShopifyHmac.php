<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

class VerifyShopifyHmac
{
    /**
	 * @emelero - 16.01.2019
     * Verficación de HMAC para OAuth. Sólo muestra la app en Shopify Admin.
	 * Bloquea cualquier petición con HMAC inválido
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
		$signature = $request->except(['hmac', 'signature', 'page']); // page -> AJAX pagination fix
		ksort($signature);

		foreach ($signature as $k => $val) {
			$k = str_replace('%', '%25', $k);
			$k = str_replace('&', '%26', $k);
			$k = str_replace('=', '%3D', $k);
			$val = str_replace('%', '%25', $val);
			$val = str_replace('&', '%26', $val);
			$signature[$k] = $val;
		}


		$pass = hash_hmac('sha256', http_build_query($signature), env('PADRE_API_SECRET'));

		if ($request->input('hmac') !== $pass) {
			exit(json_encode(array("errors" => "Unauthorized access")));
		}

        return $next($request);
    }
}
