<?php
/**
 * Dashboard para estudiantes mostrando el equipo que está presentando actualmente.
 *
 * Requiere sesión activa de estudiante.
 * Consulta el equipo en estado de presentación y verifica si el estudiante ya evaluó ese equipo.
 *
 * Utiliza variables superglobales:
 * @global int $_SESSION['id_usuario'] ID del estudiante autenticado.
 * @global int $_SESSION['id_equipo'] ID del equipo del estudiante.
 * @global string $_GET['status'] Parámetro opcional para mensajes de estado (por ejemplo, éxito al enviar evaluación).
 *
 * Realiza consultas a la base de datos para:
 * - Obtener equipo en estado 'presentando'.
 * - Verificar si el estudiante ya ha evaluado al equipo que presenta.
 *
 * Muestra interfaz con información del equipo presentando, mensajes informativos o enlaces para evaluar.
 *
 * @return void Renderiza página dashboard estudiante.
 */
require 'db.php';
verificar_sesion(false); // Solo para estudiantes

$id_usuario_actual = $_SESSION['id_usuario'];
$id_equipo_usuario = $_SESSION['id_equipo'];
$id_curso_activo = $_SESSION['id_curso_activo'] ?? null;

// Buscar equipos presentando (evaluaciones grupales)
$equipo_presentando = null;
$result_presentando = $conn->query("SELECT id, nombre_equipo FROM equipos WHERE estado_presentacion = 'presentando' AND id_curso = " . ($id_curso_activo ?? 0));
if ($result_presentando->num_rows > 0) {
    $equipo_presentando = $result_presentando->fetch_assoc();
}

// Buscar estudiantes individuales presentando (evaluaciones individuales)
$estudiante_presentando = null;
if ($id_curso_activo) {
    $stmt_est_presentando = $conn->prepare("SELECT id, nombre FROM usuarios WHERE estado_presentacion_individual = 'presentando' AND id_curso = ? AND es_docente = 0 AND id != ?");
    $stmt_est_presentando->bind_param("ii", $id_curso_activo, $id_usuario_actual);
    $stmt_est_presentando->execute();
    $result_est_presentando = $stmt_est_presentando->get_result();
    if ($result_est_presentando->num_rows > 0) {
        $estudiante_presentando = $result_est_presentando->fetch_assoc();
    }
    $stmt_est_presentando->close();
}

$ya_evaluo = false;
$id_item_a_evaluar = null;
$es_individual = false;

if ($estudiante_presentando) {
    // Es una evaluación individual
    $es_individual = true;
    $id_item_a_evaluar = $estudiante_presentando['id'];
    // Para evaluaciones individuales, usar el id del estudiante directamente como id_equipo_evaluado
    // Esto asegura que cada estudiante tenga su propia evaluación única
    $id_equipo_para_evaluar = $id_item_a_evaluar;
    
    // Verificar si ya evaluó
    $stmt = $conn->prepare("SELECT id FROM evaluaciones_maestro WHERE id_evaluador = ? AND id_equipo_evaluado = ?");
    $stmt->bind_param("ii", $id_usuario_actual, $id_equipo_para_evaluar);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $ya_evaluo = true;
    }
    $stmt->close();
} elseif ($equipo_presentando) {
    // Es una evaluación grupal
    $id_item_a_evaluar = $equipo_presentando['id'];
    $stmt = $conn->prepare("SELECT id FROM evaluaciones_maestro WHERE id_evaluador = ? AND id_equipo_evaluado = ?");
    $stmt->bind_param("ii", $id_usuario_actual, $equipo_presentando['id']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $ya_evaluo = true;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Estudiante - Coevaluación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body style="padding-bottom: 120px;">
    <?php
    // NUEVO: unificación visual con dashboard_docente
    $page_title = 'Plataforma de Evaluación';
    include 'header.php';
    ?>

    <div class="container mt-5">
        
        <!-- Mensaje de éxito al volver de la evaluación -->
        <?php if (isset($_GET['status']) && $_GET['status'] == 'success'): ?>
            <div class="alert alert-success">¡Tu evaluación ha sido enviada correctamente!</div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card text-center shadow">
                    <div class="card-header">
                        <h3><?php echo $es_individual ? 'Estudiante Presentando Ahora' : 'Equipo Presentando Ahora'; ?></h3>
                    </div>
                    <div class="card-body p-5">
                        <?php if ($estudiante_presentando): ?>
                            <h2 class="display-5"><?php echo htmlspecialchars($estudiante_presentando['nombre']); ?></h2>
                            
                            <?php if ($estudiante_presentando['id'] == $id_usuario_actual): ?>
                                <p class="alert alert-warning mt-4">Eres tú quien está presentando. No puedes evaluarte a ti mismo.</p>
                            <?php elseif ($ya_evaluo): ?>
                                <p class="alert alert-success mt-4">¡Gracias! Ya has evaluado a este estudiante.</p>
                            <?php else: ?>
                                <?php
                                // Para evaluaciones individuales, usar id_estudiante directamente
                                // Esto asegura que cada estudiante tenga su propia evaluación única
                                ?>
                                <a href="evaluar.php?id_estudiante=<?php echo $estudiante_presentando['id']; ?>" class="btn btn-success btn-lg mt-4">Evaluar Presentación</a>
                            <?php endif; ?>
                        <?php elseif ($equipo_presentando): ?>
                            <h2 class="display-5"><?php echo htmlspecialchars($equipo_presentando['nombre_equipo']); ?></h2>
                            
                            <?php if ($equipo_presentando['id'] == $id_equipo_usuario): ?>
                                <p class="alert alert-warning mt-4">Este es tu propio equipo. No puedes evaluarlo.</p>
                            <?php elseif ($ya_evaluo): ?>
                                <p class="alert alert-success mt-4">¡Gracias! Ya has evaluado a este equipo.</p>
                            <?php else: ?>
                                <a href="evaluar.php?id_equipo=<?php echo $equipo_presentando['id']; ?>" class="btn btn-success btn-lg mt-4">Evaluar Presentación</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="alert alert-info">Actualmente no hay ningún equipo o estudiante presentando. Espera indicaciones del docente.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>