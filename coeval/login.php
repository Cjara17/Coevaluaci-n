<?php
session_start();

// Capturar errores enviados por GET
$mensaje_error = isset($_GET['error']) ? $_GET['error'] : '';
$mensaje_exito = isset($_GET['success']) ? $_GET['success'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inicio de Sesión - Docentes</title>
  <link rel="stylesheet" href="css/styles.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

  <div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="card shadow-lg p-4" style="width: 380px;">
      <h3 class="text-center mb-4 text-primary">Inicio de Sesión</h3>
      <form action="login.php" method="POST" novalidate>
        
        <!-- Email -->
        <div class="mb-3">
          <label for="email" class="form-label">Correo institucional</label>
          <input type="email" class="form-control" id="email" name="email" placeholder="nombre@alu.uct.cl" required>
        </div>

<<<<<<< HEAD
    // Consulta a la BD actualizada para obtener también la contraseña y el id_curso del estudiante
    $stmt = $conn->prepare("SELECT id, nombre, password, id_equipo, es_docente, id_curso FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $resultado = $stmt->get_result();
=======
        <!-- Contraseña (solo para docentes) -->
        <div class="mb-3">
          <label for="password" class="form-label">Contraseña</label>
          <input type="password" class="form-control" id="password" name="password" placeholder="Ingresa tu contraseña">
          <div class="form-text">Solo los docentes deben ingresar contraseña.</div>
        </div>
>>>>>>> 9f138c1ff81b044a7d1760d461ad8a8128013b70

        <!-- Mensajes -->
        <?php if ($mensaje_error): ?>
          <div class="alert alert-danger py-2 text-center"><?= htmlspecialchars($mensaje_error) ?></div>
        <?php endif; ?>

<<<<<<< HEAD
        // --- LÓGICA DE VERIFICACIÓN DE CONTRASEÑA ---
        if ($usuario['es_docente']) {
            $password_ingresada = $_POST['password'] ?? '';
            
            if ($usuario['password'] === null || !password_verify($password_ingresada, $usuario['password'])) {
                header("Location: index.php?error=Correo o contraseña incorrectos.");
                exit();
            }
        }

        // Si la verificación fue exitosa (o no fue necesaria), creamos la sesión.
        $_SESSION['id_usuario'] = $usuario['id'];
        $_SESSION['nombre'] = $usuario['nombre'];
        $_SESSION['id_equipo'] = $usuario['id_equipo'];
        $_SESSION['es_docente'] = $usuario['es_docente'];
        
        // Si el usuario es estudiante, guardamos su id_curso directamente en la sesión
        // Los docentes lo establecerán en select_course.php
        if (!$usuario['es_docente']) {
             $_SESSION['id_curso_activo'] = $usuario['id_curso'];
        }

        // --- NUEVA LÓGICA DE REDIRECCIÓN SEGÚN ROL ---
        if ($usuario['es_docente']) {
            // REDIRECCIÓN CLAVE: El docente debe ir a seleccionar su curso
            header("Location: select_course.php");
        } else {
            // El estudiante va directo a su dashboard (asumiendo que ya tiene un curso asignado)
            header("Location: dashboard_estudiante.php");
        }
        exit();
=======
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
>>>>>>> 9f138c1ff81b044a7d1760d461ad8a8128013b70

</body>
</html>
