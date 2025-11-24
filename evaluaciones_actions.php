<?php
require 'db.php';
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php?error=" . urlencode("Curso activo no disponible."));
    exit();
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Acción: Crear evaluación
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre_evaluacion = isset($_POST['nombre_evaluacion']) ? trim($_POST['nombre_evaluacion']) : '';
    $tipo_evaluacion = isset($_POST['tipo_evaluacion']) ? $_POST['tipo_evaluacion'] : '';
    
    if (empty($nombre_evaluacion) || empty($tipo_evaluacion)) {
        header("Location: dashboard_docente.php?error=" . urlencode("Todos los campos son obligatorios."));
        exit();
    }
    
    if (!in_array($tipo_evaluacion, ['grupal', 'individual'])) {
        header("Location: dashboard_docente.php?error=" . urlencode("Tipo de evaluación inválido."));
        exit();
    }
    
    // Verificar que no exista una evaluación con el mismo nombre en el curso
    $stmt_check = $conn->prepare("SELECT id FROM evaluaciones WHERE nombre_evaluacion = ? AND id_curso = ?");
    $stmt_check->bind_param("si", $nombre_evaluacion, $id_curso_activo);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        $stmt_check->close();
        header("Location: dashboard_docente.php?error=" . urlencode("Ya existe una evaluación con ese nombre en este curso."));
        exit();
    }
    $stmt_check->close();
    
    // Crear la evaluación
    $stmt = $conn->prepare("INSERT INTO evaluaciones (nombre_evaluacion, tipo_evaluacion, estado, id_curso) VALUES (?, ?, 'pendiente', ?)");
    $stmt->bind_param("ssi", $nombre_evaluacion, $tipo_evaluacion, $id_curso_activo);
    
    if ($stmt->execute()) {
        // Registrar en log
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');
        $detalle = "Creó evaluación: " . $nombre_evaluacion . " (tipo: $tipo_evaluacion) en curso ID $id_curso_activo";
        $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'CREAR', ?, ?)");
        $log->bind_param("iss", $user_id, $detalle, $now);
        $log->execute();
        $log->close();
        
        header("Location: dashboard_docente.php?status=" . urlencode("Evaluación creada exitosamente."));
    } else {
        header("Location: dashboard_docente.php?error=" . urlencode("Error al crear la evaluación: " . $stmt->error));
    }
    $stmt->close();
    exit();
}

// Acción: Actualizar evaluación
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_evaluacion = isset($_POST['id_evaluacion']) ? (int)$_POST['id_evaluacion'] : 0;
    $nombre_evaluacion = isset($_POST['nombre_evaluacion']) ? trim($_POST['nombre_evaluacion']) : '';
    $tipo_evaluacion = isset($_POST['tipo_evaluacion']) ? $_POST['tipo_evaluacion'] : '';
    
    if ($id_evaluacion === 0 || empty($nombre_evaluacion) || empty($tipo_evaluacion)) {
        header("Location: dashboard_docente.php?error=" . urlencode("Datos incompletos."));
        exit();
    }
    
    if (!in_array($tipo_evaluacion, ['grupal', 'individual'])) {
        header("Location: dashboard_docente.php?error=" . urlencode("Tipo de evaluación inválido."));
        exit();
    }
    
    // Verificar que la evaluación pertenezca al curso activo
    $stmt_check = $conn->prepare("SELECT nombre_evaluacion FROM evaluaciones WHERE id = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_evaluacion, $id_curso_activo);
    $stmt_check->execute();
    $evaluacion_actual = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
    
    if (!$evaluacion_actual) {
        header("Location: dashboard_docente.php?error=" . urlencode("Evaluación no encontrada o no pertenece a este curso."));
        exit();
    }
    
    // Verificar que no exista otra evaluación con el mismo nombre en el curso
    $stmt_check_nombre = $conn->prepare("SELECT id FROM evaluaciones WHERE nombre_evaluacion = ? AND id_curso = ? AND id != ?");
    $stmt_check_nombre->bind_param("sii", $nombre_evaluacion, $id_curso_activo, $id_evaluacion);
    $stmt_check_nombre->execute();
    if ($stmt_check_nombre->get_result()->num_rows > 0) {
        $stmt_check_nombre->close();
        header("Location: dashboard_docente.php?error=" . urlencode("Ya existe otra evaluación con ese nombre en este curso."));
        exit();
    }
    $stmt_check_nombre->close();
    
    // Actualizar la evaluación (solo si está pendiente)
    $stmt_check_estado = $conn->prepare("SELECT estado FROM evaluaciones WHERE id = ? AND id_curso = ?");
    $stmt_check_estado->bind_param("ii", $id_evaluacion, $id_curso_activo);
    $stmt_check_estado->execute();
    $estado_actual = $stmt_check_estado->get_result()->fetch_assoc();
    $stmt_check_estado->close();
    
    if ($estado_actual && $estado_actual['estado'] !== 'pendiente') {
        header("Location: dashboard_docente.php?error=" . urlencode("No se puede editar una evaluación que ya ha sido iniciada o cerrada."));
        exit();
    }
    
    $stmt = $conn->prepare("UPDATE evaluaciones SET nombre_evaluacion = ?, tipo_evaluacion = ? WHERE id = ? AND id_curso = ?");
    $stmt->bind_param("ssii", $nombre_evaluacion, $tipo_evaluacion, $id_evaluacion, $id_curso_activo);
    
    if ($stmt->execute()) {
        // Registrar en log
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');
        $detalle = "Editó evaluación ID $id_evaluacion: '" . $evaluacion_actual['nombre_evaluacion'] . "' -> '$nombre_evaluacion'";
        $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
        $log->bind_param("iss", $user_id, $detalle, $now);
        $log->execute();
        $log->close();
        
        header("Location: dashboard_docente.php?status=" . urlencode("Evaluación actualizada exitosamente."));
    } else {
        header("Location: dashboard_docente.php?error=" . urlencode("Error al actualizar la evaluación: " . $stmt->error));
    }
    $stmt->close();
    exit();
}

// Acción: Eliminar evaluación
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_evaluacion = isset($_POST['id_evaluacion']) ? (int)$_POST['id_evaluacion'] : 0;
    
    if ($id_evaluacion === 0) {
        header("Location: dashboard_docente.php?error=" . urlencode("ID de evaluación no proporcionado."));
        exit();
    }
    
    // Verificar que la evaluación pertenezca al curso activo y obtener su nombre
    $stmt_check = $conn->prepare("SELECT nombre_evaluacion, estado FROM evaluaciones WHERE id = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_evaluacion, $id_curso_activo);
    $stmt_check->execute();
    $evaluacion = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
    
    if (!$evaluacion) {
        header("Location: dashboard_docente.php?error=" . urlencode("Evaluación no encontrada o no pertenece a este curso."));
        exit();
    }
    
    // Solo permitir eliminar si está pendiente
    if ($evaluacion['estado'] !== 'pendiente') {
        header("Location: dashboard_docente.php?error=" . urlencode("No se puede eliminar una evaluación que ya ha sido iniciada o cerrada."));
        exit();
    }
    
    // Eliminar la evaluación
    $stmt = $conn->prepare("DELETE FROM evaluaciones WHERE id = ? AND id_curso = ?");
    $stmt->bind_param("ii", $id_evaluacion, $id_curso_activo);
    
    if ($stmt->execute()) {
        // Registrar en log
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');
        $detalle = "Eliminó evaluación: " . $evaluacion['nombre_evaluacion'] . " (ID: $id_evaluacion) del curso ID $id_curso_activo";
        $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ELIMINAR', ?, ?)");
        $log->bind_param("iss", $user_id, $detalle, $now);
        $log->execute();
        $log->close();
        
        header("Location: dashboard_docente.php?status=" . urlencode("Evaluación eliminada exitosamente."));
    } else {
        header("Location: dashboard_docente.php?error=" . urlencode("Error al eliminar la evaluación: " . $stmt->error));
    }
    $stmt->close();
    exit();
}

// Acción: Iniciar evaluación
if ($action === 'iniciar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_evaluacion = isset($_POST['id_evaluacion']) ? (int)$_POST['id_evaluacion'] : 0;
    
    if ($id_evaluacion === 0) {
        header("Location: dashboard_docente.php?error=" . urlencode("ID de evaluación no proporcionado."));
        exit();
    }
    
    // Verificar que la evaluación pertenezca al curso activo
    $stmt_check = $conn->prepare("SELECT nombre_evaluacion, estado FROM evaluaciones WHERE id = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_evaluacion, $id_curso_activo);
    $stmt_check->execute();
    $evaluacion = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
    
    if (!$evaluacion) {
        header("Location: dashboard_docente.php?error=" . urlencode("Evaluación no encontrada o no pertenece a este curso."));
        exit();
    }
    
    if ($evaluacion['estado'] !== 'pendiente') {
        header("Location: dashboard_docente.php?error=" . urlencode("Solo se pueden iniciar evaluaciones que están pendientes."));
        exit();
    }
    
    // Actualizar estado a iniciada
    $stmt = $conn->prepare("UPDATE evaluaciones SET estado = 'iniciada' WHERE id = ? AND id_curso = ?");
    $stmt->bind_param("ii", $id_evaluacion, $id_curso_activo);
    
    if ($stmt->execute()) {
        // Registrar en log
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');
        $detalle = "Inició evaluación: " . $evaluacion['nombre_evaluacion'] . " (ID: $id_evaluacion)";
        $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
        $log->bind_param("iss", $user_id, $detalle, $now);
        $log->execute();
        $log->close();
        
        // Redirigir a la página de ver evaluación
        header("Location: ver_evaluacion.php?id=" . $id_evaluacion);
    } else {
        header("Location: dashboard_docente.php?error=" . urlencode("Error al iniciar la evaluación: " . $stmt->error));
    }
    $stmt->close();
    exit();
}

// Acción: Cerrar evaluación
if ($action === 'cerrar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_evaluacion = isset($_POST['id_evaluacion']) ? (int)$_POST['id_evaluacion'] : 0;
    
    if ($id_evaluacion === 0) {
        header("Location: dashboard_docente.php?error=" . urlencode("ID de evaluación no proporcionado."));
        exit();
    }
    
    // Verificar que la evaluación pertenezca al curso activo
    $stmt_check = $conn->prepare("SELECT nombre_evaluacion, estado FROM evaluaciones WHERE id = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_evaluacion, $id_curso_activo);
    $stmt_check->execute();
    $evaluacion = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
    
    if (!$evaluacion) {
        header("Location: dashboard_docente.php?error=" . urlencode("Evaluación no encontrada o no pertenece a este curso."));
        exit();
    }
    
    if ($evaluacion['estado'] !== 'iniciada') {
        header("Location: dashboard_docente.php?error=" . urlencode("Solo se pueden cerrar evaluaciones que están iniciadas."));
        exit();
    }
    
    // Actualizar estado a cerrada
    $stmt = $conn->prepare("UPDATE evaluaciones SET estado = 'cerrada' WHERE id = ? AND id_curso = ?");
    $stmt->bind_param("ii", $id_evaluacion, $id_curso_activo);
    
    if ($stmt->execute()) {
        // Registrar en log
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');
        $detalle = "Cerró evaluación: " . $evaluacion['nombre_evaluacion'] . " (ID: $id_evaluacion)";
        $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
        $log->bind_param("iss", $user_id, $detalle, $now);
        $log->execute();
        $log->close();
        
        header("Location: dashboard_docente.php?status=" . urlencode("Evaluación cerrada exitosamente."));
    } else {
        header("Location: dashboard_docente.php?error=" . urlencode("Error al cerrar la evaluación: " . $stmt->error));
    }
    $stmt->close();
    exit();
}

// Acción: Seleccionar evaluación
if ($action === 'seleccionar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_evaluacion = isset($_POST['id_evaluacion']) ? (int)$_POST['id_evaluacion'] : 0;
    
    if ($id_evaluacion === 0) {
        header("Location: dashboard_docente.php?error=" . urlencode("ID de evaluación no proporcionado."));
        exit();
    }
    
    // Verificar que la evaluación pertenezca al curso activo
    $stmt_check = $conn->prepare("SELECT nombre_evaluacion, estado FROM evaluaciones WHERE id = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_evaluacion, $id_curso_activo);
    $stmt_check->execute();
    $evaluacion = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
    
    if (!$evaluacion) {
        header("Location: dashboard_docente.php?error=" . urlencode("Evaluación no encontrada o no pertenece a este curso."));
        exit();
    }
    
    // Solo permitir seleccionar evaluaciones iniciadas o cerradas
    if ($evaluacion['estado'] !== 'iniciada' && $evaluacion['estado'] !== 'cerrada') {
        header("Location: dashboard_docente.php?error=" . urlencode("Solo se pueden seleccionar evaluaciones iniciadas o cerradas."));
        exit();
    }
    
    // Guardar la evaluación seleccionada en la sesión
    $_SESSION['id_evaluacion_seleccionada'] = $id_evaluacion;
    
    // Registrar en log
    $user_id = $_SESSION['id_usuario'];
    $now = date('Y-m-d H:i:s');
    $detalle = "Seleccionó evaluación: " . $evaluacion['nombre_evaluacion'] . " (ID: $id_evaluacion)";
    $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
    $log->bind_param("iss", $user_id, $detalle, $now);
    $log->execute();
    $log->close();
    
    header("Location: dashboard_docente.php?status=" . urlencode("Evaluación seleccionada exitosamente."));
    exit();
}

// Si no hay acción válida, redirigir
header("Location: dashboard_docente.php?error=" . urlencode("Acción no válida."));
exit();
?>

