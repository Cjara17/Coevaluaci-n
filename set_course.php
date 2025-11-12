<?php
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