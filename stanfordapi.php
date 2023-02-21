<?php
/* @var array $errorLog */
$this->title = 'Стенфорд Апи';
?>

<div class="col-xl-12">
    <div class="card greeting justify-content-center">
        <div class="card-body">
            <div class="media">
                <div class="media-body">
                    <h4 class="text-dark mb-3 text-center">
                        Анализ последовательностей проведен
                    </h4>
                </div>
            </div>
        </div>
    </div>
</div>
<?php if ($errorLog) { ?>
<div class="layout-wrapper">
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <div class="col-xl-8">
                    <div class="row">
                        <table class="table table-striped">
                            <tr>
                                <th>Номер карты пациента</th>
                                <th>Ошибка анализа</th>
                            </tr>
                            <?php
                            foreach ($errorLog as $patientId => $errors) {
                                echo '<tr>';
                                echo '<td class="text-center">' . $patientId . '</td>';
                                echo '<td class="text-center">' . $errors['seq'][0] . '</td>';
                                echo '</tr>';
                            }
                            ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php } ?>