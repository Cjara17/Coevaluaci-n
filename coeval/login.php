<?php
require 'db.php';

// Polyfill para compatibilidad con PHP < 8.0
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        if ('' === $needle) return true;
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    
    // Tu lógica de dominios permitidos se mantiene
    $dominios_permitidos = ["@alu.uct.cl", "@uct.cl"];
    $valido = false;
    foreach ($dominios_permitidos as $dominio) {
        if (str_ends_with($email, $dominio)) {
            $valido = true;
            break;
        }
    }
    if (!$valido) {
        header("Location: index.php?error=Correo no válido. Usa tu correo institucional.");
        exit();
    }

    // Consulta a la BD actualizada para obtener también la contraseña
    $stmt = $conn->prepare("SELECT id, nombre, password, id_equipo, es_docente FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows == 1) {
        $usuario = $resultado->fetch_assoc();

        // --- NUEVA LÓGICA DE VERIFICACIÓN DE CONTRASEÑA ---
        if ($usuario['es_docente']) {
            $password_ingresada = $_POST['password'] ?? '';
            
            // Si es docente, su campo 'password' en la BD no debe ser nulo.
            // Y la contraseña ingresada debe coincidir con la hasheada.
            if ($usuario['password'] === null || !password_verify($password_ingresada, $usuario['password'])) {
                header("Location: index.php?error=Correo o contraseña incorrectos.");
                exit();
            }
        }
        // Si no es docente, se salta esta verificación y continúa.

        // Si la verificación fue exitosa (o no fue necesaria), creamos la sesión.
        $_SESSION['id_usuario'] = $usuario['id'];
        $_SESSION['nombre'] = $usuario['nombre'];
        $_SESSION['id_equipo'] = $usuario['id_equipo'];
        $_SESSION['es_docente'] = $usuario['es_docente'];

        // Redirigir según el rol
        if ($usuario['es_docente']) {
            header("Location: dashboard_docente.php");
        } else {
            header("Location: dashboard_estudiante.php");
        }
        exit();

    } else {
        header("Location: index.php?error=El correo no está registrado en el sistema.");
        exit();
    }

    $stmt->close();
    $conn->close();
}
?>