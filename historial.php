<?php
require 'db.php';
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

// Obtener información del curso
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso_activo = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();

// Obtener todos los estudiantes del curso
$stmt_estudiantes = $conn->prepare("SELECT id, nombre, email FROM usuarios WHERE es_docente = 0 AND id_curso = ? ORDER BY nombre ASC");
$stmt_estudiantes->bind_param("i", $id_curso_activo);
$stmt_estudiantes->execute();
$estudiantes = $stmt_estudiantes->get_result();
$stmt_estudiantes->close();

// Obtener equipos activos
$stmt_equipos_activos = $conn->prepare("SELECT id, nombre_equipo FROM equipos WHERE id_curso = ? ORDER BY nombre_equipo ASC");
$stmt_equipos_activos->bind_param("i", $id_curso_activo);
$stmt_equipos_activos->execute();
$equipos_activos = $stmt_equipos_activos->get_result();
$stmt_equipos_activos->close();

// Obtener equipos eliminados (que tienen evaluaciones pero ya no existen en la tabla equipos)
$sql_equipos_eliminados = "
    SELECT DISTINCT em.id_equipo_evaluado as id, 
           'Equipo Eliminado' as nombre_equipo,
           MIN(em.fecha_evaluacion) as primera_evaluacion,
           MAX(em.fecha_evaluacion) as ultima_evaluacion
    FROM evaluaciones_maestro em
    WHERE em.id_curso = ?
      AND em.id_equipo_evaluado NOT IN (SELECT id FROM equipos WHERE id_curso = ?)
      AND em.id_equipo_evaluado NOT IN (SELECT id FROM usuarios WHERE id_curso = ? AND es_docente = 0)
    GROUP BY em.id_equipo_evaluado
    ORDER BY primera_evaluacion DESC
";
$stmt_equipos_eliminados = $conn->prepare($sql_equipos_eliminados);
$stmt_equipos_eliminados->bind_param("iii", $id_curso_activo, $id_curso_activo, $id_curso_activo);
$stmt_equipos_eliminados->execute();
$equipos_eliminados = $stmt_equipos_eliminados->get_result();
$stmt_equipos_eliminados->close();

// Obtener información de evaluaciones para contar
function contar_evaluaciones($conn, $id_item, $es_estudiante = false) {
    $sql = "SELECT COUNT(*) as total FROM evaluaciones_maestro WHERE id_equipo_evaluado = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id_item);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$result['total'];
}

include 'header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial - <?php echo htmlspecialchars($curso_activo['nombre_curso']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Historial de Evaluaciones</h1>
            <a href="dashboard_docente.php" class="btn btn-secondary">Volver al Dashboard</a>
        </div>
        
        <div class="alert alert-info">
            <strong>Curso:</strong> <?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre'] . '-' . $curso_activo['anio']); ?>
        </div>

        <div class="row">
            <!-- Columna de Estudiantes -->
            <div class="col-md-6">
                <h3 class="mb-3">Estudiantes</h3>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Nombre</th>
                                <th>Email</th>
                                <th>Evaluaciones</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($estudiantes->num_rows > 0): ?>
                                <?php while ($estudiante = $estudiantes->fetch_assoc()): ?>
                                    <?php $num_eval = contar_evaluaciones($conn, $estudiante['id'], true); ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($estudiante['nombre']); ?></td>
                                        <td><?php echo htmlspecialchars($estudiante['email']); ?></td>
                                        <td><span class="badge bg-primary"><?php echo $num_eval; ?></span></td>
                                        <td>
                                            <a href="ver_historial.php?tipo=estudiante&id=<?php echo $estudiante['id']; ?>" class="btn btn-sm btn-info">
                                                Ver Historial
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center">No hay estudiantes registrados</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Columna de Equipos -->
            <div class="col-md-6">
                <h3 class="mb-3">Equipos</h3>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Nombre</th>
                                <th>Estado</th>
                                <th>Evaluaciones</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Mostrar equipos activos
                            if ($equipos_activos->num_rows > 0): 
                                while ($equipo = $equipos_activos->fetch_assoc()): 
                                    $num_eval = contar_evaluaciones($conn, $equipo['id'], false);
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></td>
                                    <td><span class="badge bg-success">Activo</span></td>
                                    <td><span class="badge bg-primary"><?php echo $num_eval; ?></span></td>
                                    <td>
                                        <a href="ver_historial.php?tipo=equipo&id=<?php echo $equipo['id']; ?>" class="btn btn-sm btn-info">
                                            Ver Historial
                                        </a>
                                    </td>
                                </tr>
                            <?php 
                                endwhile;
                            endif;
                            
                            // Mostrar equipos eliminados
                            if ($equipos_eliminados->num_rows > 0): 
                                while ($equipo = $equipos_eliminados->fetch_assoc()): 
                                    $num_eval = contar_evaluaciones($conn, $equipo['id'], false);
                            ?>
                                <tr class="table-secondary">
                                    <td><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></td>
                                    <td><span class="badge bg-danger">Eliminado</span></td>
                                    <td><span class="badge bg-primary"><?php echo $num_eval; ?></span></td>
                                    <td>
                                        <a href="ver_historial.php?tipo=equipo&id=<?php echo $equipo['id']; ?>" class="btn btn-sm btn-info">
                                            Ver Historial
                                        </a>
                                    </td>
                                </tr>
                            <?php 
                                endwhile;
                            endif;
                            
                            if ($equipos_activos->num_rows == 0 && $equipos_eliminados->num_rows == 0):
                            ?>
                                <tr>
                                    <td colspan="4" class="text-center">No hay equipos registrados</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

