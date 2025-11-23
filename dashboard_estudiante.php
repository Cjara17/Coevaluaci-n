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

$equipo_presentando = null;
$result_presentando = $conn->query("SELECT id, nombre_equipo FROM equipos WHERE estado_presentacion = 'presentando'");
if ($result_presentando->num_rows > 0) {
    $equipo_presentando = $result_presentando->fetch_assoc();
}

$ya_evaluo = false;
if ($equipo_presentando) {
    // ***** LA CORRECCIÓN ESTÁ AQUÍ *****
    // Se cambió 'evaluaciones' por la tabla correcta 'evaluaciones_maestro'
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
                    <div class="card-header"><h3>Equipo Presentando Ahora</h3></div>
                    <div class="card-body p-5">
                        <?php if ($equipo_presentando): ?>
                            <h2 class="display-5"><?php echo htmlspecialchars($equipo_presentando['nombre_equipo']); ?></h2>
                            
                            <?php if ($equipo_presentando['id'] == $id_equipo_usuario): ?>
                                <p class="alert alert-warning mt-4">Este es tu propio equipo. No puedes evaluarlo.</p>
                            <?php elseif ($ya_evaluo): ?>
                                <p class="alert alert-success mt-4">¡Gracias! Ya has evaluado a este equipo.</p>
                            <?php else: ?>
                                <a href="evaluar.php?id_equipo=<?php echo $equipo_presentando['id']; ?>" class="btn btn-success btn-lg mt-4">Evaluar Presentación</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="alert alert-info">Actualmente no hay ningún equipo presentando. Espera indicaciones del docente.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>