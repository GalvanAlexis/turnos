# Agents.md - Sistema de Gestión de Turnos con IA

## 🎯 Objetivo del Proyecto

Sistema web de gestión de turnos mediante chat conversacional con IA (Google Gemini), integrado con Google Calendar y Google Sheets para registro automático de citas.

---

## 🎯 Controlador de Confirmación/Cancelación

### app/Http/Controllers/TurnoController.php

```php
<?php

namespace App\Http\Controllers;

use App\Models\Turno;
use App\Services\GoogleCalendarService;
use App\Services\GoogleSheetsService;
use App\Services\EmailService;
use Illuminate\Http\Request;

class TurnoController extends Controller
{
    public function __construct(
        private GoogleCalendarService $calendar,
        private GoogleSheetsService $sheets,
        private EmailService $email
    ) {}
    
    public function confirmar(string $token)
    {
        $turno = Turno::where('token_confirmacion', $token)->firstOrFail();
        
        if ($turno->estado === 'confirmado') {
            return view('turno.ya-confirmado', compact('turno'));
        }
        
        $turno->update([
            'estado' => 'confirmado',
            'confirmado_at' => now()
        ]);
        
        // Actualizar en Google Sheets
        if ($turno->google_sheets_row) {
            $this->sheets->actualizarEstado($turno->google_sheets_row, 'confirmado');
        }
        
        return view('turno.confirmado', compact('turno'));
    }
    
    public function cancelarView(string $token)
    {
        $turno = Turno::where('token_confirmacion', $token)->firstOrFail();
        
        if ($turno->estado === 'cancelado') {
            return view('turno.ya-cancelado', compact('turno'));
        }
        
        return view('turno.cancelar', compact('turno'));
    }
    
    public function cancelar(Request $request, string $token)
    {
        $turno = Turno::where('token_confirmacion', $token)->firstOrFail();
        
        $request->validate([
            'razon' => 'required|string|max:500'
        ]);
        
        $turno->update([
            'estado' => 'cancelado',
            'razon_cancelacion' => $request->razon
        ]);
        
        // Cancelar en Google Calendar
        if ($turno->google_calendar_id) {
            $this->calendar->cancelarEvento($turno->google_calendar_id);
        }
        
        // Actualizar Google Sheets
        if ($turno->google_sheets_row) {
            $this->sheets->actualizarEstado(
                $turno->google_sheets_row,
                'cancelado',
                $request->razon
            );
        }
        
        // Enviar confirmación de cancelación
        $this->email->enviarCancelacionConfirmada($turno);
        
        return view('turno.cancelacion-exitosa', compact('turno'));
    }
}
```

---

## 🌐 Rutas (routes/web.php)

```php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\TurnoController;

// Autenticación con Google
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Home (requiere autenticación)
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return view('chat');
    })->name('home');
    
    Route::post('/api/chat/mensaje', [ChatController::class, 'mensaje'])->name('chat.mensaje');
});

// Confirmación y cancelación de turnos (público, con token)
Route::get('/turno/confirmar/{token}', [TurnoController::class, 'confirmar'])->name('turno.confirmar');
Route::get('/turno/cancelar/{token}', [TurnoController::class, 'cancelarView'])->name('turno.cancelar-view');
Route::post('/turno/cancelar/{token}', [TurnoController::class, 'cancelar'])->name('turno.cancelar');
```

---

## 🔐 Autenticación con Google OAuth

### app/Http/Controllers/AuthController.php

```php
<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')
            ->scopes(['https://www.googleapis.com/auth/calendar', 'https://www.googleapis.com/auth/gmail.send'])
            ->redirect();
    }
    
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            $usuario = Usuario::updateOrCreate(
                ['google_id' => $googleUser->id],
                [
                    'nombre' => $googleUser->name,
                    'email' => $googleUser->email,
                    'avatar' => $googleUser->avatar,
                    'google_token' => $googleUser->token,
                    'google_refresh_token' => $googleUser->refreshToken ?? null,
                ]
            );
            
            Auth::login($usuario);
            
            return redirect('/')->with('success', '¡Bienvenido!');
            
        } catch (\Exception $e) {
            return redirect('/')->with('error', 'Error al iniciar sesión con Google');
        }
    }
    
    public function logout()
    {
        Auth::logout();
        return redirect('/')->with('success', 'Sesión cerrada');
    }
}
```

### config/services.php

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
],
```

---

## 📧 Servicio de Email (Gmail API)

### app/Services/EmailService.php

```php
<?php

namespace App\Services;

use Google\Client;
use Google\Service\Gmail;
use App\Models\Turno;
use Illuminate\Support\Facades\Log;

class EmailService
{
    private $client;
    private $service;
    
    public function __construct()
    {
        $this->client = new Client();
        $this->client->setAuthConfig(storage_path('app/google-credentials.json'));
        $this->client->addScope(Gmail::GMAIL_SEND);
        $this->service = new Gmail($this->client);
    }
    
    public function enviarConfirmacionTurno(Turno $turno): bool
    {
        try {
            $confirmUrl = route('turno.confirmar', ['token' => $turno->token_confirmacion]);
            $cancelUrl = route('turno.cancelar-view', ['token' => $turno->token_confirmacion]);
            
            $subject = "Confirmación de Turno - " . $turno->horario->format('d/m/Y H:i');
            
            $body = $this->getEmailTemplate($turno, $confirmUrl, $cancelUrl);
            
            $message = $this->createMessage($turno->email, $subject, $body);
            
            $this->service->users_messages->send('me', $message);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Error enviando email: ' . $e->getMessage());
            return false;
        }
    }
    
    private function createMessage(string $to, string $subject, string $body): Gmail\Message
    {
        $rawMessage = "From: Sistema de Turnos <me>\r\n";
        $rawMessage .= "To: {$to}\r\n";
        $rawMessage .= "Subject: =?utf-8?B?" . base64_encode($subject) . "?=\r\n";
        $rawMessage .= "MIME-Version: 1.0\r\n";
        $rawMessage .= "Content-Type: text/html; charset=utf-8\r\n";
        $rawMessage .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $rawMessage .= base64_encode($body);
        
        $message = new Gmail\Message();
        $message->setRaw(base64_encode($rawMessage));
        
        return $message;
    }
    
    private function getEmailTemplate(Turno $turno, string $confirmUrl, string $cancelUrl): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2563eb; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
                .info-box { background: white; padding: 15px; margin: 20px 0; border-left: 4px solid #2563eb; }
                .button { display: inline-block; padding: 12px 24px; margin: 10px 5px; text-decoration: none; border-radius: 6px; font-weight: bold; }
                .btn-confirm { background: #10b981; color: white; }
                .btn-cancel { background: #ef4444; color: white; }
                .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🗓️ Confirmación de Turno</h1>
                </div>
                <div class="content">
                    <p>Hola <strong>{$turno->nombre}</strong>,</p>
                    <p>Tu turno ha sido registrado exitosamente. Por favor, confirma tu asistencia haciendo clic en el botón de abajo.</p>
                    
                    <div class="info-box">
                        <h3>📋 Detalles del Turno:</h3>
                        <p><strong>Fecha y Hora:</strong> {$turno->horario->format('d/m/Y H:i')}</p>
                        <p><strong>Descripción:</strong> {$turno->descripcion}</p>
                        <p><strong>Estado:</strong> {$turno->estado}</p>
                    </div>
                    
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="{$confirmUrl}" class="button btn-confirm">✅ Confirmar Turno</a>
                        <br>
                        <a href="{$cancelUrl}" class="button btn-cancel">❌ Cancelar Turno</a>
                    </div>
                    
                    <p style="color: #6b7280; font-size: 14px;">
                        Si no puedes hacer clic en los botones, copia y pega este enlace en tu navegador:<br>
                        <strong>Confirmar:</strong> {$confirmUrl}<br>
                        <strong>Cancelar:</strong> {$cancelUrl}
                    </p>
                </div>
                <div class="footer">
                    <p>Este es un mensaje automático. Por favor, no responder a este email.</p>
                    <p>&copy; 2025 Sistema de Turnos. Todos los derechos reservados.</p>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }
    
    public function enviarCancelacionConfirmada(Turno $turno): bool
    {
        try {
            $subject = "Turno Cancelado - " . $turno->horario->format('d/m/Y H:i');
            
            $body = <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #ef4444; color: white; padding: 20px; text-align: center; }
                    .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>❌ Turno Cancelado</h1>
                    </div>
                    <div class="content">
                        <p>Hola <strong>{$turno->nombre}</strong>,</p>
                        <p>Tu turno ha sido cancelado exitosamente.</p>
                        <p><strong>Fecha:</strong> {$turno->horario->format('d/m/Y H:i')}</p>
                        <p><strong>Motivo:</strong> {$turno->razon_cancelacion}</p>
                        <p>Si deseas agendar un nuevo turno, visita nuestro sitio web.</p>
                    </div>
                </div>
            </body>
            </html>
            HTML;
            
            $message = $this->createMessage($turno->email, $subject, $body);
            $this->service->users_messages->send('me', $message);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Error enviando cancelación: ' . $e->getMessage());
            return false;
        }
    }
}
```

---

## 🏗️ Arquitectura del Sistema

```
Usuario → Chat Web (Laravel) → Agente IA (Gemini) → Google APIs
                                      ↓
                              [Calendar + Sheets]
```

### Componentes Principales

1. **Frontend**: Chat interface (Livewire o Blade + Alpine.js)
2. **Backend**: Laravel 10+ con PHP 8.2
3. **IA**: Google Gemini API (tier gratuito: 1,500 req/día)
4. **Base de datos**: MySQL 8.0
5. **Integraciones**: Google Calendar API + Google Sheets API

---

## 📊 Modelo de Datos

### Tabla: `usuarios`

```sql
CREATE TABLE usuarios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    google_id VARCHAR(255) UNIQUE NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    avatar TEXT NULL,
    google_token TEXT NULL,
    google_refresh_token TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Tabla: `turnos`

```sql
CREATE TABLE turnos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    horario DATETIME NOT NULL,
    descripcion TEXT,
    estado ENUM('pendiente', 'confirmado', 'cancelado') DEFAULT 'pendiente',
    razon_cancelacion TEXT NULL,
    token_confirmacion VARCHAR(64) UNIQUE NOT NULL,
    confirmado_at TIMESTAMP NULL,
    google_calendar_id VARCHAR(255) NULL,
    google_sheets_row INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);
```

### Tabla: `conversaciones`

```sql
CREATE TABLE conversaciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(255) NOT NULL,
    rol ENUM('user', 'assistant') NOT NULL,
    mensaje TEXT NOT NULL,
    turno_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (turno_id) REFERENCES turnos(id) ON DELETE SET NULL
);
```

---

## 🤖 Configuración del Agente IA

### 1. Instalación de Dependencias

```bash
composer require google/generative-ai-php
composer require google/apiclient:"^2.0"
composer require laravel/socialite
```

### 2. Variables de Entorno (.env)

```env
# Gemini API
GEMINI_API_KEY=your_gemini_api_key_here

# Google APIs (OAuth + APIs)
GOOGLE_CLIENT_ID=your_client_id
GOOGLE_CLIENT_SECRET=your_client_secret
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback

# Google Calendar
GOOGLE_CALENDAR_ID=primary

# Google Sheets
GOOGLE_SPREADSHEET_ID=your_spreadsheet_id_here

# URLs de la aplicación
APP_URL=http://localhost:8000
APP_FRONTEND_URL=http://localhost:8000
```

### 3. Estructura de Archivos Laravel

```
app/
├── Services/
│   ├── GeminiService.php
│   ├── GoogleCalendarService.php
│   ├── GoogleSheetsService.php
│   └── EmailService.php
├── Http/
│   ├── Controllers/
│   │   ├── ChatController.php
│   │   ├── TurnoController.php
│   │   └── AuthController.php
│   └── Livewire/
│       └── ChatComponent.php
└── Models/
    ├── Turno.php
    ├── Usuario.php
    └── Conversacion.php
```

---

## 🎭 Sistema de Prompts

### Prompt Principal del Agente

```php
// app/Services/GeminiService.php

private function getSystemPrompt(): string
{
    return <<<PROMPT
Eres un asistente virtual para la gestión de turnos. Tu trabajo es:

1. SALUDAR al usuario de forma amigable
2. PREGUNTAR qué necesita (agendar turno, consultar, cancelar)
3. RECOPILAR información necesaria:
   - Nombre completo
   - Fecha y hora deseada (formato: DD/MM/YYYY HH:MM)
   - Motivo o descripción de la cita
4. CONFIRMAR todos los datos antes de registrar
5. INFORMAR sobre el registro exitoso

REGLAS IMPORTANTES:
- Sé conversacional y amable
- Pregunta UNA cosa a la vez
- Valida fechas (no permitir fechas pasadas)
- Confirma antes de guardar
- Si cancelan, pregunta el motivo

FORMATO DE RESPUESTA cuando tengas todos los datos:
[ACCION:CREAR_TURNO]
NOMBRE: [nombre]
HORARIO: [YYYY-MM-DD HH:MM:SS]
DESCRIPCION: [descripción]
[/ACCION]

Para cancelaciones:
[ACCION:CANCELAR_TURNO]
ID: [id del turno]
RAZON: [motivo]
[/ACCION]

Historial de conversación:
{historial}

Usuario actual: {mensaje_usuario}
PROMPT;
}
```

### Prompt para Extracción de Datos

```php
private function getExtractionPrompt(): string
{
    return <<<PROMPT
Analiza el siguiente mensaje y extrae SOLO si está completa y confirmada:

Mensaje: {mensaje}

Responde en JSON:
{
    "accion": "crear_turno|cancelar_turno|consultar|null",
    "datos": {
        "nombre": "string o null",
        "horario": "YYYY-MM-DD HH:MM:SS o null",
        "descripcion": "string o null"
    },
    "confirmado": boolean
}

Solo marca confirmado=true si el usuario explícitamente confirmó los datos.
PROMPT;
}
```

---

## 💻 Implementación del Servicio Gemini

### app/Services/GeminiService.php

```php
<?php

namespace App\Services;

use Google\GenerativeAI\Client;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private $client;
    
    public function __construct()
    {
        $this->client = new Client(config('services.gemini.api_key'));
    }
    
    public function chat(string $mensaje, string $sessionId): array
    {
        // Obtener historial
        $historial = $this->getHistorial($sessionId);
        
        // Construir prompt
        $prompt = $this->buildPrompt($mensaje, $historial);
        
        try {
            // Llamada a Gemini
            $response = $this->client->models()->generateContent([
                'model' => 'gemini-2.0-flash-exp',
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 500,
                ]
            ]);
            
            $respuesta = $response->text();
            
            // Guardar conversación
            $this->saveMessage($sessionId, 'user', $mensaje);
            $this->saveMessage($sessionId, 'assistant', $respuesta);
            
            // Procesar acciones
            $accion = $this->extractAction($respuesta);
            
            return [
                'respuesta' => $this->cleanResponse($respuesta),
                'accion' => $accion
            ];
            
        } catch (\Exception $e) {
            Log::error('Gemini API Error: ' . $e->getMessage());
            return [
                'respuesta' => 'Lo siento, tuve un problema. ¿Podrías repetir?',
                'accion' => null
            ];
        }
    }
    
    private function extractAction(string $respuesta): ?array
    {
        if (preg_match('/\[ACCION:CREAR_TURNO\](.*?)\[\/ACCION\]/s', $respuesta, $matches)) {
            $datos = $this->parseActionData($matches[1]);
            return ['tipo' => 'crear_turno', 'datos' => $datos];
        }
        
        if (preg_match('/\[ACCION:CANCELAR_TURNO\](.*?)\[\/ACCION\]/s', $respuesta, $matches)) {
            $datos = $this->parseActionData($matches[1]);
            return ['tipo' => 'cancelar_turno', 'datos' => $datos];
        }
        
        return null;
    }
    
    private function parseActionData(string $data): array
    {
        $resultado = [];
        preg_match_all('/(\w+):\s*(.+)$/m', $data, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $key = strtolower($match[1]);
            $resultado[$key] = trim($match[2]);
        }
        
        return $resultado;
    }
    
    private function cleanResponse(string $respuesta): string
    {
        // Remover tags de acción de la respuesta visible
        return preg_replace('/\[ACCION:.*?\[\/ACCION\]/s', '', $respuesta);
    }
}
```

---

## 📅 Integración Google Calendar

### app/Services/GoogleCalendarService.php

```php
<?php

namespace App\Services;

use Google\Client;
use Google\Service\Calendar;
use Carbon\Carbon;

class GoogleCalendarService
{
    private $client;
    private $service;
    
    public function __construct()
    {
        $this->client = new Client();
        $this->client->setAuthConfig(storage_path('app/google-credentials.json'));
        $this->client->addScope(Calendar::CALENDAR);
        $this->service = new Calendar($this->client);
    }
    
    public function crearEvento(array $datos): string
    {
        $evento = new Calendar\Event([
            'summary' => "Turno: {$datos['nombre']}",
            'description' => $datos['descripcion'],
            'start' => [
                'dateTime' => Carbon::parse($datos['horario'])->toRfc3339String(),
                'timeZone' => 'America/Argentina/Buenos_Aires',
            ],
            'end' => [
                'dateTime' => Carbon::parse($datos['horario'])->addHour()->toRfc3339String(),
                'timeZone' => 'America/Argentina/Buenos_Aires',
            ],
            'reminders' => [
                'useDefault' => false,
                'overrides' => [
                    ['method' => 'email', 'minutes' => 24 * 60],
                    ['method' => 'popup', 'minutes' => 30],
                ],
            ],
        ]);
        
        $calendarId = config('services.google.calendar_id');
        $evento = $this->service->events->insert($calendarId, $evento);
        
        return $evento->getId();
    }
    
    public function cancelarEvento(string $eventoId): bool
    {
        try {
            $calendarId = config('services.google.calendar_id');
            $this->service->events->delete($calendarId, $eventoId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
```

---

## 📊 Integración Google Sheets

### app/Services/GoogleSheetsService.php

```php
<?php

namespace App\Services;

use Google\Client;
use Google\Service\Sheets;

class GoogleSheetsService
{
    private $client;
    private $service;
    private $spreadsheetId;
    
    public function __construct()
    {
        $this->client = new Client();
        $this->client->setAuthConfig(storage_path('app/google-credentials.json'));
        $this->client->addScope(Sheets::SPREADSHEETS);
        $this->service = new Sheets($this->client);
        $this->spreadsheetId = config('services.google.spreadsheet_id');
    }
    
    public function agregarTurno(array $datos): int
    {
        $values = [
            [
                date('Y-m-d H:i:s'),
                $datos['nombre'],
                $datos['horario'],
                $datos['descripcion'],
                'activo',
                ''
            ]
        ];
        
        $body = new Sheets\ValueRange(['values' => $values]);
        $params = ['valueInputOption' => 'RAW'];
        
        $result = $this->service->spreadsheets_values->append(
            $this->spreadsheetId,
            'Turnos!A:F',
            $body,
            $params
        );
        
        // Retorna el número de fila agregada
        return $result->getUpdates()->getUpdatedRows();
    }
    
    public function actualizarEstado(int $fila, string $estado, string $razon = ''): void
    {
        $range = "Turnos!E{$fila}:F{$fila}";
        $values = [[$estado, $razon]];
        
        $body = new Sheets\ValueRange(['values' => $values]);
        $params = ['valueInputOption' => 'RAW'];
        
        $this->service->spreadsheets_values->update(
            $this->spreadsheetId,
            $range,
            $body,
            $params
        );
    }
}
```

---

## 🎮 Controlador Principal

### app/Http/Controllers/ChatController.php

```php
<?php

namespace App\Http\Controllers;

use App\Services\GeminiService;
use App\Services\GoogleCalendarService;
use App\Services\GoogleSheetsService;
use App\Models\Turno;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(
        private GeminiService $gemini,
        private GoogleCalendarService $calendar,
        private GoogleSheetsService $sheets,
        private EmailService $email
    ) {}
    
    public function mensaje(Request $request)
    {
        $sessionId = $request->session()->get('chat_session', Str::uuid());
        $request->session()->put('chat_session', $sessionId);
        
        $mensaje = $request->input('mensaje');
        
        // Procesar con IA
        $resultado = $this->gemini->chat($mensaje, $sessionId);
        
        // Ejecutar acción si existe
        if ($resultado['accion']) {
            $this->ejecutarAccion($resultado['accion']);
        }
        
        return response()->json([
            'respuesta' => $resultado['respuesta'],
            'success' => true
        ]);
    }
    
    private function ejecutarAccion(array $accion): void
    {
        if ($accion['tipo'] === 'crear_turno') {
            $datos = $accion['datos'];
            
            // Generar token único para confirmación
            $token = bin2hex(random_bytes(32));
            
            // Crear en BD
            $turno = Turno::create([
                'usuario_id' => auth()->id(),
                'nombre' => $datos['nombre'],
                'email' => auth()->user()->email,
                'horario' => $datos['horario'],
                'descripcion' => $datos['descripcion'],
                'estado' => 'pendiente',
                'token_confirmacion' => $token
            ]);
            
            // Crear en Google Calendar
            $calendarId = $this->calendar->crearEvento($datos);
            $turno->update(['google_calendar_id' => $calendarId]);
            
            // Agregar a Google Sheets
            $fila = $this->sheets->agregarTurno($datos);
            $turno->update(['google_sheets_row' => $fila]);
            
            // Enviar email de confirmación
            $this->email->enviarConfirmacionTurno($turno);
        }
        
        if ($accion['tipo'] === 'cancelar_turno') {
            $turno = Turno::find($accion['datos']['id']);
            
            if ($turno && $turno->usuario_id === auth()->id()) {
                $turno->update([
                    'estado' => 'cancelado',
                    'razon_cancelacion' => $accion['datos']['razon']
                ]);
                
                // Cancelar en Google Calendar
                if ($turno->google_calendar_id) {
                    $this->calendar->cancelarEvento($turno->google_calendar_id);
                }
                
                // Actualizar Google Sheets
                if ($turno->google_sheets_row) {
                    $this->sheets->actualizarEstado(
                        $turno->google_sheets_row,
                        'cancelado',
                        $accion['datos']['razon']
                    );
                }
                
                // Enviar email de confirmación de cancelación
                $this->email->enviarCancelacionConfirmada($turno);
            }
        }
    }
}
```

---

## 🎨 Vista del Chat

### resources/views/chat.blade.php

```html
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sistema de Turnos</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-100">
    <div x-data="chatApp()" class="max-w-2xl mx-auto p-4 h-screen flex flex-col">
        <!-- Header con usuario -->
        <div class="bg-white rounded-t-lg shadow-lg p-4 flex justify-between items-center">
            <div>
                <h1 class="text-xl font-bold text-gray-800">🗓️ Sistema de Turnos</h1>
                <p class="text-sm text-gray-600">Hola, {{ auth()->user()->nombre }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm">
                    Cerrar Sesión
                </button>
            </form>
        </div>
        
        <div class="bg-white shadow-lg flex-1 flex flex-col">
            <!-- Mensajes -->
            <div class="flex-1 overflow-y-auto p-4 space-y-4" x-ref="mensajes">
                <template x-for="msg in mensajes" :key="msg.id">
                    <div :class="msg.rol === 'user' ? 'text-right' : 'text-left'">
                        <div :class="msg.rol === 'user' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-800'" 
                             class="inline-block px-4 py-2 rounded-lg max-w-xs">
                            <p x-text="msg.texto"></p>
                        </div>
                        <p class="text-xs text-gray-500 mt-1" x-text="msg.hora"></p>
                    </div>
                </template>
                
                <div x-show="cargando" class="text-left">
                    <div class="inline-block bg-gray-200 px-4 py-2 rounded-lg">
                        <p class="text-gray-600">Escribiendo...</p>
                    </div>
                </div>
            </div>
            
            <!-- Input -->
            <div class="p-4 border-t">
                <form @submit.prevent="enviarMensaje" class="flex gap-2">
                    <input 
                        type="text" 
                        x-model="nuevoMensaje"
                        placeholder="Escribe tu mensaje..."
                        class="flex-1 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :disabled="cargando"
                    >
                    <button 
                        type="submit"
                        class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50"
                        :disabled="cargando || !nuevoMensaje.trim()"
                    >
                        Enviar
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function chatApp() {
            return {
                mensajes: [],
                nuevoMensaje: '',
                cargando: false,
                
                init() {
                    this.mensajes.push({
                        id: Date.now(),
                        rol: 'assistant',
                        texto: '¡Hola {{ auth()->user()->nombre }}! Soy tu asistente para gestionar turnos. ¿En qué puedo ayudarte hoy?',
                        hora: this.getHora()
                    });
                },
                
                getHora() {
                    return new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
                },
                
                async enviarMensaje() {
                    if (!this.nuevoMensaje.trim()) return;
                    
                    const mensaje = this.nuevoMensaje;
                    this.nuevoMensaje = '';
                    
                    this.mensajes.push({
                        id: Date.now(),
                        rol: 'user',
                        texto: mensaje,
                        hora: this.getHora()
                    });
                    
                    this.cargando = true;
                    this.scrollToBottom();
                    
                    try {
                        const response = await fetch('{{ route("chat.mensaje") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({ mensaje })
                        });
                        
                        const data = await response.json();
                        
                        this.mensajes.push({
                            id: Date.now(),
                            rol: 'assistant',
                            texto: data.respuesta,
                            hora: this.getHora()
                        });
                        
                    } catch (error) {
                        this.mensajes.push({
                            id: Date.now(),
                            rol: 'assistant',
                            texto: 'Lo siento, hubo un error. Intenta nuevamente.',
                            hora: this.getHora()
                        });
                    }
                    
                    this.cargando = false;
                    this.scrollToBottom();
                },
                
                scrollToBottom() {
                    this.$nextTick(() => {
                        this.$refs.mensajes.scrollTop = this.$refs.mensajes.scrollHeight;
                    });
                }
            }
        }
    </script>
</body>
</html>
```

---

## 🔑 Vista de Login

### resources/views/login.blade.php

```html
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Turnos</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-500 to-purple-600 min-h-screen flex items-center justify-center">
    <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-md w-full">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-800 mb-2">🗓️</h1>
            <h2 class="text-2xl font-bold text-gray-800">Sistema de Turnos</h2>
            <p class="text-gray-600 mt-2">Gestiona tus citas de forma inteligente</p>
        </div>
        
        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                {{ session('error') }}
            </div>
        @endif
        
        <a href="{{ route('auth.google') }}" 
           class="flex items-center justify-center gap-3 w-full bg-white border-2 border-gray-300 hover:border-gray-400 text-gray-700 font-semibold py-3 px-6 rounded-lg transition duration-200 hover:shadow-lg">
            <svg class="w-6 h-6" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Continuar con Google
        </a>
        
        <p class="text-center text-sm text-gray-500 mt-6">
            Al continuar, aceptas nuestros términos y condiciones
        </p>
    </div>
</body>
</html>
```

---

## 📧 Vistas de Confirmación/Cancelación

### resources/views/turno/confirmar.blade.php

```html
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Turno Confirmado</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white rounded-lg shadow-lg p-8 max-w-md text-center">
        <div class="text-6xl mb-4">✅</div>
        <h1 class="text-2xl font-bold text-green-600 mb-4">¡Turno Confirmado!</h1>
        <div class="text-left bg-gray-50 p-4 rounded mb-4">
            <p><strong>Nombre:</strong> {{ $turno->nombre }}</p>
            <p><strong>Fecha:</strong> {{ $turno->horario->format('d/m/Y H:i') }}</p>
            <p><strong>Descripción:</strong> {{ $turno->descripcion }}</p>
        </div>
        <p class="text-gray-600">Te esperamos en la fecha indicada.</p>
    </div>
</body>
</html>
```

### resources/views/turno/cancelar.blade.php

```html
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cancelar Turno</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="bg-white rounded-lg shadow-lg p-8 max-w-md">
        <div class="text-6xl mb-4 text-center">❌</div>
        <h1 class="text-2xl font-bold text-red-600 mb-4 text-center">Cancelar Turno</h1>
        
        <div class="bg-gray-50 p-4 rounded mb-6">
            <p><strong>Fecha:</strong> {{ $turno->horario->format('d/m/Y H:i') }}</p>
            <p><strong>Descripción:</strong> {{ $turno->descripcion }}</p>
        </div>
        
        <form method="POST" action="{{ route('turno.cancelar', $turno->token_confirmacion) }}">
            @csrf
            <label class="block mb-2 font-semibold">Motivo de cancelación:</label>
            <textarea 
                name="razon" 
                required
                rows="4"
                class="w-full border rounded p-2 mb-4 focus:ring-2 focus:ring-red-500"
                placeholder="Por favor, indica el motivo de la cancelación..."></textarea>
            
            @error('razon')
                <p class="text-red-500 text-sm mb-2">{{ $message }}</p>
            @enderror
            
            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded">
                Confirmar Cancelación
            </button>
        </form>
    </div>
</body>
</html>
```

---

### resources/views/chat.blade.php

```html
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Turnos</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-100">
    <div x-data="chatApp()" class="max-w-2xl mx-auto p-4 h-screen flex flex-col">
        <div class="bg-white rounded-lg shadow-lg flex-1 flex flex-col">
            <!-- Header -->
            <div class="bg-blue-600 text-white p-4 rounded-t-lg">
                <h1 class="text-xl font-bold">🗓️ Sistema de Turnos</h1>
                <p class="text-sm opacity-90">Asistente virtual</p>
            </div>
            
            <!-- Mensajes -->
            <div class="flex-1 overflow-y-auto p-4 space-y-4" x-ref="mensajes">
                <template x-for="msg in mensajes" :key="msg.id">
                    <div :class="msg.rol === 'user' ? 'text-right' : 'text-left'">
                        <div :class="msg.rol === 'user' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-800'" 
                             class="inline-block px-4 py-2 rounded-lg max-w-xs">
                            <p x-text="msg.texto"></p>
                        </div>
                    </div>
                </template>
                
                <div x-show="cargando" class="text-left">
                    <div class="inline-block bg-gray-200 px-4 py-2 rounded-lg">
                        <p class="text-gray-600">Escribiendo...</p>
                    </div>
                </div>
            </div>
            
            <!-- Input -->
            <div class="p-4 border-t">
                <form @submit.prevent="enviarMensaje" class="flex gap-2">
                    <input 
                        type="text" 
                        x-model="nuevoMensaje"
                        placeholder="Escribe tu mensaje..."
                        class="flex-1 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        :disabled="cargando"
                    >
                    <button 
                        type="submit"
                        class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50"
                        :disabled="cargando || !nuevoMensaje.trim()"
                    >
                        Enviar
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function chatApp() {
            return {
                mensajes: [],
                nuevoMensaje: '',
                cargando: false,
                
                init() {
                    this.mensajes.push({
                        id: Date.now(),
                        rol: 'assistant',
                        texto: '¡Hola! Soy tu asistente para gestionar turnos. ¿En qué puedo ayudarte hoy?'
                    });
                },
                
                async enviarMensaje() {
                    if (!this.nuevoMensaje.trim()) return;
                    
                    const mensaje = this.nuevoMensaje;
                    this.nuevoMensaje = '';
                    
                    this.mensajes.push({
                        id: Date.now(),
                        rol: 'user',
                        texto: mensaje
                    });
                    
                    this.cargando = true;
                    this.scrollToBottom();
                    
                    try {
                        const response = await fetch('/api/chat/mensaje', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({ mensaje })
                        });
                        
                        const data = await response.json();
                        
                        this.mensajes.push({
                            id: Date.now(),
                            rol: 'assistant',
                            texto: data.respuesta
                        });
                        
                    } catch (error) {
                        this.mensajes.push({
                            id: Date.now(),
                            rol: 'assistant',
                            texto: 'Lo siento, hubo un error. Intenta nuevamente.'
                        });
                    }
                    
                    this.cargando = false;
                    this.scrollToBottom();
                },
                
                scrollToBottom() {
                    this.$nextTick(() => {
                        this.$refs.mensajes.scrollTop = this.$refs.mensajes.scrollHeight;
                    });
                }
            }
        }
    </script>
</body>
</html>
```

---

## 📝 Configuración de Google Sheets

### Estructura de la Hoja "Turnos"

| Fecha Creación | Usuario | Email | Nombre | Horario | Descripción | Estado | Razón Cancelación |
|----------------|---------|-------|--------|---------|-------------|--------|-------------------|
| 2024-01-15 10:30 | Juan Pérez | juan@email.com | Juan Pérez | 2024-01-20 15:00 | Consulta general | confirmado | |

---

## 🚀 Comandos de Deployment

```bash
# Instalar dependencias
docker-compose run --rm app composer install
docker-compose run --rm app npm install && npm run build

# Copiar archivo de configuración
docker-compose run --rm app cp .env.example .env

# Generar key de aplicación
docker-compose run --rm app php artisan key:generate

# Ejecutar migraciones
docker-compose run --rm app php artisan migrate

# Crear enlace simbólico para storage (si es necesario)
docker-compose run --rm app php artisan storage:link

# Levantar servicios
docker-compose up -d

# Ver logs en tiempo real
docker-compose logs -f app

# Acceder al contenedor
docker-compose exec app bash
```

### Migraciones necesarias

```bash
# Crear migraciones
docker-compose run --rm app php artisan make:migration create_usuarios_table
docker-compose run --rm app php artisan make:migration create_turnos_table
docker-compose run --rm app php artisan make:migration create_conversaciones_table

# Crear modelos
docker-compose run --rm app php artisan make:model Usuario
docker-compose run --rm app php artisan make:model Turno
docker-compose run --rm app php artisan make:model Conversacion
```

---

## 💰 Costos (Todo Gratis)

| Servicio | Límite Gratuito | Uso Esperado |
|----------|-----------------|--------------|
| Google Gemini | 1,500 req/día | ~100-300/día |
| Google Calendar API | 1,000,000 req/día | ~50/día |
| Google Sheets API | 300 req/min | ~50/día |
| MySQL (Docker) | Ilimitado | Local |

**Total mensual: $0 USD** ✅

---

## 🔧 Próximos Pasos

### 1. Configuración Inicial
1. ✅ Crear proyecto en Google Cloud Console
2. ✅ Habilitar APIs necesarias:
   - Google Calendar API
   - Google Sheets API  
   - Gmail API
3. ✅ Crear credenciales OAuth 2.0
4. ✅ Descargar `google-credentials.json` y colocar en `storage/app/`
5. ✅ Crear Google Spreadsheet y obtener ID (desde la URL)
6. ✅ Configurar todas las variables en `.env`

### 2. Implementación del Código
1. ✅ Crear migraciones y ejecutarlas
2. ✅ Implementar servicios (Gemini, Calendar, Sheets, Email)
3. ✅ Crear controladores (Auth, Chat, Turno)
4. ✅ Configurar rutas en `routes/web.php`
5. ✅ Crear vistas (login, chat, confirmación, cancelación)
6. ✅ Configurar middleware de autenticación

### 3. Testing
1. ✅ Testear login con Google
2. ✅ Testear conversación con IA
3. ✅ Testear creación de turno (BD + Calendar + Sheets)
4. ✅ Testear envío de email de confirmación
5. ✅ Testear confirmación de turno por email
6. ✅ Testear cancelación de turno con motivo
7. ✅ Verificar sincronización en todas las plataformas

### 4. Optimización
1. ✅ Implementar manejo robusto de errores
2. ✅ Agregar rate limiting a endpoints
3. ✅ Implementar logs detallados
4. ✅ Agregar validaciones de datos
5. ✅ Optimizar queries a la BD
6. ✅ Implementar cache de sesiones del chat

### 5. Producción
1. ✅ Configurar dominio y SSL
2. ✅ Actualizar URLs de callback en Google Cloud
3. ✅ Configurar variables de entorno de producción
4. ✅ Implementar backups automáticos de BD
5. ✅ Configurar monitoreo de errores (Sentry, etc.)

---

## 📞 Soporte

Para más información:
- [Gemini API Docs](https://ai.google.dev/docs)
- [Google Calendar API](https://developers.google.com/calendar)
- [Google Sheets API](https://developers.google.com/sheets)
- [Laravel Docs](https://laravel.com/docs)