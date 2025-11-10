<?php
require 'db.php';
// Requerir ser docente Y tener un curso activo (verificar_sesion lo redirige si falta el curso)
verificar_sesion(true, true); 

<<<<<<< HEAD
$id_curso_activo = get_active_course_id();
$id_docente = $_SESSION['id_usuario'];
=======
// Session timeout check and update is handled in db.php

// --- BLOQUE AÑADIR---
// Cargar la escala de notas en un array para consulta rápida
$escala_lookup = [];
$result_escala = $conn->query("SELECT puntaje, nota FROM escala_notas");
while ($row = $result_escala->fetch_assoc()) {
    $escala_lookup[$row['puntaje']] = $row['nota'];
}
// --- FIN DEL BLOQUE A AÑADIR ---
>>>>>>> 9f138c1ff81b044a7d1760d461ad8a8128013b70

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

// 3. CONSULTA PRINCIPAL: EQUIPOS DEL CURSO ACTIVO con su puntaje promedio
$sql_equipos = "
    SELECT 
        e.id, 
        e.nombre_equipo, 
        e.estado_presentacion,
        -- La subconsulta también debe filtrar por el curso
        (SELECT AVG(puntaje_total) FROM evaluaciones_maestro WHERE id_equipo_evaluado = e.id AND id_curso = ?) AS promedio_puntaje
    FROM equipos e
    WHERE e.id_curso = ? -- Filtramos los equipos por el curso activo
    ORDER BY e.nombre_equipo ASC";

$stmt_equipos = $conn->prepare($sql_equipos);
$stmt_equipos->bind_param("ii", $id_curso_activo, $id_curso_activo);
$stmt_equipos->execute();
$equipos = $stmt_equipos->get_result();

// Lógica para obtener la nota final (adaptada para usar el id_curso)
function calcular_nota_final($puntaje, $conn, $id_curso_activo) {
    if ($puntaje === null) return "N/A";

    // 1. Buscar la nota más cercana en la escala del curso activo
    $stmt = $conn->prepare("
        SELECT nota FROM escala_notas 
        WHERE id_curso = ?
        ORDER BY ABS(puntaje - ?) ASC 
        LIMIT 1"
    );
    $puntaje_redondeado = round($puntaje);
    $stmt->bind_param("ii", $id_curso_activo, $puntaje_redondeado);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        return number_format($resultado->fetch_assoc()['nota'], 1);
    }

    return "S/E"; // Sin Escala
}


$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Docente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="dashboard_docente.php">
                 <?php echo htmlspecialchars($curso_activo['nombre_curso']) . ' (' . htmlspecialchars($curso_activo['semestre']) . ')'; ?>
            </a>
            <div class="ms-auto d-flex align-items-center">
                
                <form action="set_course.php" method="POST" class="d-flex me-3">
                    <select name="id_curso" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="" disabled selected>Cambiar Curso...</option>
                        <?php 
                        // Necesitamos resetear el puntero ya que lo usamos para la opción disabled/selected
                        $all_cursos->data_seek(0); 
                        while($curso = $all_cursos->fetch_assoc()): 
                            $selected = $curso['id'] == $id_curso_activo ? 'selected' : '';
                        ?>
                            <option value="<?php echo $curso['id']; ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($curso['nombre_curso'] . ' ' . $curso['semestre']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </form>

                <ul class="navbar-nav">
                    <li class="nav-item me-2"><a class="btn btn-outline-light" href="select_course.php">Listado Cursos</a></li>
                    <li class="nav-item"><a class="btn btn-danger" href="logout.php">Cerrar Sesión</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <h1>Dashboard Docente</h1>
        <p class="lead">Gestión del curso: <strong><?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre']); ?></strong></p>

        <?php if ($status_message): ?>
            <div class="alert alert-success"><?php echo $status_message; ?></div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header bg-secondary text-white">Herramientas del Curso</div>
                    <div class="card-body">
                        <div class="list-group">
                            <a href="gestionar_criterios.php" class="list-group-item list-group-item-action">Gestionar Criterios de Evaluación</a>
                            <a href="#" class="list-group-item list-group-item-action disabled">Gestionar Docentes Colaboradores (Pendiente)</a>
                            <a href="export_results.php" class="list-group-item list-group-item-action">Exportar Resultados</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">Carga de Datos (Curso Activo)</div>
                    <div class="card-body">
                        <h5 class="card-title">1. Cargar Lista de Estudiantes y Equipos</h5>
                        <form action="upload.php" method="POST" enctype="multipart/form-data" class="mb-3">
                            <div class="input-group">
                                <input type="file" class="form-control" name="lista_estudiantes" id="lista_estudiantes" accept=".csv" required>
                                <button type="submit" class="btn btn-primary">Subir Estudiantes (CSV)</button>
                            </div>
                            <small class="form-text text-muted">Formato: Nombre, Correo, Nombre_Equipo</small>
                        </form>
                        
                        <hr>
                        
                        <h5 class="card-title">2. Cargar Escala de Notas</h5>
                        <form action="upload_escala.php" method="POST" enctype="multipart/form-data">
                            <div class="input-group">
                                <input type="file" class="form-control" name="escala_csv" id="escala_csv" accept=".csv" required>
                                <button type="submit" class="btn btn-success">Subir Escala (CSV)</button>
                            </div>
                             <small class="form-text text-muted">Formato: Puntaje, Nota (Ej: 10,7.0)</small>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <h2 class="mt-4">Equipos del Curso</h2>
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Equipo</th>
                    <th class="text-center">Estado Presentación</th>
                    <th class="text-center">Puntaje Promedio</th>
                    <th class="text-center">Nota Final</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($equipos->num_rows > 0): ?>
                    <?php while($equipo = $equipos->fetch_assoc()): 
                        $promedio = $equipo['promedio_puntaje'] !== null ? number_format($equipo['promedio_puntaje'], 2) : 'N/A';
                        $nota = calcular_nota_final($equipo['promedio_puntaje'], $conn, $id_curso_activo);
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></td>
                        <td class="text-center">
                            <?php 
                            $badge_class = 'bg-secondary';
                            if ($equipo['estado_presentacion'] == 'presentando') { $badge_class = 'bg-primary'; }
                            if ($equipo['estado_presentacion'] == 'finalizado') { $badge_class = 'bg-success'; }
                            ?>
                            <span class="badge <?php echo $badge_class; ?>">
                                <?php echo ucfirst(htmlspecialchars($equipo['estado_presentacion'])); ?>
                            </span>
                        </td>
                        <td class="text-center"><?php echo $promedio; ?></td>
                        <td class="text-center fw-bold"><?php echo $nota; ?></td>
                        <td class="text-center">
                            <form action="gestionar_presentacion.php" method="POST" class="d-inline">
                                <input type="hidden" name="id_equipo" value="<?php echo $equipo['id']; ?>">
                                <?php if ($equipo['estado_presentacion'] == 'pendiente'): ?>
                                    <input type="hidden" name="accion" value="iniciar">
                                    <button type="submit" class="btn btn-sm btn-primary">Iniciar Presentación</button>
                                <?php elseif ($equipo['estado_presentacion'] == 'presentando'): ?>
                                    <input type="hidden" name="accion" value="terminar">
                                    <button type="submit" class="btn btn-sm btn-success">Terminar Presentación</button>
                                <?php endif; ?>
                            </form>

                            <a href="ver_detalles.php?id=<?php echo $equipo['id']; ?>" class="btn btn-sm btn-info ms-2">Detalles</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center">No hay equipos registrados para este curso. Sube la lista de estudiantes.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
    </div>
</body>
</html>