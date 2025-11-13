<?php
session_start();
require 'db.php';

// Procesar el formulario POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Verificar si el usuario existe en la base de datos
    $stmt = $conn->prepare("SELECT id, nombre, password, id_equipo, es_docente, id_curso FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows == 0) {
        header("Location: index.php?error=Correo no encontrado");
        exit();
    }
    
    $usuario = $resultado->fetch_assoc();
    
    // Lógica de verificación de contraseña:
    // Si el usuario tiene contraseña almacenada (docente o invitado), se valida.
    $requierePassword = !empty($usuario['password']);
    if ($requierePassword) {
        if (empty($password) || !password_verify($password, $usuario['password'])) {
            header("Location: index.php?error=Correo o contraseña incorrectos");
            exit();
        }
    }
    
    // Crear la sesión del usuario
    $_SESSION['id_usuario'] = $usuario['id'];
    $_SESSION['nombre'] = $usuario['nombre'];
    $_SESSION['id_equipo'] = $usuario['id_equipo'];
    $_SESSION['es_docente'] = $usuario['es_docente'];
    $_SESSION['last_activity'] = time();
    
    // Redirigir según el rol del usuario
    if ($usuario['es_docente']) {
        // Si el docente tuviera un curso asociado por defecto se podría setear aquí:
        if (!empty($usuario['id_curso'])) {
            $_SESSION['id_curso_activo'] = $usuario['id_curso'];
        }
        header("Location: select_course.php");
    } else {
        // Los estudiantes van a su dashboard
        $_SESSION['id_curso_activo'] = $usuario['id_curso'];
        header("Location: dashboard_estudiante.php");
    }
    exit();
}

// Capturar errores enviados por GET
$mensaje_error = isset($_GET['error']) ? $_GET['error'] : '';
$mensaje_exito = isset($_GET['success']) ? $_GET['success'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inicio de Sesión</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body class="bg-light">

  <div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="card shadow-lg p-4" style="width: 380px;">
      <h3 class="text-center mb-4 text-primary">Inicio de Sesión</h3>
      <form action="login.php" method="POST" novalidate>
        
        <!-- Email -->
        <div class="mb-3">
          <label for="email" class="form-label">Correo institucional</label>
          <input type="email" class="form-control" id="email" name="email" placeholder="nombre@alu.uct.cl o usuario@uct.cl" required>
        </div>

        <!-- Contraseña (solo para docentes) -->
        <div class="mb-3" id="password-field" style="display: none;">
          <label for="password" class="form-label">Contraseña</label>
          <input type="password" class="form-control" id="password" name="password" placeholder="Ingresa tu contraseña">
          <div class="form-text">Solo los docentes deben ingresar contraseña.</div>
        </div>

        <!-- Mensajes -->
        <?php if ($mensaje_error): ?>
          <div class="alert alert-danger py-2 text-center"><?= htmlspecialchars($mensaje_error) ?></div>
        <?php endif; ?>

        <?php if ($mensaje_exito): ?>
          <div class="alert alert-success py-2 text-center"><?= htmlspecialchars($mensaje_exito) ?></div>
        <?php endif; ?>

        <!-- Botón -->
        <div class="d-grid mt-3">
          <button type="submit" class="btn btn-primary">Iniciar sesión</button>
        </div>
      </form>

      <div class="text-center mt-3">
        <small class="text-muted">© 2025 Instituto Tecnológico TEC-UCT</small>
      </div>
    </div>
  </div>

  <script>
    document.getElementById('email').addEventListener('input', function() {
      const email = this.value;
      const passwordField = document.getElementById('password-field');
      
      // Si el correo NO termina en '@alu.uct.cl', es un docente (necesita contraseña)
      if (email.length > 0 && !email.endsWith('@alu.uct.cl')) {
        passwordField.style.display = 'block';
        document.getElementById('password').required = true;
      } else {
        passwordField.style.display = 'none';
        document.getElementById('password').required = false;
      }
    });
  </script>

</body>
</html>
