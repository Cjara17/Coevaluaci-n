// Fichero: evaluacion_exitosa.php (Nuevo Archivo)

<?php
require 'db.php';
// Solo requerir sesión activa, no importa si es docente o estudiante, aunque es flujo de estudiante
verificar_sesion(); 

$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'Tu acción se ha completado con éxito.';

// Determinar a dónde redirigir
$dashboard_url = $_SESSION['es_docente'] ? 'dashboard_docente.php' : 'dashboard_estudiante.php';
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
    </div>
</body>
</html>