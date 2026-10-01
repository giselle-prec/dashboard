<?php

// Autenticação do dashboard. Os usuários são os mesmos do Precabot
// (tabela Usuario), com a senha guardada como hash MD5 em Usuario.Pass.

defined('AUTH_TB_USUARIO') || define('AUTH_TB_USUARIO', 'precappapp.Usuario');

// Perfis (Usuario.PerfilId) com regras próprias no dashboard. Os demais perfis
// veem tudo que não estiver restrito a um perfil em src/rotas.php.
const AUTH_PERFIL_ADMIN     = 1;
const AUTH_PERFIL_CONSULTOR = 2;

// Usuario.photo guarda o caminho relativo ao site do Precabot (ex.:
// "uploads/31012024145502zyro-image.png").
const AUTH_URL_BASE_FOTO = 'https://precapp.net/';

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
    $_SESSION['foto']       = auth_buscar_foto($pdo, $usuario['usuario_id']);

    return true;
}

// A foto fica numa consulta à parte: um problema com essa coluna tira só a
// foto do menu, e não o login de todo mundo.
function auth_buscar_foto(PDO $pdo, $usuarioId) {
    try {
        $stmt = $pdo->prepare('SELECT photo FROM ' . AUTH_TB_USUARIO . ' WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        $foto = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('auth_buscar_foto: ' . $e->getMessage());
        return null;
    }
    return ($foto === false || $foto === null) ? null : (string)$foto;
}

// Endereço da foto de perfil, ou null quando o usuário não tem foto.
function auth_url_foto($foto) {
    $foto = trim((string)$foto);
    if ($foto === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $foto)) {
        return preg_replace('#^http://#i', 'https://', $foto);
    }
    return AUTH_URL_BASE_FOTO . ltrim($foto, '/');
}

function auth_perfil() {
    return isset($_SESSION['PerfilId']) ? (int)$_SESSION['PerfilId'] : null;
}

function auth_eh_consultor() {
    return auth_perfil() === AUTH_PERFIL_CONSULTOR;
}

function auth_usuario_id() {
    $id = (int)($_SESSION['usuario_id'] ?? 0);
    return $id > 0 ? $id : null;
}

// Uma sessão de consultor sem usuario_id não teria como ser filtrada pelos
// precatórios dele: é tratada como não logada, e não como "sem filtro".
function auth_usuario_logado() {
    if (!isset($_SESSION['user'])) {
        return false;
    }
    return !auth_eh_consultor() || auth_usuario_id() !== null;
}

// Consultor só enxerga os precatórios em que é o negociador. Devolve o id a
// usar nesse filtro, ou null para os perfis que veem todos. As APIs repassam
// o valor aos repositórios; ele vem sempre da sessão, nunca da requisição.
function auth_negociador_restrito() {
    return auth_eh_consultor() ? auth_usuario_id() : null;
}

// Se o perfil logado pode abrir a página. As regras ficam em src/rotas.php.
function auth_pode_acessar_rota($rota) {
    static $rotas = null;
    if ($rotas === null) {
        $rotas = require __DIR__ . '/rotas.php';
    }
    $rota = (string)$rota;
    if (!array_key_exists($rota, $rotas)) {
        return false;
    }
    return $rotas[$rota] === null || in_array(auth_perfil(), $rotas[$rota], true);
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
