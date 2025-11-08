<?php
require 'db.php';
verificar_sesion(true); // Solo docentes

$criterios = $conn->query("SELECT * FROM criterios ORDER BY orden ASC");
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
        <div class="container">
            <a class="navbar-brand" href="dashboard_docente.php">Panel del Docente</a>
            <a class="btn btn-outline-light" href="dashboard_docente.php">Volver al Dashboard</a>
        </div>
    </nav>

    <div class="container mt-5">
        <div class="row">
            <!-- Columna para añadir nuevo criterio -->
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

            <!-- Columna para listar criterios existentes -->
            <div class="col-md-8">
                <h4>Criterios Actuales</h4>
                <table class="table table-striped">
                    <thead><tr><th>Orden</th><th>Descripción</th><th>Estado</th><th>Acciones</th></tr></thead>
                    <tbody>
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
                                <form action="criterios_actions.php" method="POST" class="d-inline">
                                    <input type="hidden" name="id_criterio" value="<?php echo $criterio['id']; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('¿Estás seguro de que quieres eliminar este criterio?');">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>