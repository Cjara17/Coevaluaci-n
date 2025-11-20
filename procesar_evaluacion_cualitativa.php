<?php
require 'db.php';
verificar_sesion(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard_docente.php");
    exit();
}

$id_evaluador = $_SESSION['id_usuario'];
$id_equipo = isset($_POST['id_equipo_evaluado']) ? (int)$_POST['id_equipo_evaluado'] : 0;
$id_escala = isset($_POST['id_escala']) ? (int)$_POST['id_escala'] : 0;
$observaciones = isset($_POST['observaciones']) ? trim($_POST['observaciones']) : null;
$conceptos_seleccionados = isset($_POST['conceptos']) && is_array($_POST['conceptos']) ? $_POST['conceptos'] : [];

// NUEVO: Recopilar descripciones opcionales y sanitizarlas
$descripciones = [];
if (isset($_POST['descripciones']) && is_array($_POST['descripciones'])) {
    foreach ($_POST['descripciones'] as $id_criterio => $desc) {
        $id_criterio = (int)$id_criterio;
        $descripciones[$id_criterio] = htmlspecialchars(trim($desc));
    }
}

if ($id_equipo <= 0 || $id_escala <= 0 || empty($conceptos_seleccionados)) {
    header("Location: dashboard_docente.php?error=" . urlencode("Debes seleccionar opciones para todos los criterios."));
    exit();
}

// Obtener curso del equipo
$stmt_equipo = $conn->prepare("SELECT id_curso FROM equipos WHERE id = ?");
$stmt_equipo->bind_param("i", $id_equipo);
$stmt_equipo->execute();
$equipo_row = $stmt_equipo->get_result()->fetch_assoc();
$stmt_equipo->close();

if (!$equipo_row) {
    header("Location: dashboard_docente.php?error=" . urlencode("Equipo no válido."));
    exit();
}
$id_curso = (int)$equipo_row['id_curso'];

// Validar pertenencia de la escala al curso
$stmt_escala = $conn->prepare("SELECT id FROM escalas_cualitativas WHERE id = ? AND id_curso = ?");
$stmt_escala->bind_param("ii", $id_escala, $id_curso);
$stmt_escala->execute();
$escala_valida = $stmt_escala->get_result()->num_rows === 1;
$stmt_escala->close();

if (!$escala_valida) {
    header("Location: dashboard_docente.php?error=" . urlencode("La escala seleccionada no pertenece a este curso."));
    exit();
}

// Obtener mapa de conceptos válidos para la escala
$conceptos_validos = [];
$stmt_conceptos = $conn->prepare("SELECT id FROM conceptos_cualitativos WHERE id_escala = ? AND activo = 1");
$stmt_conceptos->bind_param("i", $id_escala);
$stmt_conceptos->execute();
$result_conceptos = $stmt_conceptos->get_result();
while ($row = $result_conceptos->fetch_assoc()) {
    $conceptos_validos[$row['id']] = true;
}
$stmt_conceptos->close();

if (empty($conceptos_validos)) {
    header("Location: gestionar_conceptos.php?error=" . urlencode("No hay conceptos activos en la escala seleccionada."));
    exit();
}

// Validar que todas las selecciones correspondan a la escala
foreach ($conceptos_seleccionados as $id_criterio => $id_concepto) {
    if (!isset($conceptos_validos[$id_concepto])) {
        header("Location: dashboard_docente.php?error=" . urlencode("Se detectó un concepto inválido en la evaluación."));
        exit();
    }
}

$conn->begin_transaction();

try {
    $stmt_maestro = $conn->prepare("
        INSERT INTO evaluaciones_cualitativas (id_evaluador, id_equipo_evaluado, id_curso, id_escala, observaciones)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            id_escala = VALUES(id_escala),
            observaciones = VALUES(observaciones),
            fecha_evaluacion = CURRENT_TIMESTAMP
    ");
    $stmt_maestro->bind_param("iiiis", $id_evaluador, $id_equipo, $id_curso, $id_escala, $observaciones);
    $stmt_maestro->execute();

    $id_evaluacion = $conn->insert_id;

    if ($id_evaluacion === 0) {
        $stmt_fetch = $conn->prepare("SELECT id FROM evaluaciones_cualitativas WHERE id_evaluador = ? AND id_equipo_evaluado = ? AND id_curso = ?");
        $stmt_fetch->bind_param("iii", $id_evaluador, $id_equipo, $id_curso);
        $stmt_fetch->execute();
        $id_evaluacion = (int)$stmt_fetch->get_result()->fetch_assoc()['id'];
        $stmt_fetch->close();

        $stmt_delete = $conn->prepare("DELETE FROM evaluaciones_cualitativas_detalle WHERE id_evaluacion = ?");
        $stmt_delete->bind_param("i", $id_evaluacion);
        $stmt_delete->execute();
        $stmt_delete->close();
    }

    $stmt_detalle = $conn->prepare("
        INSERT INTO evaluaciones_cualitativas_detalle (id_evaluacion, id_criterio, id_concepto, qualitative_details)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($conceptos_seleccionados as $id_criterio => $id_concepto) {
        $id_criterio = (int)$id_criterio;
        $id_concepto = (int)$id_concepto;
        $detalle = isset($descripciones[$id_criterio]) ? htmlspecialchars(trim($descripciones[$id_criterio])) : null;
        $stmt_detalle->bind_param("iiis", $id_evaluacion, $id_criterio, $id_concepto, $detalle);
        $stmt_detalle->execute();
    }
    $stmt_detalle->close();

    $conn->commit();

    $log_stmt = $conn->prepare("INSERT INTO logs (id_usuario, accion, detalle, fecha) VALUES (?, 'EVAL_CUALITATIVA', ?, ?)");
    $detalle = "Registró evaluación cualitativa para equipo {$id_equipo}";
    $fecha_log = date('Y-m-d H:i:s');
    $log_stmt->bind_param("isss", $id_evaluador, $detalle, $fecha_log);
    $log_stmt->execute();
    $log_stmt->close();

    header("Location: dashboard_docente.php?status=" . urlencode("Evaluación cualitativa guardada correctamente."));
    exit();

} catch (Exception $e) {
    $conn->rollback();
    header("Location: dashboard_docente.php?error=" . urlencode("No se pudo guardar la evaluación cualitativa. " . $e->getMessage()));
    exit();
}

