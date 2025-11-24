<?php
require 'db.php';
verificar_sesion(true); // Solo docentes

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    $id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

    if (!$id_curso_activo) {
        header("Location: select_course.php");
        exit();
    }

    $status_message = "Acción realizada con éxito.";
    $error_message = "";

    // Función para redirigir fácilmente
    function redirect($status, $error = "") {
        $url = "gestionar_criterios.php";
        if (!empty($status)) {
            $url .= "?status=" . urlencode($status);
        } elseif (!empty($error)) {
            $url .= "?error=" . urlencode($error);
        }
        header("Location: " . $url);
        exit();
    }

    switch ($action) {
        // --- 1. AÑADIR NUEVO CRITERIO ---
        case 'add':
            $descripcion = trim($_POST['descripcion']);
            $orden = (int)$_POST['orden'];
            $puntaje_maximo = isset($_POST['puntaje_maximo']) ? max(1, (int)$_POST['puntaje_maximo']) : 5;
            $ponderacion = isset($_POST['ponderacion']) ? max(0, (float)$_POST['ponderacion']) : 1.0;

            if (empty($descripcion)) {
                redirect("", "La descripción no puede estar vacía.");
            }

            // Inserción, incluyendo el id_curso_activo
            $stmt = $conn->prepare("INSERT INTO criterios (descripcion, orden, puntaje_maximo, ponderacion, id_curso) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("siidi", $descripcion, $orden, $puntaje_maximo, $ponderacion, $id_curso_activo);

            if ($stmt->execute()) {
                redirect("Criterio '$descripcion' añadido al curso.");
            } else {
                $error_message = "Error al añadir el criterio: " . $stmt->error;
                redirect("", $error_message);
            }
            $stmt->close();
            break;

        // --- AÑADIR NUEVA OPCIÓN DE EVALUACIÓN ---
        case 'add_opcion':
            $nombre = trim($_POST['nombre']);
            $puntaje = isset($_POST['puntaje']) ? (float)$_POST['puntaje'] : 0;
            $orden = isset($_POST['orden']) ? (int)$_POST['orden'] : 100;

            if (empty($nombre)) {
                redirect("", "El nombre de la opción no puede estar vacío.");
            }

            $stmt = $conn->prepare("INSERT INTO opciones_evaluacion (id_curso, nombre, puntaje, orden) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isdi", $id_curso_activo, $nombre, $puntaje, $orden);

            if ($stmt->execute()) {
                redirect("Opción '$nombre' añadida correctamente.");
            } else {
                redirect("", "Error al añadir la opción: " . $stmt->error);
            }
            $stmt->close();
            break;

        // --- ACTUALIZAR CAMPO DE CRITERIO (inline) ---
        case 'update_campo':
            header('Content-Type: application/json');
            $id_criterio = (int)$_POST['id_criterio'];
            $campo = $_POST['campo'];
            $valor = trim($_POST['valor']);

            if ($id_criterio <= 0 || !in_array($campo, ['descripcion', 'orden'])) {
                echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
                exit();
            }

            $stmt = $conn->prepare("UPDATE criterios SET $campo = ? WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("sii", $valor, $id_criterio, $id_curso_activo);

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
            }
            $stmt->close();
            exit();

        // --- ACTUALIZAR OPCIÓN DE EVALUACIÓN ---
        case 'update_opcion':
            header('Content-Type: application/json');
            $id_opcion = (int)$_POST['id_opcion'];
            $campo = $_POST['campo'];
            $valor = trim($_POST['valor']);

            if ($id_opcion <= 0 || !in_array($campo, ['nombre', 'puntaje', 'orden'])) {
                echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
                exit();
            }

            // Verificar que la opción pertenece al curso activo
            $stmt_check = $conn->prepare("SELECT id FROM opciones_evaluacion WHERE id = ? AND id_curso = ?");
            $stmt_check->bind_param("ii", $id_opcion, $id_curso_activo);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows == 0) {
                echo json_encode(['success' => false, 'error' => 'Opción no encontrada']);
                $stmt_check->close();
                exit();
            }
            $stmt_check->close();

            if ($campo === 'puntaje') {
                $valor = (float)$valor;
                $stmt = $conn->prepare("UPDATE opciones_evaluacion SET puntaje = ? WHERE id = ?");
                $stmt->bind_param("di", $valor, $id_opcion);
            } else {
                $stmt = $conn->prepare("UPDATE opciones_evaluacion SET $campo = ? WHERE id = ?");
                $stmt->bind_param("si", $valor, $id_opcion);
            }

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
            }
            $stmt->close();
            exit();

        // --- ELIMINAR OPCIÓN DE EVALUACIÓN ---
        case 'delete_opcion':
            if (!isset($_POST['confirm']) || $_POST['confirm'] !== 'yes') {
                redirect("Eliminación cancelada");
            }

            $id_opcion = (int)$_POST['id_opcion'];

            // Verificar que la opción pertenece al curso activo
            $stmt_check = $conn->prepare("SELECT id FROM opciones_evaluacion WHERE id = ? AND id_curso = ?");
            $stmt_check->bind_param("ii", $id_opcion, $id_curso_activo);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows == 0) {
                redirect("", "Opción no encontrada o no pertenece al curso activo.");
            }
            $stmt_check->close();

            $stmt = $conn->prepare("DELETE FROM opciones_evaluacion WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("ii", $id_opcion, $id_curso_activo);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    redirect("Opción eliminada correctamente.");
                } else {
                    redirect("", "Opción no encontrada.");
                }
            } else {
                redirect("", "Error al eliminar opción: " . $stmt->error);
            }
            $stmt->close();
            break;

        // --- GUARDAR DESCRIPCIÓN CRITERIO-OPCIÓN ---
        case 'save_descripcion':
            header('Content-Type: application/json');
            $id_criterio = (int)$_POST['id_criterio'];
            $id_opcion = (int)$_POST['id_opcion'];
            $descripcion = trim($_POST['descripcion']);

            // Verificar que ambos pertenecen al curso activo
            $stmt_check = $conn->prepare("
                SELECT c.id 
                FROM criterios c 
                WHERE c.id = ? AND c.id_curso = ?
            ");
            $stmt_check->bind_param("ii", $id_criterio, $id_curso_activo);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows == 0) {
                echo json_encode(['success' => false, 'error' => 'Criterio no encontrado']);
                $stmt_check->close();
                exit();
            }
            $stmt_check->close();

            $stmt_check = $conn->prepare("
                SELECT o.id 
                FROM opciones_evaluacion o 
                WHERE o.id = ? AND o.id_curso = ?
            ");
            $stmt_check->bind_param("ii", $id_opcion, $id_curso_activo);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows == 0) {
                echo json_encode(['success' => false, 'error' => 'Opción no encontrada']);
                $stmt_check->close();
                exit();
            }
            $stmt_check->close();

            // Insertar o actualizar descripción
            $stmt = $conn->prepare("
                INSERT INTO criterio_opcion_descripciones (id_criterio, id_opcion, descripcion) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE descripcion = ?
            ");
            $stmt->bind_param("iiss", $id_criterio, $id_opcion, $descripcion, $descripcion);

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
            }
            $stmt->close();
            exit();

        // --- ACTUALIZAR OPCIÓN DESDE MODAL ---
        case 'update_opcion_modal':
            $id_opcion = (int)$_POST['id_opcion'];
            $nombre = trim($_POST['nombre']);
            $puntaje = isset($_POST['puntaje']) ? (float)$_POST['puntaje'] : 0;
            $orden = isset($_POST['orden']) ? (int)$_POST['orden'] : 100;

            if (empty($nombre)) {
                redirect("", "El nombre de la opción no puede estar vacío.");
            }

            // Verificar que la opción pertenece al curso activo
            $stmt_check = $conn->prepare("SELECT id FROM opciones_evaluacion WHERE id = ? AND id_curso = ?");
            $stmt_check->bind_param("ii", $id_opcion, $id_curso_activo);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows == 0) {
                redirect("", "Opción no encontrada o no pertenece al curso activo.");
            }
            $stmt_check->close();

            $stmt = $conn->prepare("UPDATE opciones_evaluacion SET nombre = ?, puntaje = ?, orden = ? WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("sdiii", $nombre, $puntaje, $orden, $id_opcion, $id_curso_activo);

            if ($stmt->execute()) {
                redirect("Opción actualizada correctamente.");
            } else {
                redirect("", "Error al actualizar opción: " . $stmt->error);
            }
            $stmt->close();
            break;

        // --- ACTUALIZAR RENDIMIENTO MÍNIMO ---
        case 'update_rendimiento_minimo':
            header('Content-Type: application/json');
            $rendimiento_minimo = isset($_POST['rendimiento_minimo']) ? (float)$_POST['rendimiento_minimo'] : 40.0;

            if ($rendimiento_minimo < 0 || $rendimiento_minimo > 100) {
                echo json_encode(['success' => false, 'error' => 'El rendimiento mínimo debe estar entre 0 y 100%']);
                exit();
            }

            $stmt = $conn->prepare("UPDATE cursos SET rendimiento_minimo = ? WHERE id = ?");
            $stmt->bind_param("di", $rendimiento_minimo, $id_curso_activo);

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
            }
            $stmt->close();
            exit();

        // --- ACTUALIZAR NOTA MÍNIMA ---
        case 'update_nota_minima':
            header('Content-Type: application/json');
            $nota_minima = isset($_POST['nota_minima']) ? (float)$_POST['nota_minima'] : 1.0;

            if ($nota_minima !== 1.0 && $nota_minima !== 2.0) {
                echo json_encode(['success' => false, 'error' => 'La nota mínima debe ser 1.0 o 2.0']);
                exit();
            }

            $stmt = $conn->prepare("UPDATE cursos SET nota_minima = ? WHERE id = ?");
            $stmt->bind_param("di", $nota_minima, $id_curso_activo);

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
            }
            $stmt->close();
            exit();

        case 'update':
            $id_criterio = (int)$_POST['id_criterio'];
            $descripcion = trim($_POST['descripcion']);
            $orden = isset($_POST['orden']) ? (int)$_POST['orden'] : 0;
            $puntaje_maximo = isset($_POST['puntaje_maximo']) ? max(1, (int)$_POST['puntaje_maximo']) : 5;
            $ponderacion = isset($_POST['ponderacion']) ? max(0, (float)$_POST['ponderacion']) : 1.0;

            if ($id_criterio <= 0) {
                redirect("", "Criterio inválido.");
            }

            if ($descripcion === '') {
                redirect("", "La descripción no puede estar vacía.");
            }

            $stmt = $conn->prepare("UPDATE criterios SET descripcion = ?, orden = ?, puntaje_maximo = ?, ponderacion = ? WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("siidii", $descripcion, $orden, $puntaje_maximo, $ponderacion, $id_criterio, $id_curso_activo);

            if ($stmt->execute()) {
                redirect("Criterio actualizado correctamente.");
            } else {
                redirect("", "Error al actualizar criterio: " . $stmt->error);
            }
            $stmt->close();
            break;

        // --- 2. ACTIVAR/DESACTIVAR ESTADO ---
        case 'toggle_status':
            $id_criterio = (int)$_POST['id_criterio'];

            // Obtener el estado actual y verificar pertenencia al curso
            $stmt_current = $conn->prepare("SELECT activo FROM criterios WHERE id = ? AND id_curso = ?");
            $stmt_current->bind_param("ii", $id_criterio, $id_curso_activo);
            $stmt_current->execute();
            $result = $stmt_current->get_result();

            if ($result->num_rows == 0) {
                 redirect("", "Criterio no encontrado o no pertenece al curso activo.");
            }

            $current_estado = $result->fetch_assoc()['activo'];
            $new_estado = $current_estado ? 0 : 1; // 1=Activo, 0=Inactivo
            $accion_msg = $new_estado ? 'Activado' : 'Desactivado';

            // Actualizar el estado, siempre filtrando por id_curso para seguridad
            $stmt = $conn->prepare("UPDATE criterios SET activo = ? WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("iii", $new_estado, $id_criterio, $id_curso_activo);

            if ($stmt->execute()) {
                redirect("Criterio $id_criterio $accion_msg con éxito.");
            } else {
                $error_message = "Error al cambiar estado: " . $stmt->error;
                redirect("", $error_message);
            }
            $stmt->close();
            break;

        // --- 3. ELIMINAR CRITERIO ---
        case 'delete':
            if (!isset($_POST['confirm']) || $_POST['confirm'] !== 'yes') {
                redirect("Eliminación cancelada");
            }

            $id_criterio = (int)$_POST['id_criterio'];

            // Eliminar, siempre filtrando por id_curso para seguridad
            $stmt = $conn->prepare("DELETE FROM criterios WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("ii", $id_criterio, $id_curso_activo);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    // Registrar log de eliminación
                    $user_id = $_SESSION['id_usuario'];
                    $now = date('Y-m-d H:i:s');
                    $detalle = "Eliminó CRITERIO ID $id_criterio";

                    $log = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'ELIMINAR', ?, ?)");
                    $log->bind_param("iss", $user_id, $detalle, $now);
                    $log->execute();
                    $log->close();

                    redirect("Criterio $id_criterio eliminado permanentemente.");
                } else {
                    redirect("", "Criterio no encontrado o no pertenece al curso activo.");
                }
            } else {
                $error_message = "Error al eliminar criterio: " . $stmt->error;
                redirect("", $error_message);
            }
            $stmt->close();
            break;

        // --- ACTUALIZAR PUNTAJE DE ESCALA DE NOTAS ---
        case 'update_puntaje_escala':
            header('Content-Type: application/json');
            $id_escala = isset($_POST['id_escala']) ? (int)$_POST['id_escala'] : 0;
            $puntaje = isset($_POST['puntaje']) ? (float)$_POST['puntaje'] : 0;
            $nota = isset($_POST['nota']) ? (float)$_POST['nota'] : 0;

            if ($id_escala <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID de escala inválido']);
                exit();
            }

            // Verificar que la escala pertenece al curso activo
            $stmt_check = $conn->prepare("SELECT id FROM escala_notas_curso WHERE id = ? AND id_curso = ?");
            $stmt_check->bind_param("ii", $id_escala, $id_curso_activo);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            if ($result_check->num_rows == 0) {
                $stmt_check->close();
                echo json_encode(['success' => false, 'error' => 'Escala no encontrada o no pertenece al curso activo']);
                exit();
            }
            $stmt_check->close();

            // Actualizar puntaje y nota
            $stmt = $conn->prepare("UPDATE escala_notas_curso SET puntaje = ?, nota = ? WHERE id = ? AND id_curso = ?");
            $stmt->bind_param("ddii", $puntaje, $nota, $id_escala, $id_curso_activo);

            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $stmt->error]);
            }
            $stmt->close();
            exit();

        default:
            redirect("", "Acción inválida.");
            break;
    }
} else {
    header("Location: gestionar_criterios.php");
    exit();
}
?>
