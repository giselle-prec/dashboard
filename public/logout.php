<?php
    require __DIR__ . '/../src/auth.php';

    auth_logout();
    header('Location: login.php');
    exit;
