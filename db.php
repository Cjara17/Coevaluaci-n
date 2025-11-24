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

if (!function_exists('ensure_usuarios_student_id')) {
    function ensure_usuarios_student_id(mysqli $conn): void
    {
        $database = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'student_id'
        ");
        $stmt->bind_param("s", $database);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        
        if ($total == 0) {
            // Agregar la columna student_id si no existe
            $conn->query("ALTER TABLE usuarios ADD COLUMN student_id varchar(100) DEFAULT NULL AFTER email");
            // Agregar índice único si no existe
            $conn->query("ALTER TABLE usuarios ADD UNIQUE KEY idx_student_id (student_id)");
        }
    }
}

ensure_usuarios_student_id($conn);

if (!function_exists('ensure_usuarios_estado_presentacion_individual')) {
    function ensure_usuarios_estado_presentacion_individual(mysqli $conn): void
    {
        $database = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'estado_presentacion_individual'
        ");
        $stmt->bind_param("s", $database);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        
        if ($total == 0) {
            // Agregar la columna estado_presentacion_individual si no existe
            $conn->query("ALTER TABLE usuarios ADD COLUMN estado_presentacion_individual varchar(20) DEFAULT 'pendiente' COMMENT 'Estado de presentación para evaluaciones individuales' AFTER id_curso");
        }
    }
}

ensure_usuarios_estado_presentacion_individual($conn);

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

if (!function_exists('ensure_invitado_curso_schema')) {
    function ensure_invitado_curso_schema(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS invitado_curso (
                id_invitado int(11) NOT NULL,
                id_curso int(11) NOT NULL,
                ponderacion decimal(5,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (id_invitado, id_curso),
                KEY id_curso (id_curso),
                CONSTRAINT invitado_curso_ibfk_1 FOREIGN KEY (id_invitado) REFERENCES usuarios (id) ON DELETE CASCADE,
                CONSTRAINT invitado_curso_ibfk_2 FOREIGN KEY (id_curso) REFERENCES cursos (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if (!$conn->query($sql)) {
            error_log('[InvitadoCursoTable] Error creando tabla invitado_curso: ' . $conn->error);
        }
    }
}

if (!function_exists('ensure_cursos_ponderacion_estudiantes')) {
    function ensure_cursos_ponderacion_estudiantes(mysqli $conn): void
    {
        $database = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
        
        $columnExists = function(string $column) use ($conn, $database): bool {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'cursos' AND COLUMN_NAME = ?
            ");
            $stmt->bind_param("ss", $database, $column);
            $stmt->execute();
            $total = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            return $total > 0;
        };
        
        if (!$columnExists('ponderacion_estudiantes')) {
            $conn->query("ALTER TABLE cursos ADD COLUMN ponderacion_estudiantes decimal(5,2) DEFAULT NULL COMMENT 'Ponderación de evaluaciones de estudiantes (0-100)' AFTER anio");
        }
        
        if (!$columnExists('usar_ponderacion_unica_invitados')) {
            $conn->query("ALTER TABLE cursos ADD COLUMN usar_ponderacion_unica_invitados tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Si es 1, se usa ponderación única para todos los invitados' AFTER ponderacion_estudiantes");
        }
        
        if (!$columnExists('ponderacion_unica_invitados')) {
            $conn->query("ALTER TABLE cursos ADD COLUMN ponderacion_unica_invitados decimal(5,2) DEFAULT NULL COMMENT 'Ponderación única para el promedio de todas las evaluaciones de invitados (0-100)' AFTER usar_ponderacion_unica_invitados");
        }
    }
}

ensure_invitado_curso_schema($conn);
ensure_cursos_ponderacion_estudiantes($conn);

if (!function_exists('ensure_evaluaciones_schema')) {
    function ensure_evaluaciones_schema(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS evaluaciones (
                id int(11) NOT NULL AUTO_INCREMENT,
                nombre_evaluacion varchar(255) NOT NULL,
                tipo_evaluacion enum('grupal','individual') NOT NULL,
                estado enum('pendiente','iniciada','cerrada') NOT NULL DEFAULT 'pendiente',
                id_curso int(11) NOT NULL,
                fecha_creacion timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (id),
                KEY id_curso (id_curso),
                CONSTRAINT evaluaciones_ibfk_1 FOREIGN KEY (id_curso) REFERENCES cursos (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if (!$conn->query($sql)) {
            error_log('[EvaluacionesTable] Error creando tabla evaluaciones: ' . $conn->error);
        }
    }
}

ensure_evaluaciones_schema($conn);

if (!function_exists('ensure_cursos_rendimiento_minimo')) {
    function ensure_cursos_rendimiento_minimo(mysqli $conn): void
    {
        $database = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'];
        $columnExists = function(string $column) use ($conn, $database): bool {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'cursos' AND COLUMN_NAME = ?
            ");
            $stmt->bind_param("ss", $database, $column);
            $stmt->execute();
            $total = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            return $total > 0;
        };

        if (!$columnExists('rendimiento_minimo')) {
            $conn->query("ALTER TABLE cursos ADD COLUMN rendimiento_minimo decimal(5,2) DEFAULT 40.00 COMMENT 'Porcentaje mínimo para aprobar con nota 4.0 (0-100)' AFTER ponderacion_unica_invitados");
        }
    }
}

ensure_cursos_rendimiento_minimo($conn);

if (!function_exists('ensure_cursos_nota_minima')) {
    function ensure_cursos_nota_minima(mysqli $conn): void
    {
        $columnExists = function($columnName) use ($conn) {
            $stmt = $conn->prepare("SELECT COUNT(*) as total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = ?");
            $stmt->bind_param("s", $columnName);
            $stmt->execute();
            $total = (int)$stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            return $total > 0;
        };
        
        if (!$columnExists('nota_minima')) {
            $conn->query("ALTER TABLE cursos ADD COLUMN nota_minima decimal(3,1) NOT NULL DEFAULT 1.0 COMMENT 'Nota mínima de la escala (1.0 o 2.0)' AFTER rendimiento_minimo");
        }
    }
}

ensure_cursos_nota_minima($conn);

if (!function_exists('ensure_opciones_evaluacion_schema')) {
    function ensure_opciones_evaluacion_schema(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS opciones_evaluacion (
                id int(11) NOT NULL AUTO_INCREMENT,
                id_curso int(11) NOT NULL,
                nombre varchar(100) NOT NULL,
                puntaje decimal(10,2) NOT NULL DEFAULT 0.00,
                orden int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY id_curso (id_curso),
                CONSTRAINT opciones_evaluacion_ibfk_1 FOREIGN KEY (id_curso) REFERENCES cursos (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if (!$conn->query($sql)) {
            error_log('[OpcionesEvaluacionTable] Error creando tabla opciones_evaluacion: ' . $conn->error);
        }
    }
}

if (!function_exists('ensure_criterio_opcion_descripciones_schema')) {
    function ensure_criterio_opcion_descripciones_schema(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS criterio_opcion_descripciones (
                id int(11) NOT NULL AUTO_INCREMENT,
                id_criterio int(11) NOT NULL,
                id_opcion int(11) NOT NULL,
                descripcion text DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY idx_criterio_opcion (id_criterio, id_opcion),
                KEY id_criterio (id_criterio),
                KEY id_opcion (id_opcion),
                CONSTRAINT criterio_opcion_desc_ibfk_1 FOREIGN KEY (id_criterio) REFERENCES criterios (id) ON DELETE CASCADE,
                CONSTRAINT criterio_opcion_desc_ibfk_2 FOREIGN KEY (id_opcion) REFERENCES opciones_evaluacion (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if (!$conn->query($sql)) {
            error_log('[CriterioOpcionDescTable] Error creando tabla criterio_opcion_descripciones: ' . $conn->error);
        }
    }
}

ensure_opciones_evaluacion_schema($conn);
ensure_criterio_opcion_descripciones_schema($conn);

if (!function_exists('ensure_escala_notas_curso_schema')) {
    function ensure_escala_notas_curso_schema(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS escala_notas_curso (
                id int(11) NOT NULL AUTO_INCREMENT,
                id_curso int(11) NOT NULL,
                puntaje decimal(10,2) NOT NULL,
                nota decimal(5,2) NOT NULL,
                orden int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY idx_curso_puntaje (id_curso, puntaje),
                KEY id_curso (id_curso),
                CONSTRAINT escala_notas_curso_ibfk_1 FOREIGN KEY (id_curso) REFERENCES cursos (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        if (!$conn->query($sql)) {
            error_log('[EscalaNotasCursoTable] Error creando tabla escala_notas_curso: ' . $conn->error);
        }
    }
}

ensure_escala_notas_curso_schema($conn);

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