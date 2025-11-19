<?php 
require 'db.php';
verificar_sesion(true);

$id_curso_activo = $_SESSION['id_curso_activo'];

$sql_equipos = "
    SELECT e.id, e.nombre_equipo
    FROM equipos e
    WHERE e.id_curso = ?
    ORDER BY e.nombre_equipo ASC
";
$stmt_equipos = $conn->prepare($sql_equipos);
$stmt_equipos->bind_param("i", $id_curso_activo);
$stmt_equipos->execute();
$equipos = $stmt_equipos->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Privado</title>

    <!-- Bootstrap + estilos globales -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">

    <!-- Estilos propios de este dashboard -->
    <link rel="stylesheet" href="public/assets/css/dashboard_privado.css">
</head>

<body class="bg-light">

    <div class="container mt-5">

        <h1 class="mb-4 text-center fw-bold text-primary">
            Dashboard Privado del Curso
        </h1>

        <div class="row g-4">

            <!-- LISTA DE EQUIPOS -->
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Equipos del Curso</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($equipos->num_rows > 0): ?>
                            <ul class="list-group">
                                <?php while($equipo = $equipos->fetch_assoc()): ?>
                                    <li class="list-group-item">
                                        <strong><?php echo htmlspecialchars($equipo['nombre_equipo']); ?></strong>
                                    </li>
                                <?php endwhile; ?>
                            </ul>
                        <?php else: ?>
                            <div class="alert alert-info text-center">
                                No hay equipos registrados en este curso.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- PANEL DE CALIFICACIONES -->
            <div class="col-md-6">
                <div class="card shadow h-100">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Calificaciones</h5>
                    </div>
                    <div class="card-body">

                        <button id="btn-toggle-notas" class="btn btn-dark w-100 mb-3">
                            Mostrar / Ocultar notas
                        </button>

                        <div id="panel-calificaciones" class="blur-notas p-3 border rounded bg-white">
                            <!-- Información cargada vía AJAX -->
                        </div>

                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="public/assets/js/dashboard_privado.js"></script>

</body>
</html>
