<?php

// Autenticação do dashboard. Os usuários são os mesmos do Precabot
// (tabela Usuario), com a senha guardada como hash MD5 em Usuario.Pass.

defined('AUTH_TB_USUARIO') || define('AUTH_TB_USUARIO', 'precappapp.Usuario');

function auth_iniciar_sessao() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Mesmo hash do sistema antigo (hashPassword): não alterar, senão as senhas
// já gravadas no banco deixam de ser aceitas.
function auth_hash_senha($senha) {
    return hash("MD5", $senha);
}

function auth_tentar_login(PDO $pdo, $email, $senha) {
    $stmt = $pdo->prepare(
        'SELECT usuario_id, Email, Pass, PerfilId FROM ' . AUTH_TB_USUARIO . ' WHERE Email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || auth_hash_senha($senha) !== $usuario['Pass']) {
        return false;
    }

    auth_iniciar_sessao();
    // Novo id de sessão no login: impede que um id plantado antes do login
    // (session fixation) passe a valer como sessão autenticada.
    session_regenerate_id(true);
    $_SESSION['user']       = $usuario['Email'];
    $_SESSION['PerfilId']   = $usuario['PerfilId'];
    $_SESSION['usuario_id'] = $usuario['usuario_id'];

    return true;
}

function auth_usuario_logado() {
    return isset($_SESSION['user']);
}

// Para os endpoints JSON: em vez de redirecionar, responde 401 para que o
// JavaScript da página mande o usuário de volta ao login.
function auth_exigir_login_api() {
    auth_iniciar_sessao();
    if (auth_usuario_logado()) {
        return;
    }
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'erro'  => 'Sessão expirada. Faça login novamente.',
        'login' => true,
    ]);
    exit;
}

function auth_logout() {
    auth_iniciar_sessao();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
