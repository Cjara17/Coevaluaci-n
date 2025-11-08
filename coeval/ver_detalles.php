<?php
require 'db.php';
verificar_sesion(true);

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: dashboard_docente.php"); exit();
}
$id_equipo = $_GET['id'];

$stmt_equipo = $conn->prepare("SELECT nombre_equipo FROM equipos WHERE id = ?");
$stmt_equipo->bind_param("i", $id_equipo);
$stmt_equipo->execute();
$equipo = $stmt_equipo->get_result()->fetch_assoc();
if (!$equipo) { header("Location: dashboard_docente.php"); exit(); }

// ***** LA CORRECCIÓN ESTÁ AQUÍ *****
// Se cambió 'evaluaciones v' por 'evaluaciones_maestro v'
$sql_detalles = "
    SELECT u.nombre, u.email, u.es_docente, v.puntaje_total, v.fecha_evaluacion
    FROM evaluaciones_maestro v
    JOIN usuarios u ON v.id_evaluador = u.id
    WHERE v.id_equipo_evaluado = ?
    ORDER BY u.es_docente DESC, u.nombre ASC";

$stmt_detalles = $conn->prepare($sql_detalles);
$stmt_detalles->bind_param("i", $id_equipo);
$stmt_detalles->execute();
$detalles = $stmt_detalles->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalles de Evaluación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="dashboard_docente.php">Panel del Docente</a><ul class="navbar-nav ms-auto"><li class="nav-item"><a class="btn btn-outline-light" href="dashboard_docente.php">Volver al Dashboard</a></li></ul></div></nav>
    <div class="container mt-5">
        <h1 class="mb-4">Detalles de Evaluación para: <strong><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></strong></h1>
        <div class="card"><div class="card-body"><table class="table table-striped table-hover"><thead class="table-light"><tr><th>Evaluador</th><th>Correo</th><th class="text-center">Rol</th><th class="text-center">Puntaje</th><th>Fecha</th></tr></thead>
            <tbody>
                <?php if ($detalles->num_rows > 0): ?>
                    <?php while ($detalle = $detalles->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($detalle['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($detalle['email']); ?></td>
                        <td class="text-center">
                            <?php if ($detalle['es_docente']): ?><span class="badge bg-primary">Docente</span><?php else: ?><span class="badge bg-secondary">Estudiante</span><?php endif; ?>
                        </td>
                        <td class="text-center fw-bold"><?php echo $detalle['puntaje_total']; ?></td>
                        <td><?php echo date("d/m/Y H:i", strtotime($detalle['fecha_evaluacion'])); ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="text-center">Este equipo aún no ha recibido evaluaciones.</td></tr>
                <?php endif; ?>
            </tbody></table></div></div>
    </div>
</body>
</html>