<?php
require 'db.php';
verificar_sesion();

$mensaje = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : "El tiempo para esta evaluación ha expirado.";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tiempo Agotado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-body text-center">
                        <h1 class="card-title text-warning">⏰ Tiempo Agotado</h1>
                        <p class="card-text"><?php echo $mensaje; ?></p>
                        <p class="text-muted">No es posible realizar modificaciones adicionales a esta evaluación.</p>
                        <a href="<?php echo $_SESSION['es_docente'] ? 'dashboard_docente.php' : 'dashboard_estudiante.php'; ?>" class="btn btn-primary">
                            Volver al Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
