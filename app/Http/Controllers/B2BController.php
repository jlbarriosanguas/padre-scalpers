<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class B2BController extends Controller
{
	//
	
    // public function helloworld(Request $request) {
    public function helloworld() {
        Log::debug("Hello World");
		// Log::debug($request->getContent());
        // return response($request, 200);
		return response("ok", 200);
    }
}
