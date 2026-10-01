<?php

// Páginas que podem ser abertas e os perfis (Usuario.PerfilId) que podem
// abrir cada uma; null = qualquer perfil. Uma página nova precisa entrar
// aqui, senão src/guarda.php manda para a 404. O menu (templates/nav_top.php)
// também esconde os itens que o perfil não pode abrir.
return [
    'index'      => null,
    'batch'      => [AUTH_PERFIL_ADMIN],
    'prospeccao' => null,
    'oxigenacao' => null,
    'precabot'   => null,
    'tjrj'       => null,
    'teste'      => null,
    'login'      => null,
    '404'        => null,
];
