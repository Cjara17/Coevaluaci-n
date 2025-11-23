<?php
/**
 * Establece el curso activo en la sesión para el docente autenticado.
 *
 * Requiere sesión activa de docente; no requiere curso activo previo para acceder.
 * Valida que el docente esté asociado al curso solicitado antes de establecerlo.
 *
 * Utiliza variables superglobales:
 * @global string $_SERVER['REQUEST_METHOD'] Método HTTP para determinar POST.
 * @global array $_POST['id_curso'] ID del curso enviado por POST.
 * @global int $_SESSION['id_usuario'] ID del docente autenticado.
 * @global int|null $_SESSION['id_curso_activo'] ID del curso activo (nullable).
 *
 * Redirige a:
 * - dashboard_docente.php con mensaje de éxito tras cambiar contexto de curso.
 * - select_course.php con mensaje de error si el docente no tiene acceso al curso solicitado.
 * - select_course.php si se accede sin método POST.
 *
 * @return void Redirige según flujo descrito.
 */
require 'db.php';
// Solo verifica sesión activa de docente, pero no requiere curso activo de antemano
verificar_sesion(true, false); 

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['id_curso'])) {
    $id_curso_seleccionado = (int)$_POST['id_curso'];
    $id_docente = $_SESSION['id_usuario'];

    // Seguridad: Verificar que el docente realmente esté asociado a ese curso
    $stmt_check = $conn->prepare("SELECT id_curso FROM docente_curso WHERE id_docente = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_docente, $id_curso_seleccionado);
    $stmt_check->execute();
    
    if ($stmt_check->get_result()->num_rows == 1) {
        // Curso válido: Establecer en la sesión y redirigir al dashboard
        $_SESSION['id_curso_activo'] = $id_curso_seleccionado;
        header("Location: dashboard_docente.php?status=" . urlencode("Contexto de curso cambiado."));
        exit();
    } else {
        // Acceso denegado, redirigir a la selección principal
        header("Location: select_course.php?error=" . urlencode("Acceso denegado al curso seleccionado."));
        exit();
    }
} else {
    // Si acceden directamente a este archivo sin POST, van a la selección principal
    header("Location: select_course.php"); 
    exit();
}
?>
