<?php
require 'db.php';
// Verificar sesión del docente
verificar_sesion(true, false);

$id_docente = $_SESSION['id_usuario'];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'delete_course') {
    // Validar confirm=yes
    if (!isset($_POST['confirm']) || $_POST['confirm'] !== 'yes') {
        header("Location: select_course.php?error=Confirmación requerida");
        exit();
    }

    $id_curso = (int)$_POST['id_curso'];

    // Verificar que el docente realmente posee ese curso en docente_curso
    $stmt_check = $conn->prepare("SELECT id_curso FROM docente_curso WHERE id_docente = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_docente, $id_curso);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check->num_rows == 0) {
        $stmt_check->close();
        header("Location: select_course.php?error=No tienes permiso para eliminar este curso");
        exit();
    }
    $stmt_check->close();

    // Eliminar la relación en docente_curso
    $stmt_delete = $conn->prepare("DELETE FROM docente_curso WHERE id_docente = ? AND id_curso = ?");
    $stmt_delete->bind_param("ii", $id_docente, $id_curso);
    $stmt_delete->execute();
    $stmt_delete->close();

    // (Opcional) si ya no existen docentes asignados a ese curso, mostrar mensaje: “Curso eliminado de tus asignaciones.” pero NO borrar la tabla cursos ni datos relacionados.
    // Verificar si quedan docentes asignados
    $stmt_remaining = $conn->prepare("SELECT COUNT(*) as count FROM docente_curso WHERE id_curso = ?");
    $stmt_remaining->bind_param("i", $id_curso);
    $stmt_remaining->execute();
    $remaining = $stmt_remaining->get_result()->fetch_assoc()['count'];
    $stmt_remaining->close();

    if ($remaining == 0) {
        $message = "Curso eliminado de tus asignaciones.";
    } else {
        $message = "Curso eliminado correctamente";
    }

    header("Location: select_course.php?status=" . urlencode($message));
    exit();
} else {
    header("Location: select_course.php");
    exit();
}
?>
