<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sistema de Turnos Médicos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="/">🩺 Sistema de Turnos Médicos</a>
        </div>
    </nav>

    <div class="container mt-4">
        <div id="chat" class="card mb-3" style="height: 500px; overflow-y: auto;">
            <div class="card-body">
                <p><b>Asistente:</b> ¡Hola! Soy tu asistente para gestionar turnos médicos. ¿En qué puedo ayudarte?</p>
            </div>
        </div>
        <form id="form">
            @csrf
            <div class="input-group">
                <input id="msg" name="msg" class="form-control" placeholder="Escribe tu mensaje..." required>
                <button type="submit" class="btn btn-primary" id="btnEnviar">Enviar</button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const chatDiv = document.getElementById('chat').querySelector('.card-body');
        const form = document.getElementById('form');
        const msgInput = document.getElementById('msg');
        const btnEnviar = document.getElementById('btnEnviar');

        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const mensaje = msgInput.value.trim();
            if (!mensaje) return;

            // Deshabilitar botón
            btnEnviar.disabled = true;
            btnEnviar.textContent = 'Enviando...';

            // Mostrar mensaje del usuario
            chatDiv.innerHTML += `<p><b>Tú:</b> ${mensaje}</p>`;
            msgInput.value = '';
            chatDiv.parentElement.scrollTop = chatDiv.parentElement.scrollHeight;

            try {
                const response = await fetch('/chat', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({ msg: mensaje })
                });

                const data = await response.json();
                
                // Mostrar respuesta de la IA
                chatDiv.innerHTML += `<p><b>Asistente:</b> ${data.texto}</p>`;
                chatDiv.parentElement.scrollTop = chatDiv.parentElement.scrollHeight;

            } catch (error) {
                chatDiv.innerHTML += `<p class="text-danger"><b>Error:</b> No se pudo conectar con el servidor</p>`;
            }

            // Habilitar botón
            btnEnviar.disabled = false;
            btnEnviar.textContent = 'Enviar';
        });
    </script>
</body>
</html>