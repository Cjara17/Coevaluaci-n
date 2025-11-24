<?php
require 'db.php';
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php?error=" . urlencode("Curso activo no disponible."));
    exit();
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Acción: Obtener estudiantes disponibles (para el modal)
if ($action === 'get_available_students' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id_equipo = isset($_GET['id_equipo']) ? (int)$_GET['id_equipo'] : 0;
    
    // Obtener todos los estudiantes del curso (incluyendo los que ya están en otros equipos)
    // Si id_equipo > 0, excluir los que ya están en ese equipo
    if ($id_equipo > 0) {
        $sql = "
            SELECT u.id, u.nombre, u.email, u.id_equipo, e.nombre_equipo as equipo_actual
            FROM usuarios u
            LEFT JOIN equipos e ON u.id_equipo = e.id
            WHERE u.es_docente = 0 
            AND u.id_curso = ?
            AND (u.id_equipo IS NULL OR u.id_equipo != ?)
            ORDER BY u.nombre ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $id_curso_activo, $id_equipo);
    } else {
        $sql = "
            SELECT u.id, u.nombre, u.email, u.id_equipo, e.nombre_equipo as equipo_actual
            FROM usuarios u
            LEFT JOIN equipos e ON u.id_equipo = e.id
            WHERE u.es_docente = 0 
            AND u.id_curso = ?
            ORDER BY u.nombre ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_curso_activo);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $estudiantes = [];
    while ($row = $result->fetch_assoc()) {
        $estudiantes[] = [
            'id' => $row['id'],
            'nombre' => $row['nombre'],
            'email' => $row['email'],
            'equipo_actual' => $row['equipo_actual']
        ];
    }
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode(['estudiantes' => $estudiantes]);
    exit();
}

// Acción: Obtener estudiantes de un equipo específico
if ($action === 'get_team_students' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id_equipo = isset($_GET['id_equipo']) ? (int)$_GET['id_equipo'] : 0;
    
    if ($id_equipo === 0) {
        header('Content-Type: application/json');
        echo json_encode(['estudiantes' => []]);
        exit();
    }
    
    // Verificar que el equipo pertenezca al curso activo
    $stmt_check = $conn->prepare("SELECT id FROM equipos WHERE id = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_equipo, $id_curso_activo);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows === 0) {
        $stmt_check->close();
        header('Content-Type: application/json');
        echo json_encode(['estudiantes' => []]);
        exit();
    }
    $stmt_check->close();
    
    // Obtener estudiantes del equipo
    $sql = "
        SELECT u.id, u.nombre, u.email
        FROM usuarios u
        WHERE u.id_equipo = ? 
        AND u.es_docente = 0
        AND u.id_curso = ?
        ORDER BY u.nombre ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id_equipo, $id_curso_activo);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $estudiantes = [];
    while ($row = $result->fetch_assoc()) {
        $estudiantes[] = [
            'id' => $row['id'],
            'nombre' => $row['nombre'],
            'email' => $row['email']
        ];
    }
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode(['estudiantes' => $estudiantes]);
    exit();
}

// Acción: Crear equipo
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre_equipo = isset($_POST['nombre_equipo']) ? trim($_POST['nombre_equipo']) : '';
    $estudiantes_ids = isset($_POST['estudiantes']) ? $_POST['estudiantes'] : [];
    
    if (empty($nombre_equipo)) {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("El nombre del equipo no puede estar vacío."));
        exit();
    }
    
    // Verificar que no exista un equipo con el mismo nombre en el curso
    $stmt_check = $conn->prepare("SELECT id FROM equipos WHERE nombre_equipo = ? AND id_curso = ?");
    $stmt_check->bind_param("si", $nombre_equipo, $id_curso_activo);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        $stmt_check->close();
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Ya existe un equipo con ese nombre en este curso."));
        exit();
    }
    $stmt_check->close();
    
    $conn->begin_transaction();
    try {
        // Crear el equipo
        $stmt = $conn->prepare("INSERT INTO equipos (nombre_equipo, id_curso, estado_presentacion) VALUES (?, ?, 'pendiente')");
        $stmt->bind_param("si", $nombre_equipo, $id_curso_activo);
        $stmt->execute();
        $id_equipo = $conn->insert_id;
        $stmt->close();
        
        // Asignar estudiantes al equipo si se seleccionaron
        $agregados = 0;
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');
        
        if (!empty($estudiantes_ids)) {
            foreach ($estudiantes_ids as $id_estudiante) {
                $id_estudiante = (int)$id_estudiante;
                
                // Verificar que el estudiante pertenezca al curso activo
                $stmt_verificar = $conn->prepare("SELECT id, nombre FROM usuarios WHERE id = ? AND id_curso = ? AND es_docente = 0");
                $stmt_verificar->bind_param("ii", $id_estudiante, $id_curso_activo);
                $stmt_verificar->execute();
                $estudiante = $stmt_verificar->get_result()->fetch_assoc();
                $stmt_verificar->close();
                
                if ($estudiante) {
                    // Asignar estudiante al equipo
                    $stmt_asignar = $conn->prepare("UPDATE usuarios SET id_equipo = ? WHERE id = ?");
                    $stmt_asignar->bind_param("ii", $id_equipo, $id_estudiante);
                    $stmt_asignar->execute();
                    $stmt_asignar->close();
                    
                    $agregados++;
                }
            }
        }
        
        // Registrar en log
        $detalle = "Creó equipo: " . $nombre_equipo . " en curso ID $id_curso_activo" . ($agregados > 0 ? " con $agregados estudiante(s)" : "");
        $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'CREAR', ?, ?)");
        $log->bind_param("iss", $user_id, $detalle, $now);
        $log->execute();
        $log->close();
        
        $conn->commit();
        $mensaje = "Equipo creado exitosamente.";
        if ($agregados > 0) {
            $mensaje .= " Se agregaron $agregados estudiante(s).";
        }
        header("Location: gestionar_estudiantes_equipos.php?status=" . urlencode($mensaje));
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Error al crear el equipo: " . $e->getMessage()));
    }
    exit();
}

// Acción: Actualizar equipo
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_equipo = isset($_POST['id_equipo']) ? (int)$_POST['id_equipo'] : 0;
    $nombre_equipo = isset($_POST['nombre_equipo']) ? trim($_POST['nombre_equipo']) : '';
    $estudiantes_ids = isset($_POST['estudiantes']) ? $_POST['estudiantes'] : [];
    
    if ($id_equipo === 0 || empty($nombre_equipo)) {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Datos incompletos."));
        exit();
    }
    
    // Verificar que el equipo pertenezca al curso activo
    $stmt_check = $conn->prepare("SELECT id, nombre_equipo FROM equipos WHERE id = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_equipo, $id_curso_activo);
    $stmt_check->execute();
    $equipo_actual = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
    
    if (!$equipo_actual) {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Equipo no encontrado o no pertenece a este curso."));
        exit();
    }
    
    // Verificar que no exista otro equipo con el mismo nombre en el curso
    $stmt_check_nombre = $conn->prepare("SELECT id FROM equipos WHERE nombre_equipo = ? AND id_curso = ? AND id != ?");
    $stmt_check_nombre->bind_param("sii", $nombre_equipo, $id_curso_activo, $id_equipo);
    $stmt_check_nombre->execute();
    if ($stmt_check_nombre->get_result()->num_rows > 0) {
        $stmt_check_nombre->close();
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Ya existe otro equipo con ese nombre en este curso."));
        exit();
    }
    $stmt_check_nombre->close();
    
    $conn->begin_transaction();
    try {
        // Actualizar el nombre del equipo
        $stmt = $conn->prepare("UPDATE equipos SET nombre_equipo = ? WHERE id = ? AND id_curso = ?");
        $stmt->bind_param("sii", $nombre_equipo, $id_equipo, $id_curso_activo);
        $stmt->execute();
        $stmt->close();
        
        // Agregar nuevos estudiantes si se seleccionaron
        $agregados = 0;
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');
        
        if (!empty($estudiantes_ids)) {
            foreach ($estudiantes_ids as $id_estudiante) {
                $id_estudiante = (int)$id_estudiante;
                
                // Verificar que el estudiante pertenezca al curso activo
                $stmt_verificar = $conn->prepare("SELECT id, nombre FROM usuarios WHERE id = ? AND id_curso = ? AND es_docente = 0");
                $stmt_verificar->bind_param("ii", $id_estudiante, $id_curso_activo);
                $stmt_verificar->execute();
                $estudiante = $stmt_verificar->get_result()->fetch_assoc();
                $stmt_verificar->close();
                
                if ($estudiante) {
                    // Asignar estudiante al equipo
                    $stmt_asignar = $conn->prepare("UPDATE usuarios SET id_equipo = ? WHERE id = ?");
                    $stmt_asignar->bind_param("ii", $id_equipo, $id_estudiante);
                    $stmt_asignar->execute();
                    $stmt_asignar->close();
                    
                    $agregados++;
                }
            }
        }
        
        // Registrar en log
        $detalle = "Editó equipo ID $id_equipo: '" . $equipo_actual['nombre_equipo'] . "' -> '$nombre_equipo'";
        if ($agregados > 0) {
            $detalle .= " y agregó $agregados estudiante(s)";
        }
        $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
        $log->bind_param("iss", $user_id, $detalle, $now);
        $log->execute();
        $log->close();
        
        $conn->commit();
        $mensaje = "Equipo actualizado exitosamente.";
        if ($agregados > 0) {
            $mensaje .= " Se agregaron $agregados estudiante(s).";
        }
        header("Location: gestionar_estudiantes_equipos.php?status=" . urlencode($mensaje));
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Error al actualizar el equipo: " . $e->getMessage()));
    }
    exit();
}

// Acción: Eliminar equipo
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_equipo = isset($_POST['id_equipo']) ? (int)$_POST['id_equipo'] : 0;
    
    if ($id_equipo === 0) {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("ID de equipo no proporcionado."));
        exit();
    }
    
    // Verificar que el equipo pertenezca al curso activo y obtener su nombre
    $stmt_check = $conn->prepare("SELECT nombre_equipo FROM equipos WHERE id = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_equipo, $id_curso_activo);
    $stmt_check->execute();
    $equipo = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
    
    if (!$equipo) {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Equipo no encontrado o no pertenece a este curso."));
        exit();
    }
    
    $conn->begin_transaction();
    try {
        // Desasignar todos los estudiantes del equipo
        $stmt_desasignar = $conn->prepare("UPDATE usuarios SET id_equipo = NULL WHERE id_equipo = ?");
        $stmt_desasignar->bind_param("i", $id_equipo);
        $stmt_desasignar->execute();
        $stmt_desasignar->close();
        
        // Eliminar el equipo
        $stmt_delete = $conn->prepare("DELETE FROM equipos WHERE id = ? AND id_curso = ?");
        $stmt_delete->bind_param("ii", $id_equipo, $id_curso_activo);
        $stmt_delete->execute();
        $stmt_delete->close();
        
        // Registrar en log
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');
        $detalle = "Eliminó equipo: " . $equipo['nombre_equipo'] . " (ID: $id_equipo) del curso ID $id_curso_activo";
        $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ELIMINAR', ?, ?)");
        $log->bind_param("iss", $user_id, $detalle, $now);
        $log->execute();
        $log->close();
        
        $conn->commit();
        header("Location: gestionar_estudiantes_equipos.php?status=" . urlencode("Equipo eliminado exitosamente. Los estudiantes fueron desasignados."));
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Error al eliminar el equipo: " . $e->getMessage()));
    }
    exit();
}

// Acción: Eliminar estudiante de equipo
if ($action === 'remove_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_estudiante = isset($_POST['id_estudiante']) ? (int)$_POST['id_estudiante'] : 0;
    $id_equipo = isset($_POST['id_equipo']) ? (int)$_POST['id_equipo'] : 0;
    
    if ($id_estudiante === 0 || $id_equipo === 0) {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Datos incompletos."));
        exit();
    }
    
    // Verificar que el equipo pertenezca al curso activo
    $stmt_check_equipo = $conn->prepare("SELECT nombre_equipo FROM equipos WHERE id = ? AND id_curso = ?");
    $stmt_check_equipo->bind_param("ii", $id_equipo, $id_curso_activo);
    $stmt_check_equipo->execute();
    $equipo = $stmt_check_equipo->get_result()->fetch_assoc();
    $stmt_check_equipo->close();
    
    if (!$equipo) {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Equipo no encontrado o no pertenece a este curso."));
        exit();
    }
    
    // Verificar que el estudiante pertenezca al curso y al equipo
    $stmt_check_est = $conn->prepare("SELECT nombre, id_equipo FROM usuarios WHERE id = ? AND id_curso = ? AND es_docente = 0");
    $stmt_check_est->bind_param("ii", $id_estudiante, $id_curso_activo);
    $stmt_check_est->execute();
    $estudiante = $stmt_check_est->get_result()->fetch_assoc();
    $stmt_check_est->close();
    
    if (!$estudiante || $estudiante['id_equipo'] != $id_equipo) {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Estudiante no encontrado o no pertenece a este equipo."));
        exit();
    }
    
    // Desasignar estudiante del equipo
    $stmt = $conn->prepare("UPDATE usuarios SET id_equipo = NULL WHERE id = ?");
    $stmt->bind_param("i", $id_estudiante);
    
    if ($stmt->execute()) {
        // Registrar en log
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');
        $detalle = "Eliminó estudiante " . $estudiante['nombre'] . " (ID: $id_estudiante) del equipo: " . $equipo['nombre_equipo'];
        $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
        $log->bind_param("iss", $user_id, $detalle, $now);
        $log->execute();
        $log->close();
        
        header("Location: gestionar_estudiantes_equipos.php?status=" . urlencode("Estudiante eliminado del equipo exitosamente."));
    } else {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Error al eliminar el estudiante del equipo: " . $stmt->error));
    }
    $stmt->close();
    exit();
}

// Acción: Agregar estudiantes a equipo
if ($action === 'add_students' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_equipo = isset($_POST['id_equipo']) ? (int)$_POST['id_equipo'] : 0;
    $estudiantes_ids = isset($_POST['estudiantes']) ? $_POST['estudiantes'] : [];
    
    if ($id_equipo === 0 || empty($estudiantes_ids)) {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Debes seleccionar al menos un estudiante."));
        exit();
    }
    
    // Verificar que el equipo pertenezca al curso activo
    $stmt_check = $conn->prepare("SELECT nombre_equipo FROM equipos WHERE id = ? AND id_curso = ?");
    $stmt_check->bind_param("ii", $id_equipo, $id_curso_activo);
    $stmt_check->execute();
    $equipo = $stmt_check->get_result()->fetch_assoc();
    $stmt_check->close();
    
    if (!$equipo) {
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Equipo no encontrado o no pertenece a este curso."));
        exit();
    }
    
    $conn->begin_transaction();
    try {
        $agregados = 0;
        $user_id = $_SESSION['id_usuario'];
        $now = date('Y-m-d H:i:s');
        
        foreach ($estudiantes_ids as $id_estudiante) {
            $id_estudiante = (int)$id_estudiante;
            
            // Verificar que el estudiante pertenezca al curso activo
            $stmt_verificar = $conn->prepare("SELECT id, nombre, id_equipo FROM usuarios WHERE id = ? AND id_curso = ? AND es_docente = 0");
            $stmt_verificar->bind_param("ii", $id_estudiante, $id_curso_activo);
            $stmt_verificar->execute();
            $estudiante = $stmt_verificar->get_result()->fetch_assoc();
            $stmt_verificar->close();
            
            if ($estudiante) {
                // Asignar estudiante al equipo
                $stmt_asignar = $conn->prepare("UPDATE usuarios SET id_equipo = ? WHERE id = ?");
                $stmt_asignar->bind_param("ii", $id_equipo, $id_estudiante);
                $stmt_asignar->execute();
                $stmt_asignar->close();
                
                $agregados++;
                
                // Registrar en log
                $detalle = "Agregó estudiante " . $estudiante['nombre'] . " (ID: $id_estudiante) al equipo: " . $equipo['nombre_equipo'];
                $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ACTUALIZAR', ?, ?)");
                $log->bind_param("iss", $user_id, $detalle, $now);
                $log->execute();
                $log->close();
            }
        }
        
        $conn->commit();
        header("Location: gestionar_estudiantes_equipos.php?status=" . urlencode("Se agregaron $agregados estudiante(s) al equipo exitosamente."));
    } catch (Exception $e) {
        $conn->rollback();
        header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Error al agregar estudiantes: " . $e->getMessage()));
    }
    exit();
}

// Si no hay acción válida, redirigir
header("Location: gestionar_estudiantes_equipos.php?error=" . urlencode("Acción no válida."));
exit();
?>

