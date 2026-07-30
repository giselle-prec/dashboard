<?php

function DBread($conn, $table, $params = null, $fields ='*'){
	$params = ($params) ? " {$params}" : null; 

	$query = "SELECT {$fields} FROM {$table}{$params}";
	$stmt = $conn->prepare($query);
	$stmt->execute();
	$data = $stmt->fetchAll();
	if (empty($data)) {
		return false;
	} else {
		return $data;
	}
}


function DBInsert($conn, $table, array $data){

	# Criar string com "?" separados por vírgula.
	# Essa string é utilizada pelo PDO para saber aonde colocar valores
	$keyvals = array();
	for ($n=0; $n < count($data[0]); $n++) { 
		$keyvals[] = '?';
	}

	# String ?, ?, ?
	$values = implode(', ', $keyvals);
	# String campo1, campo2, campo3
	$fields = implode(', ', array_keys($data[0]));
	
	# INSERT INTO TABELA (campo1, campo2, campo3) VALUES (?, ?, ?)
	$query = "INSERT INTO {$table} ({$fields}) VALUES ({$values})";
	$stmt = $conn->prepare($query);

	# Define início de uma transação do banco de dados
	$conn->beginTransaction();

	foreach ($data as $row){
		# O método execute recebe um array com os valores a serem inseridos
		# nos campos representados pelos "?" na query
		$stmt->execute(array_values($row));
	}

	# Finaliza a transação e executa
	$conn->commit();
}

function DBUpdate($conn, $table, array $data, $where){
	if (is_null($where)) {
		throw new InvalidArgumentException("WHERE é obrigatório para evitar atualizações em massa.");
	}
	$fields = '';

	foreach ($data[0] as $key => $value) {
		if($key != $where){
			$fields .= $key." = :".$key.", ";
		}
	}
	$fields = substr($fields, 0, -2);
	
	$where_clause = "{$where} = :{$where}";
	$query = "UPDATE {$table} SET {$fields} WHERE {$where_clause}";
	
	$stmt = $conn->prepare($query);
	# Define início de uma transação do banco de dados
	$conn->beginTransaction();

	for($n=0; $n< count($data); $n++){
		$stmt->execute($data[$n]);
	}

	# Finaliza a transação e executa
	$conn->commit();
}
function DBUpdateNew($conn, $table, array $data, $where){
	// $exemplo_sql =" UPDATE table
	// 	SET column2 = (CASE column1 WHEN 1 THEN 'val1'
	// 					WHEN 2 THEN 'val2'
	// 					WHEN 3 THEN 'val3'
	// 			END)
	// 	WHERE column1 IN(1, 2 ,3)";

	// $sql = "UPDATE {$table}
	// 	SET ";
	// foreach($data as $key => $value){
	// 	$sql .= $key." = (CASE {$where} WHEN {$where} THEN {$value} END"
	// }

	
	
	echo " TESTE NOVO UPDATE: ".$sql;

	# Criar string com "?" separados por vírgula.
	# Essa string é utilizada pelo PDO para saber aonde colocar valores
	$keyvals = array();
	for ($n=0; $n < count($data[0]); $n++) { 
		$keyvals[] = '?';
	}

	# String ?, ?, ?
	//$fields = implode(', ', $keyvals);
	# String campo1, campo2, campo3
	$values = ":".implode(', :', array_keys($data[0]));
	$fields ="";
	foreach ($data[0] as $key => $value) {
		$fields .= "{$key}, ";
		if($key != $where){
			$on_update .= $key." = VALUES(".$key."), ";
		}
	}
	$fields = substr($fields, 0, -2);
	// $fields = implode(", ", $data[0]);
	$on_update = substr($on_update, 0, -2);
	# INSERT INTO TABELA (campo1, campo2, campo3) VALUES (?, ?, ?)
	$query = "INSERT INTO {$table} ({$fields}) VALUES ({$values}) 
    ON DUPLICATE KEY UPDATE {$on_update}";
	
	$stmt = $conn->prepare($query);
	# Define início de uma transação do banco de dados
	$conn->beginTransaction();

	for($n=0; $n< count($data); $n++){
		$stmt->execute($data[$n]);
	}

	# Finaliza a transação e executa
	$conn->commit();
	echo $query;
}
?>