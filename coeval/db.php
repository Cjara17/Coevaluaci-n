<?php
// --- LÍNEAS PARA DEPURACIÓN ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// -----------------------------------------
// Iniciar sesión para manejar variables de usuario
session_start();

$servidor = "localhost";
$usuario_db = "usuario"; // Cambia por tu usuario de MySQL
$password_db = "password"; // Cambia por tu contraseña de MySQL
$nombre_db = "nombre_bd";

// Crear conexión
$conn = new mysqli($servidor, $usuario_db, $password_db, $nombre_db);

// Chequear conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Función para redirigir si el usuario no está logueado
function verificar_sesion() {
    if (!isset($_SESSION['id_usuario'])) {
        header("Location: index.php");
        exit();
    }
}
?>
