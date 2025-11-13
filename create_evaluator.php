<?php
require 'db.php';
verificar_sesion(true);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: dashboard_docente.php");
    exit();
}

$tipo = isset($_POST['tipo_evaluador']) ? strtolower(trim($_POST['tipo_evaluador'])) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';

$roles_permitidos = ['invitado', 'estudiante', 'docente'];
if (!in_array($tipo, $roles_permitidos, true)) {
    header("Location: dashboard_docente.php?invite_error=" . urlencode("Rol seleccionado no es válido."));
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: dashboard_docente.php?invite_error=" . urlencode("El correo electrónico no es válido."));
    exit();
}

if (strlen($password) < 6) {
    header("Location: dashboard_docente.php?invite_error=" . urlencode("La contraseña debe tener al menos 6 caracteres."));
    exit();
}

// Verificar si ya existe un usuario con ese correo
$stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt_check->bind_param("s", $email);
$stmt_check->execute();
$resultado_check = $stmt_check->get_result();
if ($resultado_check->num_rows > 0) {
    $stmt_check->close();
    header("Location: dashboard_docente.php?invite_error=" . urlencode("El correo ya está registrado en la plataforma."));
    exit();
}
$stmt_check->close();

$es_docente = ($tipo === 'docente') ? 1 : 0;

// Generar un nombre básico a partir del correo si no se solicita explícitamente.
$prefijo_correo = strtok($email, '@');
$prefijo_correo = preg_replace('/[^A-Za-z0-9]/', '', $prefijo_correo);
if ($prefijo_correo === '') {
    $prefijo_correo = 'Usuario';
}
$nombre_generado = ucfirst($tipo) . ' ' . $prefijo_correo;

$password_hash = password_hash($password, PASSWORD_DEFAULT);

$stmt_insert = $conn->prepare("INSERT INTO usuarios (nombre, email, es_docente, password, id_equipo, id_curso) VALUES (?, ?, ?, ?, NULL, NULL)");
$stmt_insert->bind_param("ssis", $nombre_generado, $email, $es_docente, $password_hash);

if (!$stmt_insert->execute()) {
    $stmt_insert->close();
    header("Location: dashboard_docente.php?invite_error=" . urlencode("No se pudo crear el perfil. Intenta nuevamente."));
    exit();
}
$stmt_insert->close();

// Enviar correo con credenciales
$asunto = "Credenciales de acceso - Plataforma de Evaluación";
$mensaje = "Hola,\n\nSe ha creado un perfil en la Plataforma de Evaluación con los siguientes datos:\n\n".
           "Rol: " . ucfirst($tipo) . "\n".
           "Correo: " . $email . "\n".
           "Contraseña: " . $password . "\n\n".
           "Puedes acceder desde: " . (isset($_SERVER['HTTP_HOST']) ? "http://{$_SERVER['HTTP_HOST']}/Coevaluaci-n/index.php" : "http://localhost/Coevaluaci-n/index.php") . "\n\n".
           "Por favor, cambia la contraseña después de iniciar sesión.\n\n".
           "Saludos,\nPlataforma de Evaluación";

$headers = "From: coeval.real@gmail.com\r\n";
$correo_enviado = @mail($email, $asunto, $mensaje, $headers);

if (!$correo_enviado) {
    header("Location: dashboard_docente.php?invite_error=" . urlencode("Perfil creado, pero ocurrió un problema al enviar las credenciales por correo. Verifica la configuración de correo del servidor."));
    exit();
}

header("Location: dashboard_docente.php?invite_success=1");
exit();

