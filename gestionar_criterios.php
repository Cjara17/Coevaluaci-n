<?php
require 'db.php';
// Requerir ser docente Y tener un curso activo
verificar_sesion(true);

$id_curso_activo = isset($_SESSION['id_curso_activo']) ? $_SESSION['id_curso_activo'] : null;

if (!$id_curso_activo) {
    header("Location: select_course.php");
    exit();
}

// Consulta CLAVE: Filtrar criterios por el curso activo
$stmt_criterios = $conn->prepare("SELECT * FROM criterios WHERE id_curso = ? ORDER BY orden ASC");
$stmt_criterios->bind_param("i", $id_curso_activo);
$stmt_criterios->execute();
$criterios = $stmt_criterios->get_result();

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

$status_message = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : '';
$error_message = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestionar Criterios de Evaluación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard_docente.php">
                <img src="logo_uct.png" alt="TEC-UCT Logo" style="height: 30px;">
                Panel Docente
            </a>
            <div class="d-flex me-4">
                <form action="set_course.php" method="POST" class="d-flex">
                    <select name="id_curso" class="form-select form-select-sm me-2" disabled>
                        <option>
                            <?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre'] . '-' . $curso_activo['anio']); ?>
                        </option>
                    </select>
                </form>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">Cerrar Sesión</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <h1>Gestionar Criterios</h1>
        <p class="lead">Curso Activo: <strong><?php echo htmlspecialchars($curso_activo['nombre_curso'] . ' ' . $curso_activo['semestre']); ?></strong></p>

        <?php if ($status_message): ?>
            <div class="alert alert-success"><?php echo $status_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><h4>Añadir Nuevo Criterio</h4></div>
                    <div class="card-body">
                        <form action="criterios_actions.php" method="POST">
                            <input type="hidden" name="action" value="add">
                            <div class="mb-3">
                                <label for="descripcion" class="form-label">Descripción del Criterio</label>
                                <textarea class="form-control" name="descripcion" id="descripcion" rows="3" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="orden" class="form-label">Orden (número menor aparece primero)</label>
                                <input type="number" class="form-control" name="orden" id="orden" value="100" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Añadir Criterio</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <h4>Criterios Actuales del Curso</h4>
                <table class="table table-striped">
                    <thead><tr><th>Orden</th><th>Descripción</th><th>Estado</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php if ($criterios->num_rows > 0): ?>
                            <?php while($criterio = $criterios->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $criterio['orden']; ?></td>
                                <td><?php echo htmlspecialchars($criterio['descripcion']); ?></td>
                                <td>
                                    <?php if ($criterio['activo']): ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form action="criterios_actions.php" method="POST" class="d-inline">
                                        <input type="hidden" name="id_criterio" value="<?php echo $criterio['id']; ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <button type="submit" class="btn btn-sm btn-warning">
                                            <?php echo $criterio['activo'] ? 'Desactivar' : 'Activar'; ?>
                                        </button>
                                    </form>
                                    <button class="btn btn-sm btn-danger" onclick="openDeleteModal(<?php echo $criterio['id']; ?>, 'criterios_actions.php')">Eliminar</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center">Aún no hay criterios definidos para este curso.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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

    <script>
        function openDeleteModal(id, action) {
            document.getElementById('delete-id').value = id;
            document.getElementById('form-delete').action = action;
            document.getElementById('delete-action').value = 'delete';
            document.getElementById('delete-id').name = 'id_criterio'; // Para criterios
            var modal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
            modal.show();
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
