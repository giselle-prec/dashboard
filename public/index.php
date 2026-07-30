<?php
    require __DIR__ . '/../src/connection.php';
    require __DIR__ . '/../src/crud.php';

 $read_ente = DBRead($pdo, 'Ente', 'ORDER BY Ente'); 

// Page header
$title = "Index";
 require __DIR__ . '/templates/head.php';
?>

<body>
<?php require __DIR__.'/templates/scripts.php' ?>
<?php require __DIR__.'/templates/nav_top.php' ?>


<script src="js/bootstrap.bundle.min.js"></script>
<div class="container">
    <h2>Informações Batch</h2>
    <form method="post" action="/public/batch_atualiza/process_csv.php" enctype="multipart/form-data">
    <div class="form-group">
            <label for="exampleFormControlSelect1">Ente</label>
            <select class="form-control" id="enteSelect" name="ente_id"  onchange="fetchEnteData()">
                <option value="" selected="selected" disabled="disabled">
                    Selecione um Ente</option>
                <?php foreach($read_ente as $ente): 
                    if($ente['Aporte_mensal'] == ''){
                        $aporte ='';
                    }else{
                        $aporte = " (R$ ".number_format($ente['Aporte_mensal'], 2, ',', '.')." - Aporte Mensal)";
                    }
                    ?>
                <option value="<?php echo $ente['ente_id']?>"><?php echo $ente['Ente'].$aporte; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="exampleFormControlInput1">Aporte Mensal:</label>
            <input
                type="text"
                class="form-control"
                id="aporte_mensal"
                name="aporte_mensal">
        </div>
        <div class="form-group">
            <label for="exampleFormControlInput1">Nível de Inadinplência:</label>
            <input
                type="text"
                class="form-control"
                id="nvl_inad"
                name="nvl_inad" >
        </div>
        <div class="form-group">
            <label for="exampleFormControlInput1">Número batch:</label>
            <input
                type="text"
                class="form-control"
                id="exampleFormControlInput1"
                name="batch"
                value="<?php echo $valor_batch; ?>">
        </div>
        <div class="form-check" style="margin-top: 10px !important ">
                <input
                    class="form-check-input"
                    type="checkbox"
                    name="vlr_apt_mensal_check"
                    value="vlr_apt_mensal_check"
                    checked
                    >
                <label class="form-check-label" for="defaultCheck1">Utilizar Aporte Mensal da planilha enviada</label>
            </div>
            <div class="form-check" style="margin-top: 10px !important ">
                <input
                    class="form-check-input"
                    type="checkbox"
                    name="especial_check"
                    value="especial_check"
                    checked
                    >
                <label class="form-check-label" for="defaultCheck1">Precatório do Regime Especial</label>
            </div>
        <div class="form-group">
            <label for="exampleFormControlFile1">Selecione a tabela</label>
            <input type="file" class="form-control-file" id="exampleFormControlFile1" name="tabela_batch">
        </div>
        <button type="submit" class="btn btn-primary">Enviar</button>
    </form>
</div>
<!-- 
<script>
        function fetchEnteData() {
            const ente_id = document.getElementById('enteSelect').value;
            
            if (ente_id) {
                // Faz a requisição AJAX para buscar os dados do usuário
                fetch(`redis/get_ente_detail.php?ente_id=${ente_id}`)
                    .then(response => response.json())
                    .then(data => {
                        console.log('Dados recebidos:', data);
                        // Popula os inputs com os dados recebidos
                        document.getElementById('aporte_mensal').value = data[0].Aporte_mensal;
                        document.getElementById('nvl_inad').value = data[0].nvl_inad;
                    })
                    .catch(error => console.error('Erro ao buscar os dados:', error));
            } else {
                // Se não houver usuário selecionado, limpa os campos
                document.getElementById('aporte_mensal').value = '';
                document.getElementById('nvl_inad').value = '';
            }
        }
    </script> -->
<?php require __DIR__ . '/templates/footer.php'; ?>
</body>
</html>
