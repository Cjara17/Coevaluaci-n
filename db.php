<?php
// Cargar variables de entorno (solo si existe el archivo y composer está instalado)
if (file_exists(__DIR__ . '/vendor/autoload.php') && file_exists(__DIR__ . '/.env')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// En la parte superior, aseguramos el inicio de sesión
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- LÍNEAS PARA DEPURACIÓN ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ----------------------------

// Configurable session timeout in seconds (15 minutes) desde .env
$session_timeout = (int)($_ENV['SESSION_TIMEOUT'] ?? 900);

// Check for session inactivity timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    // Session has expired due to inactivity
    session_unset();
    session_destroy();
    header("Location: index.php?error=Sesión expirada por inactividad");
    exit();
}

// Update last activity time to current timestamp
$_SESSION['last_activity'] = time();

// Configuración de la Base de Datos desde .env
$servername = $_ENV['DB_HOST'] ?? 'localhost';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? '';
$dbname = $_ENV['DB_NAME'] ?? 'coeval_db';

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    // Si la conexión falla, forzamos un mensaje de error visible y detenemos el script
    echo "<h1>❌ CONEXIÓN FALLIDA ❌</h1>";
    echo "<p><strong>Error de MySQL:</strong> " . $conn->connect_error . "</p>";
    echo "<p>Por favor, asegúrese de que el servicio MySQL/MariaDB esté activo en XAMPP y que la contraseña en db.php sea correcta.</p>";
    exit(); 
} else {
    // Si la conexión es exitosa, lo confirmamos. 
    // Comenta esta línea después de verificar que funciona:
    // echo "<h1>✅ CONEXIÓN EXITOSA! ✅</h1>"; 
}

// Función para generar token CSRF
function generar_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Función para redirigir si el usuario no está logueado
function verificar_sesion($solo_docentes = false) {
    if (!isset($_SESSION['id_usuario'])) {
        header("Location: index.php");
        exit();
    }
    if ($solo_docentes && (!isset($_SESSION['es_docente']) || !$_SESSION['es_docente'])) {
        header("Location: index.php");
        exit();
    }
    // Generar token CSRF si no existe
    generar_csrf_token();
}
?>