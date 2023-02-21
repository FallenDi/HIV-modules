<?php
/* @var $patientSequence \app\models\PatientSequence */
/* @var $resultArray array*/
use kartik\form\ActiveForm;
use kartik\select2\Select2;
use kartik\date\DatePicker;
use yii\helpers\Html;

$this->title = 'Проверка на контаминацию';
$this->params['breadcrumbs'][] = $this->title;

$form = ActiveForm::begin([
    'id' => 'form-manual-input',
    'class' => 'contamination-form',
    'options' => [
        'autocomplete' => 'off',
        'data-pjax' => 1,
    ],
]); ?>

<div class="container">
    <div class="row">
        <div class="col-sm-6">
            <?= $form->field($patientSequence, 'type')->widget(Select2::classname(), [
                'hideSearch' => true,
                'data' => Yii::$app->params['seq_type_array'],
                'theme' => 'bootstrap',
                'options' => ['placeholder' => 'Выберите участок...'],
                'pluginOptions' => [
                    'allowClear' => true
                ],
            ]); ?>
        </div>
        <div class="col-sm-6">
            <?php
            echo '<label class="form-label">Диапазон дат</label>';
            echo DatePicker::widget([
                'name' => 'from_date',
                //'value' => '13-11-2022',
                'type' => DatePicker::TYPE_RANGE,
                'name2' => 'to_date',
                'value2' => date('d-m-Y', time()),
                'options' => ['placeholder' => 'Дата начала выборки'],
                'options2' => ['placeholder' => 'Дата конца выборки'],
                'pluginOptions' => [
                    'autoclose' => true,
                    'format' => 'dd-mm-yyyy'
                ]
            ]);
            ?>
        </div>
    </div>

    <?php
    echo '<div class="tabular-submit-button text-center">' . Html::submitButton('Проверить', ['class' => 'btn btn-primary']) . '</div>';
    ActiveForm::end();

    if ($resultArray) {
        echo '<p> Проанализированно ' . $compareSeqCounter . ' последовательностей </p>';
        echo '<br>';
        echo '<br>';
        echo '<br>';
        ?>
        <table class="table table-bordered contaminaton-result">
            <thead>
                <tr class="middle-style">
                    <th class="middle-style">N п/п</th>
                    <th class="middle-style">Номер карты</th>
                    <th class="middle-style">Дата загрузки</th>
                    <th class="middle-style">Участок</th>
                    <th class="middle-style">Протяженность</th>
                    <th class="middle-style">Абсолютное генетическое сходство в %</th>
                    <th class="middle-style">Абсолютное генетическое сходство в цифрах</th>
                    <th class="middle-style">Относительное генетическое сходство в %</th>
                    <th class="middle-style">Относительное генетическое сходство в цифрах</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($resultArray as $key => $dataArray) { ?>
                <tr class="text-center">
                    <td rowspan="2" class="middle-style" ><?= $key+1 ?></td>
                    <td class="middle-style"><?= $dataArray['cardNumber1'] ?></td>
                    <td class="middle-style"><?= date('d.m.Y', $dataArray['dateCreate1']) ?></td>
                    <td class="middle-style"><?= implode($dataArray['analyzedSeq']) ?></td>
                    <td rowspan="2" class="middle-style"><?= $dataArray['length'] ?></td>
                    <td rowspan="2" class="middle-style"><?= $dataArray['absoluteMismatch'] ?></td>
                    <td rowspan="2" class="middle-style"><?= $dataArray['absMismatchNum'] . ' из ' .  $dataArray['length'] ?></td>
                    <td rowspan="2" class="middle-style"><?= $dataArray['relativeMismatch'] ?></td>
                    <td rowspan="2" class="middle-style"><?= $dataArray['relMismatchNum'] . ' из ' .  $dataArray['length']?></td>
                </tr>
                <tr>
                    <td class="middle-style"><?= $dataArray['cardNumber2'] ?></td>
                    <td class="middle-style"><?= date('d.m.Y', $dataArray['dateCreate2']) ?></td>
                    <td class="middle-style"><?= implode($dataArray['comparedSeq']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>

</div>
