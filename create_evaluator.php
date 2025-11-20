<?php
require 'db.php';
require_once __DIR__ . '/invite_helpers.php';
verificar_sesion(true);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: dashboard_docente.php");
    exit();
}

$tipo = isset($_POST['tipo_evaluador']) ? strtolower(trim($_POST['tipo_evaluador'])) : 'invitado';
if ($tipo === '') {
    $tipo = 'invitado';
}
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

$email_login = $email;
$usuario_generado = null;

if ($tipo === 'invitado') {
    $prefijo_username = strtolower(strtok($email, '@'));
    $prefijo_username = preg_replace('/[^a-z0-9]/', '', $prefijo_username ?? '');
    if ($prefijo_username === '') {
        $prefijo_username = 'usuario';
    }

    $prefijo_username = rtrim($prefijo_username, '.') . '.';

    $caracteres_username = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $longitud_cadena = strlen($caracteres_username);
    do {
        $sufijo = '';
        for ($i = 0; $i < 5; $i++) {
            $sufijo .= $caracteres_username[random_int(0, $longitud_cadena - 1)];
        }
        $usuario_generado = $prefijo_username . $sufijo;

        $stmt_check_username = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt_check_username->bind_param("s", $usuario_generado);
        $stmt_check_username->execute();
        $resultado_username = $stmt_check_username->get_result();
        $existe_username = $resultado_username->num_rows > 0;
        $stmt_check_username->close();
    } while ($existe_username);

    $email_login = $usuario_generado;
}

// Verificar si ya existe un usuario con el identificador que se guardará
$stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt_check->bind_param("s", $email_login);
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
$prefijo_display = strtok($email, '@');
$prefijo_display = preg_replace('/[^A-Za-z0-9]/', '', $prefijo_display);
if ($prefijo_display === '') {
    $prefijo_display = 'Usuario';
}
$nombre_generado = $prefijo_display;

$password_hash = password_hash($password, PASSWORD_DEFAULT);

$stmt_insert = $conn->prepare("INSERT INTO usuarios (nombre, email, es_docente, password, id_equipo, id_curso) VALUES (?, ?, ?, ?, NULL, NULL)");
$stmt_insert->bind_param("ssis", $nombre_generado, $email_login, $es_docente, $password_hash);

if (!$stmt_insert->execute()) {
    $stmt_insert->close();
    header("Location: dashboard_docente.php?invite_error=" . urlencode("No se pudo crear el perfil. Intenta nuevamente."));
    exit();
}
$nuevo_id_usuario = $stmt_insert->insert_id;
$stmt_insert->close();

if ($tipo === 'invitado') {
    upsert_invite_credential($nuevo_id_usuario, $email_login, $password);
}

// Enviar correo con credenciales usando PHPMailer
require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    // Configuración del servidor SMTP desde .env
    $mail->isSMTP();
    $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.example.com';
    $mail->SMTPAuth = true;
    $mail->Username = $_ENV['SMTP_USER'] ?? 'your_email@example.com';
    $mail->Password = $_ENV['SMTP_PASS'] ?? 'your_password';
    $mail->SMTPSecure = $_ENV['SMTP_ENCRYPTION'] ?? 'tls';
    $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);

    // Remitente
    $mail->setFrom($_ENV['FROM_EMAIL'] ?? 'noreply@uct.cl', $_ENV['FROM_NAME'] ?? 'Plataforma de Coevaluación');

    // Destinatario
    $mail->addAddress($email);

    // Contenido
    $mail->isHTML(false);
    $mail->Subject = 'Credenciales de acceso - Plataforma de Evaluación';
    $credencial_login = ($tipo === 'invitado') ? "Usuario: " . $email_login : "Correo: " . $email_login;
    $mail->Body = "Hola,\n\nSe ha creado un perfil en la Plataforma de Evaluación con los siguientes datos:\n\n" .
                  "Rol: " . ucfirst($tipo) . "\n" .
                  $credencial_login . "\n" .
                  "Contraseña: " . $password . "\n\n" .
                  "Puedes acceder desde: " . (isset($_SERVER['HTTP_HOST']) ? "http://{$_SERVER['HTTP_HOST']}/Coevaluaci-n/index.php" : "http://localhost/Coevaluaci-n/index.php") . "\n\n" .
                  "Por favor, cambia la contraseña después de iniciar sesión.\n\n" .
                  "Saludos,\nPlataforma de Evaluación";

    $mail->send();
    $correo_enviado = true;
} catch (Exception $e) {
    $correo_enviado = false;
}

if (!$correo_enviado) {
    header("Location: dashboard_docente.php?invite_error=" . urlencode("Perfil creado, pero ocurrió un problema al enviar las credenciales por correo. Verifica la configuración de correo del servidor."));
    exit();
}

header("Location: dashboard_docente.php?invite_success=1");
exit();

