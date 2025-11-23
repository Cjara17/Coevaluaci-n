<?php
require 'db.php';
// Solo requerir sesión activa, no importa si es docente o estudiante, aunque es flujo de estudiante
verificar_sesion(); 

$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Tu acción se ha completado con éxito.';

// Determinar rol y dashboard
$es_docente = !empty($_SESSION['es_docente']);
$dashboard_url = 'dashboard_estudiante.php';
$studentStats = null;

if (!$es_docente) {
    $id_equipo_alumno = $_SESSION['id_equipo'] ?? null;
    $id_curso_alumno = $_SESSION['id_curso_activo'] ?? null;

    if ($id_equipo_alumno && $id_curso_alumno) {
        // Promedio general del equipo (calificación personal)
        $stmt_avg = $conn->prepare("
            SELECT 
                AVG(em.puntaje_total) AS promedio_total,
                COUNT(*) AS total_evaluaciones,
                MAX(em.fecha_evaluacion) AS ultima_eval
            FROM evaluaciones_maestro em
            WHERE em.id_equipo_evaluado = ? AND em.id_curso = ?
        ");
        $stmt_avg->bind_param("ii", $id_equipo_alumno, $id_curso_alumno);
        $stmt_avg->execute();
        $res_avg = $stmt_avg->get_result()->fetch_assoc();
        $stmt_avg->close();

        if ($res_avg && $res_avg['total_evaluaciones'] > 0) {
            $promedio_total = (float)$res_avg['promedio_total'];
            $total_evaluaciones = (int)$res_avg['total_evaluaciones'];
            $ultima_eval = $res_avg['ultima_eval'];

            // Desglose por criterio
            $stmt_detalle = $conn->prepare("
                SELECT 
                    cr.descripcion,
                    cr.puntaje_maximo,
                    cr.ponderacion,
                    AVG(d.puntaje) AS promedio_criterio
                FROM evaluaciones_detalle d
                JOIN evaluaciones_maestro em ON d.id_evaluacion = em.id
                JOIN criterios cr ON d.id_criterio = cr.id
                WHERE em.id_equipo_evaluado = ? AND em.id_curso = ?
                GROUP BY d.id_criterio, cr.descripcion, cr.puntaje_maximo, cr.ponderacion
                ORDER BY cr.orden ASC
            ");
            $stmt_detalle->bind_param("ii", $id_equipo_alumno, $id_curso_alumno);
            $stmt_detalle->execute();
            $detalle = $stmt_detalle->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_detalle->close();

            $studentStats = [
                'promedio_total' => $promedio_total,
                'total_evaluaciones' => $total_evaluaciones,
                'ultima_eval' => $ultima_eval,
                'detalle' => $detalle
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Evaluación Exitosa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-lg border-success">
                    <div class="card-header bg-success text-white text-center">
                        <h4 class="mb-0">¡Operación Exitosa!</h4>
                    </div>
                    <div class="card-body text-center p-5">
                        <svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" fill="currentColor" class="bi bi-check-circle-fill text-success mb-4" viewBox="0 0 16 16">
                            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.497 5.354 7.373a.75.75 0 0 0-1.06 1.06l2.123 2.122a.75.75 0 0 0 1.06 0l4.58-4.591a.75.75 0 0 0-.02-1.08z"/>
                        </svg>
                        <p class="lead"><?php echo $msg; ?></p>
                        <hr>
                        <a href="<?php echo $dashboard_url; ?>" class="btn btn-primary mt-3">Volver al Dashboard</a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($studentStats): ?>
        <div class="row justify-content-center mt-4">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Tu calificación individual</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center mb-4">
                            <div class="col-md-4">
                                <p class="text-muted mb-1">Promedio actual</p>
                                <p class="display-6 fw-bold text-success"><?php echo number_format($studentStats['promedio_total'], 2); ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-muted mb-1">Evaluaciones recibidas</p>
                                <p class="h4 fw-bold"><?php echo $studentStats['total_evaluaciones']; ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-muted mb-1">Última actualización</p>
                                <p class="fw-semibold"><?php echo $studentStats['ultima_eval'] ? date("d/m/Y H:i", strtotime($studentStats['ultima_eval'])) : 'Sin datos'; ?></p>
                            </div>
                        </div>

                        <?php if (!empty($studentStats['detalle'])): ?>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>Criterio</th>
                                        <th class="text-center">Promedio</th>
                                        <th class="text-center">Puntaje Máx.</th>
                                        <th class="text-center">Ponderación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($studentStats['detalle'] as $fila): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($fila['descripcion']); ?></td>
                                            <td class="text-center fw-semibold text-primary">
                                                <?php echo number_format($fila['promedio_criterio'], 2); ?>
                                            </td>
                                            <td class="text-center"><?php echo (int)$fila['puntaje_maximo']; ?></td>
                                            <td class="text-center"><?php echo number_format((float)$fila['ponderacion'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <p class="text-muted text-center mb-0">
                                Aún no hay suficientes evaluaciones para mostrar el desglose por criterio. Puedes volver más tarde para revisar tu progreso.
                            </p>
                        <?php endif; ?>

                        <div class="alert alert-info mt-4 mb-0">
                            Esta calificación se actualiza cada vez que un compañero o docente evalúa a tu equipo.
                            Puedes consultar este historial en cualquier momento desde tu dashboard.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif (!$es_docente): ?>
            <div class="alert alert-secondary mt-4 text-center">
                Aún no hay evaluaciones registradas para tu equipo. Recibirás tu calificación tan pronto como tus compañeros y docentes envíen sus coevaluaciones.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>