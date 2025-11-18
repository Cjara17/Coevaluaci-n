<?php
require 'db.php';
verificar_sesion(true);

$id_docente = $_SESSION['id_usuario'];
$id_curso = isset($_SESSION['id_curso_activo']) ? (int)$_SESSION['id_curso_activo'] : null;

if (!$id_curso) {
    header("Location: select_course.php?error=" . urlencode("Selecciona un curso antes de administrar conceptos."));
    exit();
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$allowed = ['add_concept', 'update_concept', 'delete_concept', 'toggle_concept', 'update_scale'];
if (!in_array($action, $allowed, true)) {
    header("Location: gestionar_conceptos.php?error=" . urlencode("Acción no válida."));
    exit();
}

function escala_pertenece_al_curso(mysqli $conn, int $id_escala, int $id_curso): bool {
    $stmt = $conn->prepare("SELECT id FROM escalas_cualitativas WHERE id = ? AND id_curso = ?");
    $stmt->bind_param("ii", $id_escala, $id_curso);
    $stmt->execute();
    $belongs = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $belongs;
}

try {
    switch ($action) {
        case 'update_scale':
            $id_escala = (int)($_POST['id_escala'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');

            if (!$id_escala || !escala_pertenece_al_curso($conn, $id_escala, $id_curso)) {
                throw new Exception("La escala seleccionada no pertenece al curso activo.");
            }
            if ($nombre === '') {
                throw new Exception("El nombre de la escala es obligatorio.");
            }

            $stmt = $conn->prepare("UPDATE escalas_cualitativas SET nombre = ?, descripcion = ?, actualizado_en = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bind_param("ssi", $nombre, $descripcion, $id_escala);
            $stmt->execute();
            $stmt->close();
            $msg = "Escala actualizada con éxito.";
            break;

        case 'add_concept':
            $id_escala = (int)($_POST['id_escala'] ?? 0);
            $etiqueta = trim($_POST['etiqueta'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $color = trim($_POST['color_hex'] ?? '#0d6efd');
            $orden = isset($_POST['orden']) ? (int)$_POST['orden'] : 0;

            if ($etiqueta === '') {
                throw new Exception("La etiqueta del concepto es obligatoria.");
            }
            if (!$id_escala || !escala_pertenece_al_curso($conn, $id_escala, $id_curso)) {
                throw new Exception("Escala no encontrada para el curso.");
            }
            if (!preg_match('/^#([A-Fa-f0-9]{6})$/', $color)) {
                $color = '#0d6efd';
            }

            $stmt = $conn->prepare("
                INSERT INTO conceptos_cualitativos (id_escala, etiqueta, descripcion, color_hex, orden, activo)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->bind_param("isssi", $id_escala, $etiqueta, $descripcion, $color, $orden);
            $stmt->execute();
            $stmt->close();
            $msg = "Concepto añadido correctamente.";
            break;

        case 'update_concept':
            $id_concepto = (int)($_POST['id_concepto'] ?? 0);
            $etiqueta = trim($_POST['etiqueta'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $color = trim($_POST['color_hex'] ?? '#0d6efd');
            $orden = isset($_POST['orden']) ? (int)$_POST['orden'] : 0;
            $activo = isset($_POST['activo']) ? (int)$_POST['activo'] : 0;

            if ($etiqueta === '') {
                throw new Exception("El nombre del concepto es obligatorio.");
            }
            $stmt = $conn->prepare("SELECT c.id, c.id_escala, e.id_curso FROM conceptos_cualitativos c JOIN escalas_cualitativas e ON c.id_escala = e.id WHERE c.id = ?");
            $stmt->bind_param("i", $id_concepto);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || (int)$row['id_curso'] !== $id_curso) {
                throw new Exception("No se encontró el concepto en tu curso.");
            }
            if (!preg_match('/^#([A-Fa-f0-9]{6})$/', $color)) {
                $color = '#0d6efd';
            }

            $stmt = $conn->prepare("
                UPDATE conceptos_cualitativos
                SET etiqueta = ?, descripcion = ?, color_hex = ?, orden = ?, activo = ?
                WHERE id = ?
            ");
            $stmt->bind_param("sssiii", $etiqueta, $descripcion, $color, $orden, $activo, $id_concepto);
            $stmt->execute();
            $stmt->close();
            $msg = "Concepto actualizado.";
            break;

        case 'delete_concept':
            $id_concepto = (int)($_POST['id_concepto'] ?? 0);
            if (!$id_concepto) {
                throw new Exception("Concepto inválido.");
            }
            $stmt = $conn->prepare("SELECT c.id, e.id_curso FROM conceptos_cualitativos c JOIN escalas_cualitativas e ON c.id_escala = e.id WHERE c.id = ?");
            $stmt->bind_param("i", $id_concepto);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row || (int)$row['id_curso'] !== $id_curso) {
                throw new Exception("No se puede eliminar este concepto.");
            }

            $stmt = $conn->prepare("DELETE FROM conceptos_cualitativos WHERE id = ?");
            $stmt->bind_param("i", $id_concepto);
            $stmt->execute();
            $stmt->close();
            $msg = "Concepto eliminado.";
            break;

        case 'toggle_concept':
            $id_concepto = (int)($_POST['id_concepto'] ?? 0);
            $nuevo_estado = isset($_POST['activo']) ? (int)$_POST['activo'] : 1;

            if (!$id_concepto) {
                throw new Exception("Concepto inválido.");
            }
            $stmt = $conn->prepare("SELECT c.id, e.id_curso FROM conceptos_cualitativos c JOIN escalas_cualitativas e ON c.id_escala = e.id WHERE c.id = ?");
            $stmt->bind_param("i", $id_concepto);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || (int)$row['id_curso'] !== $id_curso) {
                throw new Exception("No se puede actualizar este concepto.");
            }

            $stmt = $conn->prepare("UPDATE conceptos_cualitativos SET activo = ? WHERE id = ?");
            $stmt->bind_param("ii", $nuevo_estado, $id_concepto);
            $stmt->execute();
            $stmt->close();
            $msg = $nuevo_estado ? "Concepto activado." : "Concepto desactivado.";
            break;
    }

    if (isset($msg)) {
        $log_stmt = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, ?, ?, ?)");
        $accion = strtoupper($action);
        $detalle = $msg . " (Curso ID {$id_curso})";
        $fecha = date('Y-m-d H:i:s');
        $log_stmt->bind_param("isss", $id_docente, $accion, $detalle, $fecha);
        $log_stmt->execute();
        $log_stmt->close();
    }

    header("Location: gestionar_conceptos.php?status=" . urlencode($msg));
    exit();

} catch (Exception $e) {
    header("Location: gestionar_conceptos.php?error=" . urlencode($e->getMessage()));
    exit();
}
