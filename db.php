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

if (!function_exists('ensure_criterios_extended_schema')) {
    function ensure_criterios_extended_schema(mysqli $conn): void
    {
        $database = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];

        $columnExists = function(string $column) use ($conn, $database): bool {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'criterios' AND COLUMN_NAME = ?
            ");
            $stmt->bind_param("ss", $database, $column);
            $stmt->execute();
            $total = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            return $total > 0;
        };

        if (!$columnExists('puntaje_maximo')) {
            $conn->query("ALTER TABLE criterios ADD COLUMN puntaje_maximo INT(11) NOT NULL DEFAULT 5 AFTER orden");
        }

        if (!$columnExists('ponderacion')) {
            $conn->query("ALTER TABLE criterios ADD COLUMN ponderacion DECIMAL(6,2) NOT NULL DEFAULT 1.00 AFTER puntaje_maximo");
        }
    }
}

ensure_criterios_extended_schema($conn);

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