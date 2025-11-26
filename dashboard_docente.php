<?php
require 'db.php';
require_once __DIR__ . '/invite_helpers.php';
// Requerir ser docente Y tener un curso activo
verificar_sesion(true);

// Función helper para limpiar nombres de invitados (remover prefijo "invitado")
function limpiar_nombre_invitado($nombre) {
    if (empty($nombre)) return $nombre;
    return preg_replace('/^invitado\s+/i', '', trim($nombre));
}

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;
$id_docente = $_SESSION['id_usuario'];

// Si no tiene curso activo, redirigir a select_course.php
if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

// 1. OBTENER INFORMACIÓN DEL CURSO ACTIVO (para mostrar el título)
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso_activo = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();

// 2. OBTENER TODOS LOS CURSOS DEL DOCENTE (para el selector en el navbar)
$sql_all_cursos = "
    SELECT c.id, c.nombre_curso, c.semestre, c.anio 
    FROM cursos c 
    JOIN docente_curso dc ON c.id = dc.id_curso 
    WHERE dc.id_docente = ? 
    ORDER BY c.anio DESC, c.semestre DESC";
$stmt_all_cursos = $conn->prepare($sql_all_cursos);
$stmt_all_cursos->bind_param("i", $id_docente);
$stmt_all_cursos->execute();
$all_cursos = $stmt_all_cursos->get_result();

// Función para calcular la evaluación docente ponderada
function obtener_puntaje_docente_ponderado($conn, $id_equipo, $id_curso) {
    $sql = "
        SELECT em.puntaje_total, dc.ponderacion
        FROM evaluaciones_maestro em
        JOIN usuarios u ON em.id_evaluador = u.id
        JOIN docente_curso dc ON u.id = dc.id_docente AND dc.id_curso = em.id_curso
        WHERE em.id_equipo_evaluado = ? AND em.id_curso = ? AND u.es_docente = TRUE
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id_equipo, $id_curso);
    $stmt->execute();
    $result = $stmt->get_result();

    $total_ponderado = 0;
    $total_ponderacion = 0;

    while ($row = $result->fetch_assoc()) {
        $total_ponderado += $row['puntaje_total'] * $row['ponderacion'];
        $total_ponderacion += $row['ponderacion'];
    }

    $stmt->close();

    if ($total_ponderacion > 0) {
        return $total_ponderado / $total_ponderacion;
    } else {
        return null; // No hay evaluaciones docentes
    }
}

// Función para calcular la evaluación de invitados (con o sin ponderación única)
function obtener_puntaje_invitados_ponderado($conn, $id_equipo, $id_curso) {
    // Obtener configuración del curso
    $stmt_config = $conn->prepare("SELECT usar_ponderacion_unica_invitados, ponderacion_unica_invitados FROM cursos WHERE id = ?");
    $stmt_config->bind_param("i", $id_curso);
    $stmt_config->execute();
    $config = $stmt_config->get_result()->fetch_assoc();
    $stmt_config->close();
    
    $usar_ponderacion_unica = isset($config['usar_ponderacion_unica_invitados']) ? (bool)$config['usar_ponderacion_unica_invitados'] : false;
    
    // Obtener todas las evaluaciones de invitados para este equipo
    $sql_invitados = "
        SELECT em.puntaje_total
        FROM evaluaciones_maestro em
        JOIN usuarios u ON em.id_evaluador = u.id
        WHERE em.id_equipo_evaluado = ? 
        AND em.id_curso = ? 
        AND u.es_docente = FALSE
        AND u.id_equipo IS NULL
        AND u.id_curso IS NULL
    ";
    $stmt_inv = $conn->prepare($sql_invitados);
    $stmt_inv->bind_param("ii", $id_equipo, $id_curso);
    $stmt_inv->execute();
    $result_inv = $stmt_inv->get_result();
    
    $evaluaciones_invitados = [];
    while ($row = $result_inv->fetch_assoc()) {
        $evaluaciones_invitados[] = $row['puntaje_total'];
    }
    $stmt_inv->close();
    
    if (empty($evaluaciones_invitados)) {
        return null; // No hay evaluaciones de invitados
    }
    
    if ($usar_ponderacion_unica) {
        // Si usa ponderación única, simplemente promediar todas las evaluaciones
        $promedio = array_sum($evaluaciones_invitados) / count($evaluaciones_invitados);
        return $promedio;
    } else {
        // Si usa ponderaciones individuales, calcular ponderado
        $sql_ponderadas = "
            SELECT em.puntaje_total, ic.ponderacion
            FROM evaluaciones_maestro em
            JOIN usuarios u ON em.id_evaluador = u.id
            JOIN invitado_curso ic ON u.id = ic.id_invitado AND ic.id_curso = em.id_curso
            WHERE em.id_equipo_evaluado = ? AND em.id_curso = ?
        ";
        $stmt_pond = $conn->prepare($sql_ponderadas);
        $stmt_pond->bind_param("ii", $id_equipo, $id_curso);
        $stmt_pond->execute();
        $result_pond = $stmt_pond->get_result();
        
        $total_ponderado = 0;
        $total_ponderacion = 0;
        
        while ($row = $result_pond->fetch_assoc()) {
            $total_ponderado += $row['puntaje_total'] * $row['ponderacion'];
            $total_ponderacion += $row['ponderacion'];
        }
        $stmt_pond->close();
        
        if ($total_ponderacion > 0) {
            return $total_ponderado / $total_ponderacion;
        } else {
            return null;
        }
    }
}

// 3. OBTENER INFORMACIÓN DE LAS EVALUACIONES DEL CURSO ACTIVO
$sql_evaluaciones = "
    SELECT
        ev.id,
        ev.nombre_evaluacion,
        ev.tipo_evaluacion,
        ev.estado,
        ev.fecha_creacion
    FROM evaluaciones ev
    WHERE ev.id_curso = ?
    ORDER BY ev.fecha_creacion DESC
";
$stmt_evaluaciones = $conn->prepare($sql_evaluaciones);
$stmt_evaluaciones->bind_param("i", $id_curso_activo);
$stmt_evaluaciones->execute();
$evaluaciones = $stmt_evaluaciones->get_result();

// Verificar si hay alguna evaluación iniciada o cerrada para habilitar botones
$tiene_evaluacion_activa = false;
if ($evaluaciones->num_rows > 0) {
    // Resetear el puntero del resultado para poder iterarlo de nuevo
    $evaluaciones->data_seek(0);
    while ($eval = $evaluaciones->fetch_assoc()) {
        if ($eval['estado'] == 'iniciada' || $eval['estado'] == 'cerrada') {
            $tiene_evaluacion_activa = true;
            break;
        }
    }
    // Resetear el puntero nuevamente para usarlo en la tabla
    $evaluaciones->data_seek(0);
}

// Obtener la evaluación seleccionada actualmente (guardada en sesión)
$id_evaluacion_seleccionada = isset($_SESSION['id_evaluacion_seleccionada']) ? (int)$_SESSION['id_evaluacion_seleccionada'] : null;

// Verificar que la evaluación seleccionada pertenezca al curso activo
if ($id_evaluacion_seleccionada) {
    $stmt_check_seleccionada = $conn->prepare("SELECT id FROM evaluaciones WHERE id = ? AND id_curso = ?");
    $stmt_check_seleccionada->bind_param("ii", $id_evaluacion_seleccionada, $id_curso_activo);
    $stmt_check_seleccionada->execute();
    if ($stmt_check_seleccionada->get_result()->num_rows === 0) {
        // La evaluación seleccionada no pertenece a este curso, limpiar la selección
        unset($_SESSION['id_evaluacion_seleccionada']);
        $id_evaluacion_seleccionada = null;
    }
    $stmt_check_seleccionada->close();
}

// Verificar si hay una evaluación seleccionada (esto es lo que realmente habilita los botones)
$tiene_evaluacion_seleccionada = ($id_evaluacion_seleccionada !== null);

$qualitative_feed = get_course_qualitative_feed($conn, $id_curso_activo, 6);

// Invitados creados (usuarios sin curso ni equipo)
$invitados_registrados = [];
$sql_invitados = "
    SELECT id, nombre, email
    FROM usuarios
    WHERE es_docente = 0
      AND id_equipo IS NULL
      AND id_curso IS NULL
    ORDER BY id DESC
";
if ($resultado_invitados = $conn->query($sql_invitados)) {
    while ($fila = $resultado_invitados->fetch_assoc()) {
        $invitados_registrados[] = $fila;
    }
    $resultado_invitados->free();
}
$credenciales_invitados = load_invite_credentials();

// 4. FUNCIÓN PARA CALCULAR LA NOTA FINAL PONDERADA Y LA NOTA EN ESCALA 1-7
// Escala Chilena (asumiendo puntaje max. 100)
function calcular_nota_final($puntaje) {
    if ($puntaje === null) return "N/A";
    
    // Fórmula: 1.0 + (Puntaje / 100) * 6.0
    $nota = 1.0 + ($puntaje / 100) * 6.0;
    
    if ($nota < 1.0) $nota = 1.0;
    if ($nota > 7.0) $nota = 7.0;
    
    return number_format($nota, 1, '.', ''); // Formato x.x
}

// Mensajes de estado y error
$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
$invite_success = isset($_GET['invite_success']) ? true : false;
$invite_error = isset($_GET['invite_error']) ? htmlspecialchars($_GET['invite_error']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Docente - Coevaluación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="public/assets/css/dashboard_buttons.css">
</head>
<body style="padding-bottom: 120px;">
    <?php include 'header.php'; ?>

    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>
                Curso Activo: <?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre'] . '-' . $curso_activo['anio']); ?>
            </h1>
<div class="dashboard-buttons d-flex flex-wrap justify-content-end">
                <!-- // NUEVO: unificación de estilo sin cambiar colores -->
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#inviteModal">
                    Agregar invitado
                </button>
                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#resetModal">
                    Resetear Plataforma
                </button>
                <a href="gestionar_estudiantes_equipos.php" class="btn btn-primary">
                    Estudiantes y Equipos
                </a>
                <a href="historial.php" class="btn btn-info">
                    Historial
                </a>
                <a href="dashboard_privado.php" class="btn btn-dark">Vista privada</a>
            </div>
        </div>

        <?php if ($status_message): ?>
            <div class="alert alert-success"><?php echo $status_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if ($invite_error): ?>
            <div class="alert alert-danger"><?php echo $invite_error; ?></div>
        <?php endif; ?>

        <?php
        // Mostrar alertas de actualizaciones si existe el archivo update_alerts.log
        $alerts_file = __DIR__ . '/tools/update_alerts.log';
        if (file_exists($alerts_file)) {
            $alerts = file($alerts_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!empty($alerts)) {
                echo '<div class="alert alert-warning alert-dismissible fade show" role="alert" style="border-left: 4px solid #ffc107; background-color: #fff3cd; color: #856404;">';
                echo '<strong>⚠️ Alertas de Actualizaciones:</strong><br>';
                echo '<ul class="mb-0">';
                foreach ($alerts as $alert) {
                    // Remover timestamp para mostrar solo el mensaje
                    $message = preg_replace('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] /', '', $alert);
                    echo '<li>' . htmlspecialchars($message) . '</li>';
                }
                echo '</ul>';
                echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                echo '</div>';
            }
        }
        ?>

        <div class="modal fade" id="resetModal" tabindex="-1" aria-labelledby="resetModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="resetModalLabel">Confirmar Reseteo de Plataforma</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-danger fw-bold">ADVERTENCIA: Esta acción es irreversible.</p>
                        <p>Al confirmar, se eliminarán todos los datos (equipos, estudiantes, evaluaciones, criterios) asociados al **curso activo**.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <form action="admin_actions.php" method="POST" class="d-inline">
                            <input type="hidden" name="action" value="reset_all">
                            <input type="hidden" name="confirm" value="yes">
                            <button type="submit" class="btn btn-danger">Confirmar Reseteo Total</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card shadow h-100">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Importar Estudiantes por ID (CSV/Excel)</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $zipDisponible = extension_loaded('zip') && class_exists('ZipArchive');
                        ?>
                        <p><strong>Formato requerido:</strong> ID, Nombre, Email</p>
                        <p class="small text-muted">Soporta archivos <strong>CSV (.csv)</strong><?php echo $zipDisponible ? ' y <strong>Excel (.xlsx)</strong>' : ''; ?>. Los estudiantes se asociarán automáticamente al curso activo.</p>
                        <?php if (!$zipDisponible): ?>
                            <div class="alert alert-warning mb-3">
                                <strong>⚠️ Nota:</strong> Los archivos Excel (.xlsx) no están disponibles porque la extensión ZipArchive no está habilitada. 
                                Por favor, use archivos <strong>CSV</strong> o <a href="verificar_zip.php" target="_blank">habilite ZipArchive</a>.
                            </div>
                        <?php endif; ?>
                        <form action="import_students.php" method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="lista_estudiantes_id" class="form-label">Selecciona archivo CSV<?php echo $zipDisponible ? ' o Excel' : ''; ?></label>
                                <input class="form-control" type="file" id="lista_estudiantes_id" name="lista_estudiantes" accept="<?php echo $zipDisponible ? '.csv,.xlsx' : '.csv'; ?>" required>
                            </div>
                            <button type="submit" class="btn btn-success w-100 fw-bold">Importar Estudiantes</button>
                        </form>
                        <hr>
                        <p class="text-muted small"><strong>Ejemplo (CSV):</strong></p>
                        <pre class="bg-light p-2 small">ID,Nombre,Email
20201234,Juan Pérez,jperez@alu.uct.cl
20204567,Ana Gómez,agomez@alu.uct.cl</pre>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Gestión de Estudiantes y Equipos</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $zipDisponible = extension_loaded('zip') && class_exists('ZipArchive');
                        ?>
                        <p>Sube un archivo <strong>CSV</strong><?php echo $zipDisponible ? ' o <strong>Excel (.xlsx)</strong>' : ''; ?> con la lista de estudiantes y su equipo.</p>
                        <?php if (!$zipDisponible): ?>
                            <div class="alert alert-warning mb-3">
                                <strong>⚠️ Nota:</strong> Los archivos Excel (.xlsx) no están disponibles porque la extensión ZipArchive no está habilitada. 
                                Por favor, use archivos <strong>CSV</strong> o <a href="verificar_zip.php" target="_blank">habilite ZipArchive</a>.
                            </div>
                        <?php endif; ?>
                        <form action="upload.php" method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="lista_estudiantes" class="form-label">Archivo CSV<?php echo $zipDisponible ? ' o Excel' : ''; ?> (Nombre, Email, Equipo)</label>
                                <input class="form-control" type="file" id="lista_estudiantes" name="lista_estudiantes" accept="<?php echo $zipDisponible ? '.csv,.xlsx' : '.csv'; ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Subir y Procesar Lista</button>
                        </form>
                        <hr>
                        <p class="text-muted small">
                            Orden de columnas esperado: <strong>Nombre, Correo institucional y Equipo</strong>. Ejemplo:
                            <br><code>Juan Perez,jperez@alu.uct.cl,Los Vengadores</code>
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                 <div class="card shadow h-100">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Resultados y Exportar</h5>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-between">
                        <div>
                            <p>Exporta los resultados finales (promedio coevaluación de estudiantes y nota del docente) a un archivo CSV para el cálculo de notas.</p>
                            <p class="text-muted small">
                                *El cálculo usa una ponderación **50% estudiantes** y **50% docente**.
                            </p>
                        </div>
                        <a href="export_results.php" class="btn btn-success btn-lg mt-3">Exportar Resultados Finales</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Exportar Evaluaciones y Rúbricas</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <h6>Exportar Evaluaciones</h6>
                                <p class="text-muted small">Exporta evaluaciones completas con puntajes, notas y comentarios.</p>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalExportarEvaluaciones" data-export-type="pdf">
                                    📄 Exportar Evaluaciones (PDF)
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modalExportarEvaluaciones" data-export-type="csv">
                                    📋 Exportar Evaluaciones (CSV)
                                </button>
                            </div>
                            <div class="col-md-6 mb-3">
                                <h6>Exportar Rúbricas</h6>
                                <p class="text-muted small">Exporta la rúbrica de evaluación actual (criterios, opciones y descripciones).</p>
                                <a href="exportar_rubrica_pdf.php?id_curso=<?php echo $id_curso_activo; ?>" class="btn btn-danger" target="_blank">
                                    📄 Exportar Rúbrica (PDF)
                                </a>
                                <a href="exportar_rubrica_csv.php?id_curso=<?php echo $id_curso_activo; ?>" class="btn btn-secondary">
                                    📋 Exportar Rúbrica (CSV)
                                </a>
                                <a href="exportar_rubrica.php?id_curso=<?php echo $id_curso_activo; ?>" class="btn btn-primary">
                                    📊 Exportar Rúbrica (Excel)
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="mb-0">Evaluaciones del Curso</h2>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearEvaluacion">
                    Crear Evaluación
                </button>
                <button type="button" class="btn btn-success <?php echo !$tiene_evaluacion_seleccionada ? 'disabled' : ''; ?>" 
                        data-bs-toggle="modal" data-bs-target="#docentesModal"
                        <?php echo !$tiene_evaluacion_seleccionada ? 'disabled title="Debes seleccionar una evaluación iniciada o cerrada"' : ''; ?>>
                    Docentes y ponderaciones
                </button>
                <a href="gestionar_criterios.php" class="btn btn-info <?php echo !$tiene_evaluacion_seleccionada ? 'disabled' : ''; ?>"
                   <?php echo !$tiene_evaluacion_seleccionada ? 'tabindex="-1" aria-disabled="true" onclick="return false;" title="Debes seleccionar una evaluación iniciada o cerrada"' : ''; ?>>
                    Criterios y Escala de Notas
                </a>
                <a href="gestionar_conceptos.php" class="btn btn-secondary">
                    Conceptos Cualitativos
                </a>
            </div>
        </div>
        
        <?php if ($tiene_evaluacion_seleccionada): ?>
            <?php
                // Obtener nombre de la evaluación seleccionada
                $stmt_nombre_seleccionada = $conn->prepare("SELECT nombre_evaluacion FROM evaluaciones WHERE id = ?");
                $stmt_nombre_seleccionada->bind_param("i", $id_evaluacion_seleccionada);
                $stmt_nombre_seleccionada->execute();
                $eval_seleccionada = $stmt_nombre_seleccionada->get_result()->fetch_assoc();
                $stmt_nombre_seleccionada->close();
            ?>
            <div class="alert alert-success mb-3">
                <strong>✓ Evaluación activa:</strong> <?php echo htmlspecialchars($eval_seleccionada['nombre_evaluacion']); ?>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-3">
                <strong>⚠ Atención:</strong> No hay evaluación seleccionada. Selecciona una evaluación iniciada o cerrada para habilitar las opciones de ponderaciones y criterios.
            </div>
        <?php endif; ?>
        
        <div class="table-responsive">
            <table class="table table-striped table-hover shadow-sm">
                <thead class="table-dark">
                    <tr>
                        <th>Evaluación</th>
                        <th class="text-center">Tipo</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center">Fecha Creación</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
            <tbody>
                <?php if ($evaluaciones->num_rows > 0): ?>
                    <?php while($evaluacion = $evaluaciones->fetch_assoc()): ?>
                    <?php 
                        $es_seleccionada = ($id_evaluacion_seleccionada && $evaluacion['id'] == $id_evaluacion_seleccionada);
                        $clase_fila = $es_seleccionada ? 'table-primary fw-bold' : '';
                        $es_clickeable = ($evaluacion['estado'] == 'iniciada' || $evaluacion['estado'] == 'cerrada');
                        $cursor_style = $es_clickeable ? 'cursor-pointer' : '';
                    ?>
                    <tr class="<?php echo $clase_fila . ' ' . $cursor_style; ?>" 
                        id="evaluacion_<?php echo $evaluacion['id']; ?>"
                        <?php if ($es_clickeable): ?>
                            onclick="seleccionarEvaluacion(<?php echo $evaluacion['id']; ?>)"
                            style="cursor: pointer;"
                        <?php endif; ?>
                        >
                        <td>
                            <strong><?php echo htmlspecialchars($evaluacion['nombre_evaluacion']); ?></strong>
                            <?php if ($es_seleccionada): ?>
                                <span class="badge bg-success ms-2">✓ Seleccionada</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($evaluacion['tipo_evaluacion'] == 'grupal'): ?>
                                <span class="badge bg-primary">Grupal</span>
                            <?php else: ?>
                                <span class="badge bg-info">Individual</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php 
                                if ($evaluacion['estado'] == 'iniciada') {
                                    echo '<span class="badge bg-success">Iniciada</span>';
                                } elseif ($evaluacion['estado'] == 'cerrada') {
                                    echo '<span class="badge bg-secondary">Cerrada</span>';
                                } else {
                                    echo '<span class="badge bg-warning text-dark">Pendiente</span>';
                                }
                            ?>
                        </td>
                        <td class="text-center">
                            <?php echo date("d/m/Y H:i", strtotime($evaluacion['fecha_creacion'])); ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm" role="group" onclick="event.stopPropagation();">
                                <?php if ($evaluacion['estado'] == 'pendiente'): ?>
                                    <button type="button" class="btn btn-warning btn-sm" 
                                            onclick="abrirModalEditarEvaluacion(<?php echo $evaluacion['id']; ?>, '<?php echo htmlspecialchars($evaluacion['nombre_evaluacion'], ENT_QUOTES); ?>', '<?php echo $evaluacion['tipo_evaluacion']; ?>')">
                                        Editar
                                    </button>
                                    <form action="evaluaciones_actions.php" method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id_evaluacion" value="<?php echo $evaluacion['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" 
                                                onclick="return confirm('¿Estás seguro de eliminar esta evaluación?')">
                                            Eliminar
                                        </button>
                                    </form>
                                    <form action="evaluaciones_actions.php" method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="iniciar">
                                        <input type="hidden" name="id_evaluacion" value="<?php echo $evaluacion['id']; ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Iniciar</button>
                                    </form>
                                <?php elseif ($evaluacion['estado'] == 'iniciada'): ?>
                                    <a href="ver_evaluacion.php?id=<?php echo $evaluacion['id']; ?>" class="btn btn-primary btn-sm">Ver Evaluación</a>
                                    <form action="evaluaciones_actions.php" method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="cerrar">
                                        <input type="hidden" name="id_evaluacion" value="<?php echo $evaluacion['id']; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" 
                                                onclick="return confirm('¿Estás seguro de cerrar esta evaluación?')">
                                            Cerrar
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a href="ver_evaluacion.php?id=<?php echo $evaluacion['id']; ?>" class="btn btn-info btn-sm">Ver Resultados</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center">No hay evaluaciones creadas. Crea una para comenzar.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="mt-5">
            <h3>Últimas evaluaciones cualitativas</h3>
            <?php if (!empty($qualitative_feed)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Equipo</th>
                                <th>Resumen</th>
                                <th>Observaciones</th>
                                <th>Fecha</th>
                                <th class="text-center">Privacidad</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($qualitative_feed as $feed): ?>
                                <?php
                                    $obs = $feed['observaciones'] ?? '';
                                    $obs_display = $obs ? mb_strimwidth($obs, 0, 120, '…', 'UTF-8') : 'Sin comentarios';
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($feed['nombre_equipo']); ?></strong></td>
                                    <td>
                                        <span class="badge bg-info text-dark"><?php echo (int)$feed['total_items']; ?> criterios</span>
                                    </td>
                                    <td><?php echo htmlspecialchars($obs_display); ?></td>
                                    <td><?php echo date("d/m/Y H:i", strtotime($feed['fecha_evaluacion'])); ?></td>
                                    <td class="text-center">
                                        <div class="small text-muted">
                                            Evaluador:
                                            <span class="evaluador-obfus fw-bold" data-name="<?php echo htmlspecialchars(limpiar_nombre_invitado($feed['nombre_evaluador'])); ?>">•••••••</span>
                                        </div>
                                        <button type="button" class="btn btn-link btn-sm toggle-evaluador" data-target-name="<?php echo htmlspecialchars(limpiar_nombre_invitado($feed['nombre_evaluador'])); ?>">
                                            Mostrar
                                        </button>
                                    </td>
                                    <td class="text-center">
                                        <a href="ver_detalles.php?id=<?php echo $feed['id_equipo_evaluado']; ?>" class="btn btn-sm btn-outline-primary">Ver equipo</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-light border">Aún no hay evaluaciones cualitativas registradas en este curso.</div>
            <?php endif; ?>
        </div>

        <div style="height: 120px;"></div>

    </div>

    <!-- Modal: Crear nuevo evaluador -->
    <div class="modal fade" id="inviteModal" tabindex="-1" aria-labelledby="inviteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="inviteModalLabel">Crear nuevo evaluador</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="create_evaluator.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="tipo_evaluador" value="invitado">
                        <div class="alert alert-info py-2">
                            <strong>Rol invitado activo.</strong> Se generará un acceso temporal para el correo indicado.
                        </div>
                        <div class="mb-3">
                            <label for="email_evaluador" class="form-label">Correo electrónico</label>
                            <input type="email" class="form-control" id="email_evaluador" name="email" required placeholder="correo@dominio.cl">
                        </div>
                        <div class="mb-3">
                            <label for="password_evaluador" class="form-label">Contraseña</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="password_evaluador" name="password" required minlength="6" placeholder="Ingrese o genere una contraseña">
                                <button class="btn btn-outline-secondary" type="button" id="btn-generar-password">Generar automáticamente</button>
                            </div>
                            <div class="form-text">La contraseña debe tener al menos 6 caracteres.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Crear</button>
                    </div>
                </form>
                <div class="modal-body border-top">
                    <h6 class="mb-3">Invitados creados</h6>
                    <?php if (count($invitados_registrados) === 0): ?>
                        <div class="alert alert-light border">Aún no hay invitados registrados.</div>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 240px; overflow-y: auto;">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 45%;">Usuario</th>
                                        <th style="width: 40%;">Contraseña</th>
                                        <th class="text-center" style="width: 15%;">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($invitados_registrados as $invitado): ?>
                                    <?php $form_id = 'invite-update-' . $invitado['id']; ?>
                                    <tr>
                                        <?php
                                            $credencial = $credenciales_invitados[$invitado['id']] ?? [];
                                            $password_visible = $credencial['password'] ?? '';
                                        ?>
                                        <td>
                                            <strong><?php echo htmlspecialchars($invitado['email']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars(limpiar_nombre_invitado($invitado['nombre'])); ?></small>
                                            <input type="hidden"
                                                   name="nombre"
                                                   form="<?php echo $form_id; ?>"
                                                   value="<?php echo htmlspecialchars($invitado['nombre']); ?>">
                                            <input type="hidden"
                                                   name="username"
                                                   form="<?php echo $form_id; ?>"
                                                   value="<?php echo htmlspecialchars($invitado['email']); ?>">
                                        </td>
                                        <td>
                                            <input type="text"
                                                   name="password"
                                                   form="<?php echo $form_id; ?>"
                                                   class="form-control form-control-sm"
                                                   value="<?php echo htmlspecialchars($password_visible); ?>"
                                                   placeholder="Contraseña"
                                                   minlength="6">
                                        </td>
                                        <td class="text-center">
                                            <form id="<?php echo $form_id; ?>" action="invite_actions.php" method="POST" class="d-inline invite-action-form" data-action="update" target="inviteActionsFrame">
                                                <input type="hidden" name="action" value="update_invite">
                                                <input type="hidden" name="id_usuario" value="<?php echo (int)$invitado['id']; ?>">
                                            </form>
                                            <button type="submit" form="<?php echo $form_id; ?>" class="btn btn-sm btn-outline-primary mb-2">Actualizar</button>
                                            
                                            <form action="invite_actions.php" method="POST" class="d-inline invite-action-form" data-action="delete" target="inviteActionsFrame">
                                                <input type="hidden" name="action" value="delete_invite">
                                                <input type="hidden" name="id_usuario" value="<?php echo (int)$invitado['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <iframe name="inviteActionsFrame" style="display:none;"></iframe>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Éxito en creación -->
    <div class="modal fade" id="inviteSuccessModal" tabindex="-1" aria-labelledby="inviteSuccessModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="inviteSuccessModalLabel">Operación exitosa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <p class="lead mb-0">Perfil creado y credenciales enviadas de manera correcta.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal">Aceptar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmación de Eliminación -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="confirmDeleteModalLabel">Confirmar eliminación</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-danger fw-bold">ADVERTENCIA: Esta acción es irreversible.</p>
                    <p>¿Estás seguro de que quieres eliminar este elemento? No se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="form-delete" class="btn btn-danger fw-bold">Eliminar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulario oculto para eliminación -->
    <form id="form-delete" method="POST" style="display: none;">
        <input type="hidden" name="action" id="delete-action">
        <input type="hidden" name="id" id="delete-id">
        <input type="hidden" name="confirm" value="yes">
    </form>

    <!-- Modal: Crear Evaluación -->
    <div class="modal fade" id="modalCrearEvaluacion" tabindex="-1" aria-labelledby="modalCrearEvaluacionLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearEvaluacionLabel">Crear Nueva Evaluación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="evaluaciones_actions.php" method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nombre_evaluacion" class="form-label">Nombre de la Evaluación</label>
                            <input type="text" class="form-control" id="nombre_evaluacion" name="nombre_evaluacion" required>
                        </div>
                        <div class="mb-3">
                            <label for="tipo_evaluacion" class="form-label">Tipo de Evaluación</label>
                            <select class="form-select" id="tipo_evaluacion" name="tipo_evaluacion" required>
                                <option value="">Seleccione un tipo</option>
                                <option value="grupal">Grupal</option>
                                <option value="individual">Individual</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Crear Evaluación</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Editar Evaluación -->
    <div class="modal fade" id="modalEditarEvaluacion" tabindex="-1" aria-labelledby="modalEditarEvaluacionLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarEvaluacionLabel">Editar Evaluación</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="evaluaciones_actions.php" method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id_evaluacion" id="edit_id_evaluacion">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nombre_evaluacion_edit" class="form-label">Nombre de la Evaluación</label>
                            <input type="text" class="form-control" id="nombre_evaluacion_edit" name="nombre_evaluacion" required>
                        </div>
                        <div class="mb-3">
                            <label for="tipo_evaluacion_edit" class="form-label">Tipo de Evaluación</label>
                            <select class="form-select" id="tipo_evaluacion_edit" name="tipo_evaluacion" required>
                                <option value="grupal">Grupal</option>
                                <option value="individual">Individual</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-warning">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Exportar Evaluaciones -->
    <div class="modal fade" id="modalExportarEvaluaciones" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Exportar Evaluaciones</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="formExportarEvaluaciones" method="GET">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="id_curso" class="form-label">Seleccionar Curso</label>
                            <select class="form-select" name="id_curso" id="id_curso" required>
                                <?php
                                $all_cursos->data_seek(0); // Reset pointer
                                while($curso = $all_cursos->fetch_assoc()): ?>
                                    <option value="<?php echo $curso['id']; ?>" <?php echo ($curso['id'] == $id_curso_activo) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($curso['nombre_curso'] . ' ' . $curso['semestre'] . '-' . $curso['anio']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="filtro_equipo" class="form-label">Filtrar por Equipo (opcional)</label>
                            <select class="form-select" name="id_equipo" id="filtro_equipo">
                                <option value="">Todos los equipos/estudiantes</option>
                                <?php
                                // Obtener equipos del curso activo (para mostrar opciones iniciales)
                                $stmt_equipos = $conn->prepare("SELECT id, nombre_equipo FROM equipos WHERE id_curso = ? ORDER BY nombre_equipo ASC");
                                $stmt_equipos->bind_param("i", $id_curso_activo);
                                $stmt_equipos->execute();
                                $equipos_result = $stmt_equipos->get_result();
                                while ($equipo = $equipos_result->fetch_assoc()) {
                                    echo '<option value="' . $equipo['id'] . '">' . htmlspecialchars($equipo['nombre_equipo']) . '</option>';
                                }
                                $stmt_equipos->close();

                                // Obtener estudiantes individuales (sin equipo)
                                $stmt_estudiantes = $conn->prepare("
                                    SELECT DISTINCT u.id, u.nombre, u.email
                                    FROM usuarios u
                                    JOIN evaluaciones_maestro em ON em.id_equipo_evaluado = u.id
                                    WHERE u.es_docente = 0
                                    AND u.id_equipo IS NULL
                                    AND em.id_curso = ?
                                    ORDER BY u.nombre ASC
                                ");
                                $stmt_estudiantes->bind_param("i", $id_curso_activo);
                                $stmt_estudiantes->execute();
                                $estudiantes_result = $stmt_estudiantes->get_result();
                                while ($estudiante = $estudiantes_result->fetch_assoc()) {
                                    echo '<option value="' . $estudiante['id'] . '">' . htmlspecialchars($estudiante['nombre'] . ' (' . $estudiante['email'] . ')') . '</option>';
                                }
                                $stmt_estudiantes->close();
                                ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="fecha_desde" class="form-label">Fecha Desde (opcional)</label>
                                <input type="date" class="form-control" name="fecha_desde" id="fecha_desde">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="fecha_hasta" class="form-label">Fecha Hasta (opcional)</label>
                                <input type="date" class="form-control" name="fecha_hasta" id="fecha_hasta">
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <small>Selecciona un curso obligatorio. Los filtros de equipo y fechas son opcionales. Si no seleccionas ningún filtro adicional, se exportarán todas las evaluaciones del curso seleccionado.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger" id="btnExportarPDF" formaction="exportar_evaluaciones_pdf.php" target="_blank">
                            Exportar PDF
                        </button>
                        <button type="submit" class="btn btn-secondary" id="btnExportarCSV" formaction="exportar_evaluaciones_csv.php">
                            Exportar CSV
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Docentes y ponderaciones -->
    <div class="modal fade" id="docentesModal" tabindex="-1" aria-labelledby="docentesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="docentesModalLabel">Ponderaciones de Evaluadores</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="admin_actions.php" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_docente_weights">
                        
                        <!-- Ponderación de Estudiantes -->
                        <div class="mb-4">
                            <h6>Ponderación de Evaluaciones de Estudiantes</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="ponderacion_estudiantes" class="form-label">Ponderación de Estudiantes (%)</label>
                                    <input type="number" class="form-control ponderacion-input" id="ponderacion_estudiantes" 
                                           name="ponderacion_estudiantes" 
                                           value="<?php 
                                               $stmt_pond_est = $conn->prepare("SELECT ponderacion_estudiantes FROM cursos WHERE id = ?");
                                               $stmt_pond_est->bind_param("i", $id_curso_activo);
                                               $stmt_pond_est->execute();
                                               $result_pond_est = $stmt_pond_est->get_result();
                                               $pond_est = $result_pond_est->fetch_assoc();
                                               echo $pond_est ? number_format($pond_est['ponderacion_estudiantes'] ?? 0, 0) : 0;
                                               $stmt_pond_est->close();
                                           ?>" 
                                           min="0" max="100" step="1" required>
                                    <small class="text-muted">Ponderación para el promedio de todas las evaluaciones de estudiantes.</small>
                                </div>
                            </div>
                        </div>
                        <hr>
                        
                        <!-- Docentes -->
                        <h6>Docentes del curso</h6>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Email</th>
                                    <th>Ponderación (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $sql_docentes_actuales = "
                                    SELECT u.id, u.nombre, u.email, dc.ponderacion
                                    FROM docente_curso dc
                                    JOIN usuarios u ON dc.id_docente = u.id
                                    WHERE dc.id_curso = ?
                                    ORDER BY u.nombre ASC
                                ";
                                $stmt_docentes_actuales = $conn->prepare($sql_docentes_actuales);
                                $stmt_docentes_actuales->bind_param("i", $id_curso_activo);
                                $stmt_docentes_actuales->execute();
                                $docentes_actuales = $stmt_docentes_actuales->get_result();
                                while ($docente = $docentes_actuales->fetch_assoc()) {
                                    $ponderacion_porcentaje = $docente['ponderacion'] * 100;
                                    echo "<tr>
                                        <td>" . htmlspecialchars($docente['nombre']) . "</td>
                                        <td>" . htmlspecialchars($docente['email']) . "</td>
                                        <td>
                                            <input type='number' class='form-control form-control-sm ponderacion-input' name='ponderaciones_docentes[" . $docente['id'] . "]' value='" . number_format($ponderacion_porcentaje, 0) . "' min='0' max='100' step='1'>
                                        </td>
                                    </tr>";
                                }
                                $stmt_docentes_actuales->close();
                                ?>
                            </tbody>
                        </table>
                        <hr>
                        
                        <!-- Invitados (se agregan automáticamente) -->
                        <h6>Invitados del curso</h6>
                        <?php
                        // Obtener configuración de ponderación única de invitados
                        $stmt_config_inv = $conn->prepare("SELECT usar_ponderacion_unica_invitados, ponderacion_unica_invitados FROM cursos WHERE id = ?");
                        $stmt_config_inv->bind_param("i", $id_curso_activo);
                        $stmt_config_inv->execute();
                        $config_inv = $stmt_config_inv->get_result()->fetch_assoc();
                        $usar_ponderacion_unica = isset($config_inv['usar_ponderacion_unica_invitados']) ? (bool)$config_inv['usar_ponderacion_unica_invitados'] : false;
                        $ponderacion_unica_valor = isset($config_inv['ponderacion_unica_invitados']) ? $config_inv['ponderacion_unica_invitados'] : 0;
                        $stmt_config_inv->close();
                        ?>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="usar_ponderacion_unica_invitados" 
                                       name="usar_ponderacion_unica_invitados" value="1" 
                                       <?php echo $usar_ponderacion_unica ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="usar_ponderacion_unica_invitados">
                                    <strong>Ponderación única</strong>
                                </label>
                            </div>
                            <small class="text-muted d-block ms-4">Si se marca, se promediarán todas las evaluaciones de invitados y se aplicará un único porcentaje al promedio.</small>
                        </div>
                        
                        <div id="ponderacion_unica_container" class="mb-3" style="display: <?php echo $usar_ponderacion_unica ? 'block' : 'none'; ?>;">
                            <label for="ponderacion_unica_invitados" class="form-label">Ponderación única de invitados (%)</label>
                            <input type="number" class="form-control ponderacion-input" id="ponderacion_unica_invitados" 
                                   name="ponderacion_unica_invitados" 
                                   value="<?php echo number_format($ponderacion_unica_valor, 0); ?>" 
                                   min="0" max="100" step="1">
                            <small class="text-muted">Este porcentaje se aplicará al promedio de todas las evaluaciones de invitados.</small>
                        </div>
                        
                        <div id="ponderaciones_individuales_invitados" style="display: <?php echo $usar_ponderacion_unica ? 'none' : 'block'; ?>;">
                            <?php
                            // Obtener todos los invitados (es_docente=0, id_equipo IS NULL, id_curso IS NULL)
                            $sql_invitados_todos = "
                                SELECT u.id, u.nombre, u.email
                                FROM usuarios u
                                WHERE u.es_docente = 0
                                AND u.id_equipo IS NULL
                                AND u.id_curso IS NULL
                                ORDER BY u.nombre ASC
                            ";
                            $result_invitados_todos = $conn->query($sql_invitados_todos);
                            
                            // Obtener invitados ya asociados al curso
                            $sql_invitados_curso = "
                                SELECT ic.id_invitado, ic.ponderacion, u.nombre, u.email
                                FROM invitado_curso ic
                                JOIN usuarios u ON ic.id_invitado = u.id
                                WHERE ic.id_curso = ?
                                ORDER BY u.nombre ASC
                            ";
                            $stmt_invitados_curso = $conn->prepare($sql_invitados_curso);
                            $stmt_invitados_curso->bind_param("i", $id_curso_activo);
                            $stmt_invitados_curso->execute();
                            $invitados_curso = $stmt_invitados_curso->get_result();
                            $invitados_asociados = [];
                            while ($inv = $invitados_curso->fetch_assoc()) {
                                $invitados_asociados[$inv['id_invitado']] = $inv;
                            }
                            $stmt_invitados_curso->close();
                            
                            // Auto-agregar invitados que no estén en el curso
                            if ($result_invitados_todos->num_rows > 0) {
                                echo "<table class='table table-sm'>";
                                echo "<thead><tr><th>Nombre</th><th>Email</th><th>Ponderación (%)</th></tr></thead>";
                                echo "<tbody>";
                                while ($invitado = $result_invitados_todos->fetch_assoc()) {
                                    $id_inv = $invitado['id'];
                                    $ponderacion_inv = isset($invitados_asociados[$id_inv]) ? $invitados_asociados[$id_inv]['ponderacion'] * 100 : 0;
                                    
                                    // Si no está asociado, agregarlo automáticamente
                                    if (!isset($invitados_asociados[$id_inv])) {
                                        $stmt_insert_inv = $conn->prepare("INSERT INTO invitado_curso (id_invitado, id_curso, ponderacion) VALUES (?, ?, 0.00)");
                                        $stmt_insert_inv->bind_param("ii", $id_inv, $id_curso_activo);
                                        $stmt_insert_inv->execute();
                                        $stmt_insert_inv->close();
                                    }
                                    
                                    echo "<tr>
                                        <td>" . htmlspecialchars(limpiar_nombre_invitado($invitado['nombre'])) . "</td>
                                        <td>" . htmlspecialchars($invitado['email']) . "</td>
                                        <td>
                                            <input type='number' class='form-control form-control-sm ponderacion-input' name='ponderaciones_invitados[" . $id_inv . "]' value='" . number_format($ponderacion_inv, 0) . "' min='0' max='100' step='1'>
                                        </td>
                                    </tr>";
                                }
                                echo "</tbody></table>";
                            } else {
                                echo "<p class='text-muted'>No hay invitados registrados en el sistema.</p>";
                            }
                            ?>
                        </div>
                        <hr>
                        
                        <h6>Agregar nuevos docentes</h6>
                        <select name="nuevos_docentes[]" multiple class="form-select">
                            <?php
                            $sql_docentes_disponibles = "
                                SELECT u.id, u.nombre, u.email
                                FROM usuarios u
                                WHERE u.es_docente = TRUE
                                AND u.id NOT IN (
                                    SELECT dc.id_docente
                                    FROM docente_curso dc
                                    WHERE dc.id_curso = ?
                                )
                                ORDER BY u.nombre ASC
                            ";
                            $stmt_docentes_disponibles = $conn->prepare($sql_docentes_disponibles);
                            $stmt_docentes_disponibles->bind_param("i", $id_curso_activo);
                            $stmt_docentes_disponibles->execute();
                            $docentes_disponibles = $stmt_docentes_disponibles->get_result();
                            while ($docente = $docentes_disponibles->fetch_assoc()) {
                                echo "<option value='" . $docente['id'] . "'>" . htmlspecialchars($docente['nombre'] . ' (' . $docente['email'] . ')') . "</option>";
                            }
                            $stmt_docentes_disponibles->close();
                            ?>
                        </select>
                        <small class="text-muted">Mantén presionado Ctrl (o Cmd en Mac) para seleccionar múltiples docentes.</small>
                        <hr>
                        <p><strong>Suma total: <span id="sumaPonderaciones">0</span>% <span id="estadoPonderaciones"></span></strong></p>
                        <small class="text-muted">La suma de todas las ponderaciones (estudiantes + docentes + invitados) debe ser exactamente 100%.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="submitBtn">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var confirmDeleteModal = document.getElementById('confirmDeleteModal');
            confirmDeleteModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var action = button.getAttribute('data-action');
                var id = button.getAttribute('data-id');
                var type = button.getAttribute('data-type');

                var form = document.getElementById('form-delete');
                form.action = action;
                document.getElementById('delete-action').value = 'delete';
                document.getElementById('delete-id').value = id;
                document.getElementById('delete-id').name = (type === 'criterio') ? 'id_criterio' : 'id';
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const generarBtn = document.getElementById('btn-generar-password');
            if (generarBtn) {
                generarBtn.addEventListener('click', function () {
                    const targetInput = document.getElementById('password_evaluador');
                    if (!targetInput) return;

                    const caracteres = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()';
                    let password = '';
                    for (let i = 0; i < 12; i++) {
                        password += caracteres.charAt(Math.floor(Math.random() * caracteres.length));
                    }
                    targetInput.value = password;
                });
            }

            <?php if ($invite_success): ?>
            const successModal = new bootstrap.Modal(document.getElementById('inviteSuccessModal'));
            successModal.show();
            <?php endif; ?>

            // Función para calcular y actualizar la suma de ponderaciones
            function actualizarSumaPonderaciones() {
                const usarPonderacionUnica = document.getElementById('usar_ponderacion_unica_invitados')?.checked || false;
                const inputs = document.querySelectorAll('.ponderacion-input');
                let suma = 0;
                
                inputs.forEach(input => {
                    // Si está usando ponderación única, excluir los inputs individuales de invitados
                    if (usarPonderacionUnica && input.closest('#ponderaciones_individuales_invitados')) {
                        return; // Saltar este input
                    }
                    // Si NO está usando ponderación única, excluir el input de ponderación única
                    if (!usarPonderacionUnica && input.id === 'ponderacion_unica_invitados') {
                        return; // Saltar este input
                    }
                    suma += parseFloat(input.value) || 0;
                });
                
                const sumaSpan = document.getElementById('sumaPonderaciones');
                const estadoSpan = document.getElementById('estadoPonderaciones');
                const submitBtn = document.getElementById('submitBtn');
                sumaSpan.textContent = suma.toFixed(0);
                if (Math.abs(suma - 100) < 0.5) {
                    estadoSpan.textContent = '(Correcto)';
                    estadoSpan.style.color = 'green';
                    submitBtn.disabled = false;
                } else if (suma < 100) {
                    estadoSpan.textContent = '(Falta)';
                    estadoSpan.style.color = 'orange';
                    submitBtn.disabled = true;
                } else {
                    estadoSpan.textContent = '(Exceso)';
                    estadoSpan.style.color = 'red';
                    submitBtn.disabled = true;
                }
            }
            
            // Manejar el checkbox de ponderación única
            const checkboxPonderacionUnica = document.getElementById('usar_ponderacion_unica_invitados');
            if (checkboxPonderacionUnica) {
                checkboxPonderacionUnica.addEventListener('change', function() {
                    const usarUnica = this.checked;
                    const containerUnica = document.getElementById('ponderacion_unica_container');
                    const containerIndividuales = document.getElementById('ponderaciones_individuales_invitados');
                    
                    if (containerUnica) {
                        containerUnica.style.display = usarUnica ? 'block' : 'none';
                    }
                    if (containerIndividuales) {
                        containerIndividuales.style.display = usarUnica ? 'none' : 'block';
                    }
                    
                    // Actualizar suma cuando cambia el modo
                    actualizarSumaPonderaciones();
                });
            }

            // Event listener para el modal de docentes
            const docentesModal = document.getElementById('docentesModal');
            docentesModal.addEventListener('shown.bs.modal', function () {
                actualizarSumaPonderaciones();
            });

            // Event listeners para los inputs de ponderación
            document.addEventListener('input', function (e) {
                if (e.target.classList.contains('ponderacion-input')) {
                    actualizarSumaPonderaciones();
                }
            });

            // Manejar el modal de exportar evaluaciones
            const modalExportar = document.getElementById('modalExportarEvaluaciones');
            const btnExportarPDF = document.getElementById('btnExportarPDF');
            const btnExportarCSV = document.getElementById('btnExportarCSV');
            
            // Detectar qué botón abrió el modal
            document.querySelectorAll('[data-bs-target="#modalExportarEvaluaciones"]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const exportType = this.getAttribute('data-export-type');
                    if (exportType === 'pdf') {
                        btnExportarPDF.style.display = 'inline-block';
                        btnExportarCSV.style.display = 'none';
                    } else if (exportType === 'csv') {
                        btnExportarPDF.style.display = 'none';
                        btnExportarCSV.style.display = 'inline-block';
                    }
                });
            });

            document.querySelectorAll('.toggle-evaluador').forEach(function(btn) {
                btn.addEventListener('click', function () {
                    const wrapper = btn.parentElement;
                    const span = wrapper.querySelector('.evaluador-obfus');
                    if (!span) return;
                    const original = span.getAttribute('data-name') || '';
                    const oculto = '•••••••';
                    if (span.textContent === oculto) {
                        span.textContent = original || 'N/D';
                        btn.textContent = 'Ocultar';
                    } else {
                        span.textContent = oculto;
                        btn.textContent = 'Mostrar';
                    }
                });
            });

            // Confirmaciones para formularios de invitados
            document.querySelectorAll('.invite-action-form').forEach(function(form) {
                form.addEventListener('submit', function (event) {
                    const tipo = form.dataset.action;
                    let mensaje = '';
                    if (tipo === 'update') {
                        mensaje = '¿Confirmas actualizar las credenciales de este invitado?';
                    } else if (tipo === 'delete') {
                        mensaje = '¿Confirmas eliminar este invitado? Esta acción no se puede deshacer.';
                    }
                    if (mensaje && !confirm(mensaje)) {
                        event.preventDefault();
                    }
                });
            });

            // Validación del formulario de exportar evaluaciones
            const formExportar = document.getElementById('formExportarEvaluaciones');
            if (formExportar) {
                formExportar.addEventListener('submit', function(event) {
                    const idCurso = document.getElementById('id_curso').value;
                    const fechaDesde = document.getElementById('fecha_desde').value;
                    const fechaHasta = document.getElementById('fecha_hasta').value;

                    // Validar que se haya seleccionado un curso
                    if (!idCurso) {
                        alert('Debes seleccionar un curso para exportar las evaluaciones.');
                        event.preventDefault();
                        return;
                    }

                    // Validar fechas si ambas están presentes
                    if (fechaDesde && fechaHasta) {
                        const desde = new Date(fechaDesde);
                        const hasta = new Date(fechaHasta);
                        if (desde > hasta) {
                            alert('La fecha "Desde" no puede ser posterior a la fecha "Hasta".');
                            event.preventDefault();
                            return;
                        }
                    }
                });
            }
        });

        // Función para abrir modal de editar evaluación
        function abrirModalEditarEvaluacion(id, nombre, tipo) {
            document.getElementById('edit_id_evaluacion').value = id;
            document.getElementById('nombre_evaluacion_edit').value = nombre;
            document.getElementById('tipo_evaluacion_edit').value = tipo;
            const modal = new bootstrap.Modal(document.getElementById('modalEditarEvaluacion'));
            modal.show();
        }

        // Función para seleccionar evaluación al hacer clic en la fila
        function seleccionarEvaluacion(id) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'evaluaciones_actions.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'seleccionar';
            form.appendChild(actionInput);
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id_evaluacion';
            idInput.value = id;
            form.appendChild(idInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>
