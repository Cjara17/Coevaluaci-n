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

<<<<<<< HEAD
// Configuración de la Base de Datos
$servername = "localhost";
$username = "root"; 

// 🚨 PRUEBA CLAVE: Usamos la cadena vacía, que es la configuración por defecto más común.
// Si esto no funciona, prueba $password = "root"; de nuevo.
$password = ""; 
$dbname = "coeval_db"; // Nombre de la base de datos
=======
// Configurable session timeout in seconds (15 minutes)
$session_timeout = 900;

// Check for session inactivity timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    // Session has expired due to inactivity
    session_unset();
    session_destroy();
    header("Location: login.php?error=Sesión expirada por inactividad");
    exit();
}

// Update last activity time to current timestamp
$_SESSION['last_activity'] = time();

$servidor = "localhost";
$usuario_db = "root"; // Cambia por tu usuario de MySQL
$password_db = ""; // Cambia por tu contraseña de MySQL
$nombre_db = "coeval_db";
>>>>>>> 9f138c1ff81b044a7d1760d461ad8a8128013b70

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    // Si la conexión falla, forzamos un mensaje de error visible y detenemos el script
    echo "<h1>❌ CONEXIÓN FALLIDA ❌</h1>";
    echo "<p><strong>Error de MySQL:</strong> " . $conn->connect_error . "</p>";
    echo "<p>Por favor, asegúrese de que el servicio MySQL/MariaDB esté activo en XAMPP y que la contraseña en db.php (línea 16) sea correcta.</p>";
    exit(); 
} else {
    // Si la conexión es exitosa, lo confirmamos. 
    // Comenta esta línea después de verificar que funciona:
    // echo "<h1>✅ CONEXIÓN EXITOSA! ✅</h1>"; 
}

<<<<<<< HEAD
// ----------------------------------------------------------------------
// FUNCIONES DE SEGURIDAD Y CONTEXTO
// ----------------------------------------------------------------------

/**
 * Verifica si el usuario tiene una sesión activa y, si es docente,
 * lo redirige para seleccionar un curso si no tiene uno activo.
 * @param bool $requiere_docente Si se requiere rol de docente.
 * @param bool $requiere_curso Si se debe verificar que un curso esté activo (solo para docentes).
 */
function verificar_sesion($requiere_docente = false, $requiere_curso = true) {
    // 1. Verificar sesión activa
=======
// Función para redirigir si el usuario no está logueado
function verificar_sesion($solo_docentes = false) {
>>>>>>> 9f138c1ff81b044a7d1760d461ad8a8128013b70
    if (!isset($_SESSION['id_usuario'])) {
        header("Location: index.php");
        exit();
    }
<<<<<<< HEAD

    // 2. Verificar rol de docente (si es requerido)
    if ($requiere_docente && (!isset($_SESSION['es_docente']) || !$_SESSION['es_docente'])) {
        // Redirigir a una página de acceso denegado si no es docente
        header("Location: dashboard_estudiante.php"); 
        exit();
    }

    // 3. Verificar contexto de curso (SOLO para Docentes, si es requerido)
    if ($requiere_docente && $requiere_curso) {
        if (!isset($_SESSION['id_curso_activo'])) {
            // Si el docente no tiene curso activo, lo enviamos a la página de selección
            header("Location: select_course.php");
            exit();
        }
    }
=======
    if ($solo_docentes && (!isset($_SESSION['es_docente']) || !$_SESSION['es_docente'])) {
        header("Location: index.php");
        exit();
    }
>>>>>>> 9f138c1ff81b044a7d1760d461ad8a8128013b70
}

/**
 * Obtiene el ID del curso activo de la sesión. Si no está, redirige al docente.
 * Esta función DEBE usarse para todas las consultas SQL de datos de curso.
 * @return int El ID del curso activo.
 */
function get_active_course_id() {
    // Solo verificar si el usuario es docente. Los estudiantes no tienen contexto de curso en la sesión.
    if (isset($_SESSION['es_docente']) && $_SESSION['es_docente']) {
        if (!isset($_SESSION['id_curso_activo'])) {
            // Llama a la verificación completa que redirigirá
            verificar_sesion(true, true);
        }
        return (int)$_SESSION['id_curso_activo'];
    }
    // Para simplificar, asumiremos que si un script llama esto, ya pasó la verificación.
    return (int)$_SESSION['id_curso_activo'];
}