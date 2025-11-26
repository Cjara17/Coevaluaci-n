<?php
session_start();
require 'db.php';

// Procesar el formulario POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email)) {
        header("Location: index.php?error=Por favor ingrese un correo o usuario");
        exit();
    }
    
    // Normalizar el email: convertir a minúsculas y eliminar espacios
    $email_normalizado = strtolower(trim($email));
    
    // Verificar si el usuario existe en la base de datos (búsqueda case-insensitive)
    $stmt = $conn->prepare("SELECT id, nombre, password, id_equipo, es_docente, id_curso, email FROM usuarios WHERE LOWER(TRIM(email)) = ?");
    $stmt->bind_param("s", $email_normalizado);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows == 0) {
        header("Location: index.php?error=Correo no encontrado: " . htmlspecialchars($email));
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
    
    // Crear la sesión del usuario con regeneración de ID para seguridad
    session_regenerate_id(true);
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
