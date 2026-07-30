<?php

$db_config_file = __DIR__ . "/config.ini";
$config = parse_ini_file($db_config_file);
$dsn ="mysql:host={$config['host']};dbname={$config['db']};charset={$config['charset']};port={$config['port']}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = new PDO($dsn, $config['user'], $config['password'], $options);

function closeDBConn($pdo) {
    $pdo = null;
}

// include "config.php";
// 	//Protege contra SQL Injections
// 	function DBEscape($data) {
// 		$link = DBConnect();
// 		if(!is_array($data))
// 			$data = mysqli_real_escape_string($link, $data);
// 		else {
// 			$arr = $data;
			
// 			foreach ($arr as $key => $value){
// 				$key 	= mysqli_real_escape_string($link, $key);
// 				$value 	= mysqli_real_escape_string($link, $value);
// 				$dados [$key] = $value;
// 			}
// 		}
// 	DBClose($link);
// 	return $data;		
// 	}
