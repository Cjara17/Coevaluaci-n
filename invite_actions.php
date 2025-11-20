<?php
require 'db.php';
require_once __DIR__ . '/invite_helpers.php';
verificar_sesion(true);

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_POST['action'])) {
    header("Location: dashboard_docente.php");
    exit();
}

$action = $_POST['action'];

function obtener_invitado(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("
        SELECT id, nombre, email
        FROM usuarios
        WHERE id = ?
          AND es_docente = 0
          AND id_equipo IS NULL
          AND id_curso IS NULL
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $usuario = $resultado->fetch_assoc();
    $stmt->close();
    return $usuario ?: null;
}

if ($action === 'update_invite') {
    $id_usuario = isset($_POST['id_usuario']) ? (int)$_POST['id_usuario'] : 0;
    $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password_plain = isset($_POST['password']) ? trim($_POST['password']) : '';

    if ($id_usuario === 0 || $nombre === '' || $username === '') {
        header("Location: dashboard_docente.php?error=" . urlencode("Datos incompletos para actualizar el invitado."));
        exit();
    }

    $invitado = obtener_invitado($conn, $id_usuario);
    if (!$invitado) {
        header("Location: dashboard_docente.php?error=" . urlencode("El invitado no existe o no puede modificarse."));
        exit();
    }

    if (preg_match('/\s/', $username)) {
        header("Location: dashboard_docente.php?error=" . urlencode("El usuario no debe contener espacios."));
        exit();
    }

    $stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE email = ? AND id <> ?");
    $stmt_check->bind_param("si", $username, $id_usuario);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        $stmt_check->close();
        header("Location: dashboard_docente.php?error=" . urlencode("El usuario ya está registrado."));
        exit();
    }
    $stmt_check->close();

    $password_hash = null;
    if ($password_plain !== '') {
        if (strlen($password_plain) < 6) {
            header("Location: dashboard_docente.php?error=" . urlencode("La contraseña debe tener al menos 6 caracteres."));
            exit();
        }
        $password_hash = password_hash($password_plain, PASSWORD_DEFAULT);
    }

    if ($password_hash) {
        $stmt_update = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ?, password = ? WHERE id = ?");
        $stmt_update->bind_param("sssi", $nombre, $username, $password_hash, $id_usuario);
    } else {
        $stmt_update = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?");
        $stmt_update->bind_param("ssi", $nombre, $username, $id_usuario);
    }
    $stmt_update->execute();
    $stmt_update->close();

    upsert_invite_credential($id_usuario, $username, $password_plain !== '' ? $password_plain : null);

    header("Location: dashboard_docente.php?status=" . urlencode("Invitado actualizado correctamente."));
    exit();
}

if ($action === 'delete_invite') {
    $id_usuario = isset($_POST['id_usuario']) ? (int)$_POST['id_usuario'] : 0;
    if ($id_usuario === 0) {
        header("Location: dashboard_docente.php?error=" . urlencode("No se pudo identificar al invitado a eliminar."));
        exit();
    }

    $invitado = obtener_invitado($conn, $id_usuario);
    if (!$invitado) {
        header("Location: dashboard_docente.php?error=" . urlencode("El invitado no existe o no puede eliminarse."));
        exit();
    }

    $stmt_delete = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt_delete->bind_param("i", $id_usuario);
    $stmt_delete->execute();
    $stmt_delete->close();

    remove_invite_credential($id_usuario);

    header("Location: dashboard_docente.php?status=" . urlencode("Invitado eliminado correctamente."));
    exit();
}

header("Location: dashboard_docente.php");
exit();

