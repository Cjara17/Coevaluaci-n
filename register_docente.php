<?php
/**
 * Maneja el registro de nuevos docentes mediante formulario.
 *
 * Valida nombre, correo institucional (@uct.cl), contraseña y confirmación.
 * Verifica cliente si el correo ya está registrado.
 * Encripta contraseña usando password_hash antes de insertar en BD.
 *
 * Utiliza variables superglobales:
 * @global string $_SERVER['REQUEST_METHOD'] Método HTTP para determinar POST.
 * @global string $_POST['nombre'] Nombre completo del docente enviado por POST.
 * @global string $_POST['email'] Correo institucional enviado por POST.
 * @global string $_POST['password'] Contraseña enviada por POST.
 * @global string $_POST['confirm_password'] Confirmación de contraseña enviada por POST.
 *
 * Redirige a:
 * - index.php con mensaje de éxito tras registro.
 * - Permanece en la misma página mostrando errores si hay validaciones fallidas.
 *
 * @return void Procesa registro y renderiza formulario con posibles errores.
 */
require 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    $errors = [];

    // Validar nombre
    if (empty($nombre)) {
        $errors[] = "El nombre es obligatorio.";
    }

    // Validar email
    if (empty($email)) {
        $errors[] = "El correo es obligatorio.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "El correo no es válido.";
    } elseif (!str_ends_with($email, '@uct.cl')) {
        $errors[] = "Solo se permiten correos institucionales (@uct.cl).";
    }

    // Validar contraseña
    if (empty($password)) {
        $errors[] = "La contraseña es obligatoria.";
    } elseif (strlen($password) < 6) {
        $errors[] = "La contraseña debe tener al menos 6 caracteres.";
    }

    // Confirmar contraseña
    if ($password !== $confirm_password) {
        $errors[] = "Las contraseñas no coinciden.";
    }

    if (empty($errors)) {
        // Verificar si el email ya existe
        $stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            $errors[] = "El correo ya está registrado.";
        } else {
            // Hash de la contraseña
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insertar docente
            $stmt = $conn->prepare("INSERT INTO usuarios (nombre, email, password, es_docente) VALUES (?, ?, ?, TRUE)");
            $stmt->bind_param("sss", $nombre, $email, $hashed_password);
            if ($stmt->execute()) {
                header("Location: index.php?success=Registro exitoso. Ahora puedes iniciar sesión.");
                exit();
            } else {
                $errors[] = "Error al registrar. Inténtalo de nuevo.";
            }
            $stmt->close();
        }
        $stmt_check->close();
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Docente - Plataforma de Evaluación</title>
    <link rel="icon" href="img/favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<body>
    <!-- NUEVO: Vista reestructurada para alinear logo y formulario como en index.php -->
    <div class="register-wrapper" style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; background-image: url('img/fondoAraucarias.png'); background-size: cover; background-position: center; background-attachment: fixed; background-repeat: no-repeat;">
        <!-- NUEVO: logo UCT movido a la esquina superior izquierda -->
        <div style="position:absolute; top:20px; left:20px;">
            <img src="img/logo_uct.png" style="height:80px;">
        </div>

        <div class="container" style="margin-top: 20px;">
            <div class="row justify-content-center w-100">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow login-card">
                        <div class="card-body">
                            <h3 class="card-title text-center mb-4">Registro de Docente</h3>
                            <p class="text-center text-muted">Regístrate con tu correo institucional</p>

                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <ul>
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <form action="register_docente.php" method="POST">
                                <div class="mb-3">
                                    <label for="nombre" class="form-label">Nombre Completo</label>
                                    <input type="text" class="form-control" id="nombre" name="nombre" required placeholder="Tu nombre completo">
                                </div>

                                <div class="mb-3">
                                    <label for="email" class="form-label">Correo Institucional</label>
                                    <input type="email" class="form-control" id="email" name="email" required placeholder="usuario@uct.cl">
                                </div>

                                <div class="mb-3">
                                    <label for="password" class="form-label">Contraseña</label>
                                    <input type="password" class="form-control" id="password" name="password" required minlength="6">
                                </div>

                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirmar Contraseña</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">Registrarse</button>
                                </div>
                            </form>

                            <div class="text-center mt-3">
                                <a href="index.php">¿Ya tienes cuenta? Inicia sesión</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Validación adicional en el cliente
        document.getElementById('email').addEventListener('input', function() {
            const email = this.value;
            const submitBtn = document.querySelector('button[type="submit"]');
            if (email.length > 0 && !email.endsWith('@uct.cl')) {
                this.setCustomValidity('Solo se permiten correos @uct.cl');
            } else {
                this.setCustomValidity('');
            }
        });

        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            if (this.value !== password) {
                this.setCustomValidity('Las contraseñas no coinciden');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
