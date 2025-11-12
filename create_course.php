<?php
require 'db.php';
// Solo docentes pueden crear cursos
verificar_sesion(true, false); // No requiere curso activo, ya que está creando uno

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre_curso = trim($_POST['nombre_curso']);
    $semestre = trim($_POST['semestre']);
    $anio = (int)$_POST['anio'];
    $id_docente = $_SESSION['id_usuario'];

    if (empty($nombre_curso) || empty($semestre) || $anio < 2024) {
        header("Location: select_course.php?error=" . urlencode("Datos del curso incompletos o inválidos."));
        exit();
    }

    $conn->begin_transaction();
    try {
        // 1. Crear el nuevo curso
        $stmt_curso = $conn->prepare("INSERT INTO cursos (nombre_curso, semestre, anio) VALUES (?, ?, ?)");
        $stmt_curso->bind_param("ssi", $nombre_curso, $semestre, $anio);
        $stmt_curso->execute();
        $id_curso = $conn->insert_id;
        $stmt_curso->close();

        // 2. Asociar el docente actual al nuevo curso (con ponderación 1.0 por defecto)
        $stmt_relacion = $conn->prepare("INSERT INTO docente_curso (id_docente, id_curso, ponderacion) VALUES (?, ?, 1.00)");
        $stmt_relacion->bind_param("ii", $id_docente, $id_curso);
        $stmt_relacion->execute();
        $stmt_relacion->close();

        $conn->commit();

        // 3. Establecer el curso recién creado como el curso activo en la sesión
        $_SESSION['id_curso_activo'] = $id_curso;

        // Redirigir al dashboard del docente con el nuevo curso activo
        header("Location: dashboard_docente.php?status=" . urlencode("Curso creado y establecido como activo."));
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        // Manejar error de curso duplicado o error general
        if ($conn->errno == 1062) { // Código de error MySQL para clave duplicada
             $error_msg = "Error: Ya existe un curso con ese nombre en ese período (" . htmlspecialchars($nombre_curso) . " " . htmlspecialchars($semestre) . "-" . htmlspecialchars($anio) . ").";
        } else {
             $error_msg = "Error al crear el curso: " . $e->getMessage();
        }
        header("Location: select_course.php?error=" . urlencode($error_msg));
        exit();
    }
} else {
    header("Location: select_course.php");
    exit();
}
?>