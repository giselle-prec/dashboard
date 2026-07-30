<?php   
    require __DIR__ . '/../src/connection.php';
    require __DIR__ . '/../src/crud.php';


$data = [
	['Banca' => 'John', 'MaxValor' => 102.0],
	['Banca' => 'Jane', 'MaxValor' => 155.0],
    ['Banca' => 'Alice', 'MaxValor' => 200.0]
];

$keyfield = "Banca";

echo "<pre>";
print_r(DBUpdate($pdo, 'Banca', $data, $keyfield));
echo "<br>";
// Array do update
print_r($data[0]);
echo "</pre>";


// $data = [
// 	['MinValor' => 90.1, 'MaxValor' => 150.5],
// 	['MinValor' => 85.0, 'MaxValor' => 120.0]
// ];

// $keys = ["John", "Jane"];



// $read_ente = DBRead($pdo, 'Ente', 'ORDER BY Ente'); 
// DBInsert($pdo, 'Banca', $data);
// // print_r($read_ente);
// print_r("Data inserted successfully.");

// try {
//      DBUpdate($pdo, 'Banca', ['MinValor' => 95.0, 'MaxValor' => 155.0], null);
// } catch (InvalidArgumentException $e) {
//     print_r("Não foi atualizado para evitar atualização em massa.");
//     echo "Error: " . $e->getMessage();
// }
?>
