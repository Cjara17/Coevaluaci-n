<?php
/**
 * Página de login para la Plataforma de Coevaluación TEC-UCT.
 *
 * Presenta un formulario de inicio de sesión para usuarios con correo institucional.
 * Incluye validación básica de formato del correo y manejo de errores simples.
 *
 * @return void Genera salida HTML para el formulario de login.
 *
 * NOTA: El archivo contiene principalmente HTML y scripts embebidos para la interfaz.
 */
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Plataforma de Coevaluación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="style.css" />
    <link rel="stylesheet" href="public/assets/css/min/index.min.css" />
</head>

<body>

    <!-- NUEVO: Se reestructuró la vista para eliminar espacio vertical entre logo y formulario -->
    <div class="login-wrapper-bg-flex">
        <div class="text-center">
            <img src="img/logo_uct.png" alt="Logo UCT" class="logo-img-lg" loading="lazy" />
        </div>

        <div class="container container-mt20">
            <div class="row justify-content-center w-100">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow login-card">
                        <div class="card-body">
                            <h3 class="card-title text-center mb-4">Plataforma de Coevaluación</h3>
                            <h4 class="card-subtitle text-center mb-4 text-muted">¡Evaluémonos!</h4>
                            <p class="text-center text-muted">Inicia sesión con tu correo institucional</p>
                            
                            <?php if (isset($_GET['error'])): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
                            <?php endif; ?>

                        <form action="login.php" method="POST">
                            <div class="mb-3">
                                <label for="email" class="form-label">Correo institucional</label>
                                <input type="text" class="form-control" id="email" name="email" required placeholder="usuario@alu.uct.cl o docente@uct.cl">
                            </div>

                            <div class="mb-3 hidden-element" id="password-field">
                                <label for="password" class="form-label">Contraseña</label>
                                <input type="password" class="form-control" id="password" name="password" />
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">Ingresar</button>
                            </div>
                        </form>

                            <div class="text-center mt-3">
                                <a href="register_docente.php" class="text-decoration-none">¿Eres docente? Regístrate aquí</a>
                            </div>
                            <!-- NUEVO: se eliminó enlace Ir al Panel Docente por falta de utilidad -->

                            <div class="text-center mt-3">
                                <small class="text-muted">© 2025 Instituto Tecnológico TEC-UCT</small>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="public/assets/js/min/login.min.js" defer></script>
</body>
</html>
