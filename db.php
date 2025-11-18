<?php
// En la parte superior, aseguramos el inicio de sesión
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- LÍNEAS PARA DEPURACIÓN ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ----------------------------

// Configurable session timeout in seconds (15 minutes)
$session_timeout = 900;

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

// Configuración de la Base de Datos
$servername = "localhost";
$username = "root";
$password = ""; 
$dbname = "coeval_db";

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

require_once __DIR__ . '/qualitative_helpers.php';
ensure_qualitative_schema($conn);

if (!function_exists('ensure_logs_table')) {
    function ensure_logs_table(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS logs (
                id INT(11) NOT NULL AUTO_INCREMENT,
                id_usuario INT(11) NOT NULL,
                accion VARCHAR(50) NOT NULL,
                detalle TEXT DEFAULT NULL,
                fecha DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_logs_usuario (id_usuario),
                CONSTRAINT fk_logs_usuario
                    FOREIGN KEY (id_usuario)
                    REFERENCES usuarios (id)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!$conn->query($sql)) {
            error_log('[LogsTable] Error creando tabla logs: ' . $conn->error);
        }
    }
}

ensure_logs_table($conn);

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
}
?>