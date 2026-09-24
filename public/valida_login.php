<?php
    require __DIR__ . '/../src/connection.php';
    require __DIR__ . '/../src/auth.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: login.php');
        exit;
    }

    $post_user = $_POST['email'] ?? '';
    $post_password = $_POST['password'] ?? '';
    if ($post_user === '' || $post_password === '') {
        header('Location: login.php?error=1');
        exit;
    }

    if (auth_tentar_login($pdo, $post_user, $post_password)) {
        header('Location: index.php');
        exit;
    }

    header('Location: login.php?error=2');
    exit;
