<?php
use kartik\form\ActiveForm;
use kartik\file\FileInput;

/* @var $modelUpload app\models\ExcelUpload */

$this->title = 'Cluster';
$session = Yii::$app->session;

$form = ActiveForm::begin([
    'id' => 'excel-form',
    'options' => ['enctype' => 'multipart/form-data'
    ]]);
echo '<div class="excelLoadForm">';
echo '<div class="input-file">';

echo '<div class="col-sm-12">';
echo '<div class="col-sm-6">';
echo $form->field($modelUpload, 'excelFile', ['validateOnBlur' => false])->widget(FileInput::class, [
    'options' => [
        'multiple' => true,
        'accept' => 'xls, fas',
    ],
    'pluginOptions' => [
        'showPreview' => false,
        'showCaption' => true,
        'showRemove' => true,
        'showUpload' => true,
        'captionClass' => 'fileInputCaption'
    ],
    'language' => 'ru',

]);
echo '</div>';
echo '</div>';
echo '<div class="col-sm-12">';
echo '<div class="col-sm-6">';
echo '<div class="error-message"><font color="red">' . $session->getFlash('incorrect-data-cluster') . '</font></div>';
echo '<div class="error-message"><font color="green">' . $session->getFlash('correct-data') . '</font></div>';
$session->remove('incorrect-data');
$session->remove('correct-data');
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';
ActiveForm::end();