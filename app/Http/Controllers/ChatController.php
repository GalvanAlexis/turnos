<?php

namespace App\Http\Controllers;

use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index()
    {
        return view('chat');
    }

    public function send(Request $request)
    {
        $sessionId = $request->session()->get('chat_session', Str::uuid());
        $request->session()->put('chat_session', $sessionId);
        
        $mensaje = $request->input('msg');
        
        $gemini = new GeminiService();
        $resultado = $gemini->chat($mensaje, $sessionId);
        
        return response()->json([
            'texto' => $resultado['respuesta']
        ]);
    }
}