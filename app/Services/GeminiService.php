<?php

namespace App\Services;

use Gemini\Laravel\Gemini;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private $client;
    
    public function __construct()
    {
        $this->client = Gemini::client(config('services.gemini.api_key'));
    }
    
    public function chat(string $mensaje, string $sessionId): array
    {
        try {
            $prompt = $this->buildPrompt($mensaje);
            
            $response = $this->client->geminiPro()->generateContent($prompt);
            $respuesta = $response->text();
            
            return [
                'respuesta' => $respuesta,
                'accion' => $this->extractAction($respuesta)
            ];
            
        } catch (\Exception $e) {
            Log::error('Gemini API Error: ' . $e->getMessage());
            return [
                'respuesta' => 'Lo siento, tuve un problema. ¿Podrías repetir?',
                'accion' => null
            ];
        }
    }
    
    private function buildPrompt(string $mensaje): string
    {
        return <<<PROMPT
Eres un asistente virtual para gestión de turnos médicos. Tu trabajo es:

1. SALUDAR amablemente
2. PREGUNTAR qué necesita (agendar turno)
3. RECOPILAR paso a paso:
   - Nombre completo
   - Email (validar formato)
   - Fecha y hora (formato: DD/MM/YYYY HH:MM)
   - Motivo de la consulta
4. CONFIRMAR todos los datos
5. INFORMAR que recibirá email de confirmación

REGLAS:
- Pregunta UNA cosa a la vez
- Valida fechas (no pasadas)
- Valida formato email
- Confirma TODO antes de guardar

Cuando tengas TODOS los datos confirmados, responde:
[CREAR_TURNO]
NOMBRE: [nombre]
EMAIL: [email]
HORARIO: [YYYY-MM-DD HH:MM:SS]
DESCRIPCION: [motivo]
[/CREAR_TURNO]

Usuario dice: {$mensaje}
PROMPT;
    }
    
    private function extractAction(string $respuesta): ?array
    {
        if (preg_match('/\[CREAR_TURNO\](.*?)\[\/CREAR_TURNO\]/s', $respuesta, $matches)) {
            $datos = [];
            preg_match_all('/(\w+):\s*(.+)$/m', $matches[1], $lines, PREG_SET_ORDER);
            
            foreach ($lines as $line) {
                $datos[strtolower($line[1])] = trim($line[2]);
            }
            
            return ['tipo' => 'crear_turno', 'datos' => $datos];
        }
        
        return null;
    }
}