<?php
include 'header.php';
require 'db.php';
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

// Obtener información del curso activo
$stmt_curso = $conn->prepare("SELECT nombre_curso, semestre, anio FROM cursos WHERE id = ?");
$stmt_curso->bind_param("i", $id_curso_activo);
$stmt_curso->execute();
$curso_activo = $stmt_curso->get_result()->fetch_assoc();
$stmt_curso->close();

// Obtener todos los estudiantes del curso activo
$sql_estudiantes = "
    SELECT u.id, u.nombre, u.email, u.id_equipo, e.nombre_equipo
    FROM usuarios u
    LEFT JOIN equipos e ON u.id_equipo = e.id
    WHERE u.es_docente = 0 
    AND u.id_curso = ?
    AND (u.id_equipo IS NULL OR e.id_curso = ?)
    ORDER BY u.nombre ASC
";
$stmt_estudiantes = $conn->prepare($sql_estudiantes);
$stmt_estudiantes->bind_param("ii", $id_curso_activo, $id_curso_activo);
$stmt_estudiantes->execute();
$estudiantes = $stmt_estudiantes->get_result();

// Obtener todos los equipos del curso activo
$sql_equipos = "
    SELECT e.id, e.nombre_equipo, e.estado_presentacion,
           COUNT(u.id) as total_estudiantes
    FROM equipos e
    LEFT JOIN usuarios u ON e.id = u.id_equipo
    WHERE e.id_curso = ?
    GROUP BY e.id, e.nombre_equipo, e.estado_presentacion
    ORDER BY e.nombre_equipo ASC
";
$stmt_equipos = $conn->prepare($sql_equipos);
$stmt_equipos->bind_param("i", $id_curso_activo);
$stmt_equipos->execute();
$equipos = $stmt_equipos->get_result();

$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Estudiantes y Equipos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
</head>
<body style="padding-bottom: 120px;">
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1>Estudiantes y Equipos</h1>
                <p class="text-muted mb-0">Curso: <strong><?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre'] . '-' . $curso_activo['anio']); ?></strong></p>
            </div>
            <a href="dashboard_docente.php" class="btn btn-secondary">← Volver al Dashboard</a>
        </div>

        <?php if ($status_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $status_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Columna izquierda: Estudiantes -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Estudiantes</h5>
                        <span class="badge bg-primary"><?php echo $estudiantes->num_rows; ?> estudiantes</span>
                    </div>
                    <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                        <?php if ($estudiantes->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="selectAllEstudiantes" title="Seleccionar todos">
                                        </th>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th>Equipo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($estudiante = $estudiantes->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="estudiante-checkbox" 
                                                   name="estudiantes[]" 
                                                   value="<?php echo $estudiante['id']; ?>"
                                                   data-nombre="<?php echo htmlspecialchars($estudiante['nombre']); ?>">
                                        </td>
                                        <td><?php echo htmlspecialchars($estudiante['nombre']); ?></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($estudiante['email']); ?></small></td>
                                        <td>
                                            <?php if ($estudiante['nombre_equipo']): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($estudiante['nombre_equipo']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Sin equipo</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No hay estudiantes registrados en este curso.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Columna derecha: Equipos -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Equipos</h5>
                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalCrearEquipo">
                            + Crear Equipo
                        </button>
                    </div>
                    <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                        <?php if ($equipos->num_rows > 0): ?>
                            <div class="list-group">
                                <?php while ($equipo = $equipos->fetch_assoc()): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></h6>
                                            <small class="text-muted">
                                                <?php echo $equipo['total_estudiantes']; ?> estudiante(s)
                                                <?php if ($equipo['estado_presentacion'] == 'presentando'): ?>
                                                    <span class="badge bg-success ms-2">Presentando</span>
                                                <?php elseif ($equipo['estado_presentacion'] == 'finalizado'): ?>
                                                    <span class="badge bg-secondary ms-2">Finalizado</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark ms-2">Pendiente</span>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-warning btn-sm"
                                                    onclick="abrirModalEditarEquipo(<?php echo $equipo['id']; ?>, <?php echo json_encode($equipo['nombre_equipo']); ?>)"
                                                    title="Editar equipo">
                                                Editar
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-sm" 
                                                    onclick="confirmarEliminarEquipo(<?php echo $equipo['id']; ?>, '<?php echo htmlspecialchars($equipo['nombre_equipo'], ENT_QUOTES); ?>')"
                                                    title="Eliminar equipo">
                                                Eliminar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center">No hay equipos creados. Crea uno para comenzar.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Crear Equipo -->
    <div class="modal fade" id="modalCrearEquipo" tabindex="-1" aria-labelledby="modalCrearEquipoLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCrearEquipoLabel">Crear Nuevo Equipo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="equipos_actions.php" method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nombre_equipo_nuevo" class="form-label">Nombre del Equipo</label>
                            <input type="text" class="form-control" id="nombre_equipo_nuevo" name="nombre_equipo" required>
                        </div>
                        <hr>
                        <h6 class="mb-3">Agregar Estudiantes al Equipo</h6>
                        <p class="text-muted small">Selecciona los estudiantes que deseas agregar al equipo:</p>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="selectAllCrear" title="Seleccionar todos">
                                        </th>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th>Equipo Actual</th>
                                    </tr>
                                </thead>
                                <tbody id="tablaEstudiantesCrear">
                                    <!-- Se llenará dinámicamente -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Crear Equipo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Editar Equipo -->
    <div class="modal fade" id="modalEditarEquipo" tabindex="-1" aria-labelledby="modalEditarEquipoLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalEditarEquipoLabel">Editar Equipo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="equipos_actions.php" method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id_equipo" id="edit_id_equipo">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nombre_equipo_edit" class="form-label">Nombre del Equipo</label>
                            <input type="text" class="form-control" id="nombre_equipo_edit" name="nombre_equipo" required>
                        </div>
                        <hr>
                        
                        <!-- Estudiantes actuales del equipo -->
                        <h6 class="mb-3">Estudiantes Actuales del Equipo</h6>
                        <div id="estudiantesActualesContainer" style="max-height: 200px; overflow-y: auto;">
                            <div class="table-responsive">
                                <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th style="width: 100px;">Acción</th>
                                    </tr>
                                </thead>
                                <tbody id="tablaEstudiantesActuales">
                                    <!-- Se llenará dinámicamente -->
                                </tbody>
                            </table>
                        </div>
                        
                        <hr>
                        
                        <!-- Agregar nuevos estudiantes -->
                        <h6 class="mb-3">Agregar Estudiantes al Equipo</h6>
                        <p class="text-muted small">Selecciona los estudiantes que deseas agregar:</p>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="selectAllEditar" title="Seleccionar todos">
                                        </th>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th>Equipo Actual</th>
                                    </tr>
                                </thead>
                                <tbody id="tablaEstudiantesEditar">
                                    <!-- Se llenará dinámicamente -->
                                </tbody>
                            </table>
                            </div>
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

    <!-- Formulario oculto para eliminar estudiante de equipo -->
    <form id="formEliminarEstudianteEquipo" action="equipos_actions.php" method="POST" style="display: none;">
        <input type="hidden" name="action" value="remove_student">
        <input type="hidden" name="id_estudiante" id="remove_student_id">
        <input type="hidden" name="id_equipo" id="remove_student_equipo">
    </form>

    <!-- Formulario oculto para eliminar equipo -->
    <form id="formEliminarEquipo" action="equipos_actions.php" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id_equipo" id="delete_id_equipo">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Seleccionar todos los estudiantes en la lista principal
        document.getElementById('selectAllEstudiantes')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.estudiante-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });

        // Seleccionar todos en el modal de crear
        document.getElementById('selectAllCrear')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('#tablaEstudiantesCrear input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });

        // Seleccionar todos en el modal de editar
        document.getElementById('selectAllEditar')?.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('#tablaEstudiantesEditar input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });

        // Cargar estudiantes disponibles cuando se abre el modal de crear
        document.getElementById('modalCrearEquipo')?.addEventListener('shown.bs.modal', function() {
            cargarEstudiantesDisponibles('crear');
        });

        // Variable global para almacenar el id del equipo que se está editando
        let idEquipoEditando = 0;

        // Función para cargar estudiantes disponibles
        function cargarEstudiantesDisponibles(tipo, idEquipo = 0) {
            fetch(`equipos_actions.php?action=get_available_students&id_equipo=${idEquipo}`)
                .then(response => response.json())
                .then(data => {
                    const tbodyId = tipo === 'crear' ? 'tablaEstudiantesCrear' : 'tablaEstudiantesEditar';
                    const tbody = document.getElementById(tbodyId);
                    tbody.innerHTML = '';
                    
                    if (data.estudiantes && data.estudiantes.length > 0) {
                        data.estudiantes.forEach(est => {
                            const row = document.createElement('tr');
                            row.innerHTML = `
                                <td>
                                    <input type="checkbox" name="estudiantes[]" value="${est.id}">
                                </td>
                                <td>${est.nombre}</td>
                                <td><small class="text-muted">${est.email}</small></td>
                                <td>${est.equipo_actual ? `<span class="badge bg-info">${est.equipo_actual}</span>` : '<span class="text-muted">Sin equipo</span>'}</td>
                            `;
                            tbody.appendChild(row);
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No hay estudiantes disponibles</td></tr>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    const tbodyId = tipo === 'crear' ? 'tablaEstudiantesCrear' : 'tablaEstudiantesEditar';
                    document.getElementById(tbodyId).innerHTML = '<tr><td colspan="4" class="text-center text-danger">Error al cargar estudiantes</td></tr>';
                });
        }

        // Función para abrir modal de editar equipo
        function abrirModalEditarEquipo(id, nombre) {
            idEquipoEditando = id;
            document.getElementById('edit_id_equipo').value = id;
            document.getElementById('nombre_equipo_edit').value = nombre;
            
            // Cargar estudiantes actuales del equipo
            fetch(`equipos_actions.php?action=get_team_students&id_equipo=${id}`)
                .then(response => response.json())
                .then(data => {
                    const tbody = document.getElementById('tablaEstudiantesActuales');
                    tbody.innerHTML = '';
                    
                    if (data.estudiantes && data.estudiantes.length > 0) {
                        data.estudiantes.forEach(est => {
                            const row = document.createElement('tr');
                            const nombreEscapado = est.nombre.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                            row.innerHTML = `
                                <td>${est.nombre}</td>
                                <td><small class="text-muted">${est.email}</small></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="eliminarEstudianteEquipo(${est.id}, ${id}, '${nombreEscapado}')">
                                        Eliminar
                                    </button>
                                </td>
                            `;
                            tbody.appendChild(row);
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No hay estudiantes en este equipo</td></tr>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('tablaEstudiantesActuales').innerHTML = '<tr><td colspan="3" class="text-center text-danger">Error al cargar estudiantes</td></tr>';
                });
            
            // Cargar estudiantes disponibles (excluyendo los que ya están en este equipo)
            cargarEstudiantesDisponibles('editar', id);
            
            const modal = new bootstrap.Modal(document.getElementById('modalEditarEquipo'));
            modal.show();
        }

        // Función para eliminar estudiante de un equipo
        function eliminarEstudianteEquipo(idEstudiante, idEquipo, nombreEstudiante) {
            if (confirm(`¿Estás seguro de que deseas eliminar a "${nombreEstudiante}" de este equipo?`)) {
                document.getElementById('remove_student_id').value = idEstudiante;
                document.getElementById('remove_student_equipo').value = idEquipo;
                document.getElementById('formEliminarEstudianteEquipo').submit();
            }
        }

        // Función para confirmar eliminación de equipo
        function confirmarEliminarEquipo(id, nombre) {
            if (confirm(`¿Estás seguro de que deseas eliminar el equipo "${nombre}"?\n\nEsta acción eliminará el equipo y desasignará a todos sus estudiantes.`)) {
                document.getElementById('delete_id_equipo').value = id;
                document.getElementById('formEliminarEquipo').submit();
            }
        }
    </script>
</body>
</html>

