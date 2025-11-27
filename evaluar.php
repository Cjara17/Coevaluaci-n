<?php
require 'db.php';
// Esta página es usada por estudiantes y docentes
verificar_sesion();

$id_equipo_a_evaluar = null;
$id_estudiante_a_evaluar = null;
$es_evaluacion_individual = false;
$nombre_item = '';
$id_curso_item = null;

// Verificar si es una evaluación individual (id_estudiante) o grupal (id_equipo)
if (isset($_GET['id_estudiante']) && is_numeric($_GET['id_estudiante'])) {
    // Evaluación individual
    $es_evaluacion_individual = true;
    $id_estudiante_a_evaluar = (int)$_GET['id_estudiante'];
    
    // Obtener información del estudiante
    $stmt = $conn->prepare("SELECT nombre, id_curso FROM usuarios WHERE id = ? AND es_docente = 0");
    $stmt->bind_param("i", $id_estudiante_a_evaluar);
    $stmt->execute();
    $estudiante = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$estudiante) {
        header("Location: dashboard_estudiante.php?error=" . urlencode("Estudiante no encontrado."));
        exit();
    }
    
    $nombre_item = $estudiante['nombre'];
    $id_curso_item = (int)$estudiante['id_curso'];
    
    // Para evaluaciones individuales, usar el id del estudiante como id_equipo_evaluado
    // Esto asegura que cada estudiante tenga su propia evaluación única
    $id_equipo_a_evaluar = $id_estudiante_a_evaluar;
    
    // Restricción para estudiantes, no para docentes
    if (!$_SESSION['es_docente'] && $id_estudiante_a_evaluar == $_SESSION['id_usuario']) {
        header("Location: dashboard_estudiante.php"); exit();
    }
} elseif (isset($_GET['id_equipo']) && is_numeric($_GET['id_equipo'])) {
    // Evaluación grupal
    $id_equipo_a_evaluar = (int)$_GET['id_equipo'];
    
    // Restricción para estudiantes, no para docentes
    if (!$_SESSION['es_docente'] && $id_equipo_a_evaluar == $_SESSION['id_equipo']) {
        header("Location: dashboard_estudiante.php"); exit();
    }
    
    $stmt = $conn->prepare("SELECT nombre_equipo, id_curso FROM equipos WHERE id = ?");
    $stmt->bind_param("i", $id_equipo_a_evaluar);
    $stmt->execute();
    $equipo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$equipo) {
        header("Location: dashboard_estudiante.php?error=" . urlencode("Equipo no encontrado."));
        exit();
    }
    
    $nombre_item = $equipo['nombre_equipo'];
    $id_curso_item = (int)$equipo['id_curso'];
} else {
    header("Location: dashboard_estudiante.php?error=" . urlencode("Parámetros inválidos."));
    exit();
}

if (!isset($_SESSION['id_curso_activo']) || $_SESSION['id_curso_activo'] != $id_curso_item) {
    $_SESSION['id_curso_activo'] = $id_curso_item;
}

$stmt_criterios = $conn->prepare("SELECT * FROM criterios WHERE activo = TRUE AND id_curso = ? ORDER BY orden ASC");
$stmt_criterios->bind_param("i", $id_curso_item);
$stmt_criterios->execute();
$criterios_result = $stmt_criterios->get_result();
$criterios = [];
while ($row = $criterios_result->fetch_assoc()) {
    $criterios[] = $row;
}
$stmt_criterios->close();

// Obtener opciones de evaluación
$stmt_opciones = $conn->prepare("SELECT * FROM opciones_evaluacion WHERE id_curso = ? ORDER BY orden ASC, puntaje ASC");
$stmt_opciones->bind_param("i", $id_curso_item);
$stmt_opciones->execute();
$opciones_result = $stmt_opciones->get_result();
$opciones = [];
while ($row = $opciones_result->fetch_assoc()) {
    $opciones[] = $row;
}
$stmt_opciones->close();

// Obtener descripciones de criterio-opción
$descripciones = [];
if (!empty($criterios) && !empty($opciones)) {
    $ids_criterios = array_column($criterios, 'id');
    $ids_opciones = array_column($opciones, 'id');
    if (!empty($ids_criterios) && !empty($ids_opciones)) {
        $placeholders_c = implode(',', array_fill(0, count($ids_criterios), '?'));
        $placeholders_o = implode(',', array_fill(0, count($ids_opciones), '?'));
        $types = str_repeat('i', count($ids_criterios) + count($ids_opciones));
        $params = array_merge($ids_criterios, $ids_opciones);
        $stmt_desc = $conn->prepare("
            SELECT id_criterio, id_opcion, descripcion 
            FROM criterio_opcion_descripciones 
            WHERE id_criterio IN ($placeholders_c) AND id_opcion IN ($placeholders_o)
        ");
        if ($stmt_desc) {
            $stmt_desc->bind_param($types, ...$params);
            $stmt_desc->execute();
            $desc_result = $stmt_desc->get_result();
            while ($row = $desc_result->fetch_assoc()) {
                $descripciones[(int)$row['id_criterio']][(int)$row['id_opcion']] = $row['descripcion'];
            }
            $stmt_desc->close();
        }
    }
}

// Iniciar temporizador: Crear o actualizar registro de evaluación con inicio_temporizador
$now = date('Y-m-d H:i:s');
$stmt_timer = $conn->prepare("
    INSERT INTO evaluaciones_maestro (id_evaluador, id_equipo_evaluado, id_curso, puntaje_total, inicio_temporizador)
    VALUES (?, ?, ?, 0, ?)
    ON DUPLICATE KEY UPDATE
        inicio_temporizador = IF(inicio_temporizador IS NULL, VALUES(inicio_temporizador), inicio_temporizador)
");
$stmt_timer->bind_param("iiis", $_SESSION['id_usuario'], $id_equipo_a_evaluar, $id_curso_item, $now);
$stmt_timer->execute();
$id_evaluacion = $conn->insert_id;
if ($stmt_timer->affected_rows === 2) { // UPDATE case
    $stmt_get_id = $conn->prepare("SELECT id FROM evaluaciones_maestro WHERE id_evaluador = ? AND id_equipo_evaluado = ? AND id_curso = ?");
    $stmt_get_id->bind_param("iii", $_SESSION['id_usuario'], $id_equipo_a_evaluar, $id_curso_item);
    $stmt_get_id->execute();
    $id_evaluacion = $stmt_get_id->get_result()->fetch_assoc()['id'];
    $stmt_get_id->close();
}
$stmt_timer->close();

// Calcular tiempo restante
require 'timeout_helpers.php';
$timeout_info = verificar_timeout($conn, $id_evaluacion);
$tiempo_restante_en_segundos = $timeout_info['tiempo_restante_segundos'];

if ($tiempo_restante_en_segundos <= 0) {
    header("Location: tiempo_agotado.php?msg=" . urlencode("El tiempo para esta evaluación ha expirado."));
    exit();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Evaluar Equipo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-lg-between mb-4 gap-3">
            <div>
                <h1 class="mb-1">Evaluando a: <strong><?php echo htmlspecialchars($nombre_item); ?></strong></h1>
                <p class="mb-0">Selecciona la opción que mejor describe el desempeño en cada criterio.</p>
            </div>
        </div>

        <div id="timer" class="timer-box">
            Cargando temporizador...
        </div>

        <form action="procesar_evaluacion.php" method="POST" id="evaluacionForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <input type="hidden" name="id_equipo_evaluado" value="<?php echo $id_equipo_a_evaluar; ?>">
            
            <div class="table-responsive">
                <table class="table table-bordered rubrica-table">
                    <thead>
                        <tr>
                            <th class="criterio-cell">Criterios</th>
                            <?php if (empty($opciones)): ?>
                                <th class="opcion-cell">No hay opciones definidas</th>
                            <?php else: ?>
                                <?php foreach ($opciones as $opcion): ?>
                                    <th class="opcion-cell">
                                        <div class="fw-bold"><?php echo htmlspecialchars($opcion['nombre']); ?></div>
                                        <div class="puntaje-header mt-2">
                                            <small class="text-muted">Puntaje: <?php echo number_format($opcion['puntaje'], 0); ?></small>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($criterios)): ?>
                            <tr>
                                <td colspan="<?php echo max(2, count($opciones) + 1); ?>" class="text-center">
                                    No hay criterios definidos.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($criterios as $criterio): ?>
                                <tr>
                                    <td class="criterio-cell">
                                        <?php
                                        $parts = explode(':', $criterio['descripcion'], 2);
                                        $name = trim($parts[0]);
                                        $description = isset($parts[1]) ? trim($parts[1]) : '';
                                        ?>
                                        <strong><?php echo htmlspecialchars($name); ?></strong>
                                        <?php if (!empty($description)): ?>
                                            <br><small><?php echo htmlspecialchars($description); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (empty($opciones)): ?>
                                        <td class="text-center text-muted">-</td>
                                    <?php else: ?>
                                        <?php foreach ($opciones as $opcion): ?>
                                            <td class="opcion-cell">
                                                <?php
                                                $descripcion = isset($descripciones[$criterio['id']][$opcion['id']]) 
                                                    ? htmlspecialchars($descripciones[$criterio['id']][$opcion['id']]) 
                                                    : '';
                                                ?>
                                                <button type="button" 
                                                        class="btn btn-outline-primary w-100 h-100 text-start p-3 descripcion-btn" 
                                                        data-criterio-id="<?php echo $criterio['id']; ?>"
                                                        data-opcion-id="<?php echo $opcion['id']; ?>"
                                                        data-puntaje="<?php echo $opcion['puntaje']; ?>"
                                                        style="min-height: 100px; white-space: normal; word-wrap: break-word;">
                                                    <?php if (empty($descripcion)): ?>
                                                        <span class="text-muted">Sin descripción</span>
                                                    <?php else: ?>
                                                        <?php echo $descripcion; ?>
                                                    <?php endif; ?>
                                                </button>
                                                <input type="radio" 
                                                       name="criterios[<?php echo $criterio['id']; ?>]" 
                                                       value="<?php echo (int)round($opcion['puntaje']); ?>" 
                                                       id="criterio_<?php echo $criterio['id']; ?>_opcion_<?php echo $opcion['id']; ?>"
                                                       class="d-none"
                                                       required>
                                            </td>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <style>
                .rubrica-table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .rubrica-table th,
                .rubrica-table td {
                    border: 1px solid #dee2e6;
                    padding: 8px;
                    text-align: left;
                    vertical-align: top;
                }
                .rubrica-table th {
                    background-color: #f8f9fa;
                    font-weight: bold;
                    text-align: center;
                }
                .rubrica-table .criterio-cell {
                    min-width: 200px;
                    font-weight: 500;
                }
                .rubrica-table .opcion-cell {
                    min-width: 150px;
                    text-align: center;
                }
                .rubrica-table .puntaje-header {
                    font-size: 0.9em;
                    color: #666;
                }
                .descripcion-btn {
                    transition: all 0.2s;
                }
                .descripcion-btn:hover {
                    background-color: #e7f1ff;
                    border-color: #0d6efd;
                }
                .descripcion-btn.selected {
                    background-color: #0d6efd;
                    color: white;
                    border-color: #0d6efd;
                }
            </style>
            
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const descripcionBtns = document.querySelectorAll('.descripcion-btn');
                    descripcionBtns.forEach(btn => {
                        btn.addEventListener('click', function() {
                            const criterioId = this.getAttribute('data-criterio-id');
                            const opcionId = this.getAttribute('data-opcion-id');
                            
                            // Deseleccionar otros botones del mismo criterio
                            const otrosBtns = document.querySelectorAll(`.descripcion-btn[data-criterio-id="${criterioId}"]`);
                            otrosBtns.forEach(b => {
                                b.classList.remove('selected');
                                const radio = document.getElementById(`criterio_${criterioId}_opcion_${b.getAttribute('data-opcion-id')}`);
                                if (radio) radio.checked = false;
                            });
                            
                            // Seleccionar este botón
                            this.classList.add('selected');
                            const radio = document.getElementById(`criterio_${criterioId}_opcion_${opcionId}`);
                            if (radio) radio.checked = true;
                        });
                    });
                });
            </script>
            
            <div class="d-grid gap-2 mt-4"><button type="submit" class="btn btn-primary btn-lg">Enviar Evaluación</button></div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let tiempoRestante = <?php echo $tiempo_restante_en_segundos; ?>;
            const timerElement = document.getElementById('timer');
            const form = document.getElementById('evaluacionForm');
            const inputs = form.querySelectorAll('input, button');

            function actualizarTimer() {
                const minutos = Math.floor(tiempoRestante / 60);
                const segundos = tiempoRestante % 60;
                const tiempoFormateado = `${minutos.toString().padStart(2, '0')}:${segundos.toString().padStart(2, '0')}`;
                timerElement.textContent = tiempoFormateado;

                if (tiempoRestante > 60) {
                    timerElement.style.color = 'green';
                } else if (tiempoRestante > 20) {
                    timerElement.style.color = 'yellow';
                } else {
                    timerElement.style.color = 'red';
                }

                if (tiempoRestante <= 0) {
                    clearInterval(intervalo);
                    timerElement.textContent = '00:00';
                    // Bloquear formulario
                    inputs.forEach(input => {
                        input.disabled = true;
                    });
                    // Enviar automáticamente
                    form.submit();
                } else {
                    tiempoRestante--;
                }
            }

            actualizarTimer();
            const intervalo = setInterval(actualizarTimer, 1000);
        });
    </script>

</body>
</html>