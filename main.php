<?php

/* @var $this \yii\web\View */
/* @var $content string */

use app\models\User as pUser;
use yii\helpers\Html;
use webvimark\modules\UserManagement\components\GhostNav;
use yii\bootstrap\NavBar;
use yii\widgets\Breadcrumbs;
use app\assets\AppAsset;
use app\components\Common;
use webvimark\modules\UserManagement\models\User;
use yii\helpers\Url;

AppAsset::register($this);
$this->registerJsFile(Yii::getAlias('@web/js/common-end.js'), ['position' => yii\web\View::POS_END, 'depends' => [\yii\web\JqueryAsset::className()]]);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $this->registerCsrfMetaTags() ?>
    <title><?= Html::encode($this->title) ?></title>
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody();?>

<div class="wrap">
    <!--<div class="company-name"><?/*=Html::a('База данных устойчивости ВИЧ к антиретровирусным препаратам', '/index', ['class' => 'title-link'])*/?></div>-->
    <?php
    if (Yii::$app->user->isGuest) {
        $username = '';
        $login_text = 'Вход';
        $login_url = '/login';
    } else {
        $username = Yii::$app->user->identity->username;
        $login_text = 'Выход (' . Yii::$app->user->identity->username . ')';
        $login_url = '/logout';
    }

    $userId = Yii::$app->user->id;
    $userProfile = pUser::userOrganizationId($userId);
    if (in_array($userProfile->profile->organization_id, Yii::$app->params['EECA_center_id'])) {
        $routToLogo = '<img src="/images/logo_eeca.svg" alt="eecahiv"/>';
        $logoText = '<img src="/images/logo2_eeca.svg" alt="dd">';
        $routToPage = Url::to('https://eecahiv.ru');
    } else {
        $routToLogo = '<img src="/images/logo.svg" alt="ruhiv"/>';
        $routToPage = Url::to('https://ruhiv.ru');
        $logoText = '<img src="/images/logo2.svg" alt="dd">';
    }
    NavBar::begin([
        'brandLabel' => $routToLogo,
        'brandUrl' => $routToPage,
        'options' => [
            'class' => 'navbar navbar-expand-lg navbar-light header-navbar',
        ],
    ]);
    echo GhostNav::widget([
        'options' => ['class' => 'navbar-nav pull-right nav-items', 'role' => 'navigation'],
        'encodeLabels' => false,
        'activateParents' => true,
        'dropDownCaret' => Html::tag('span', '', ['class' => 'caret']),
        'items' => [
            ['label' => 'Пациенты', 'items' => [
                [
                    'label' => 'Добавить вручную',
                    'url'=>['/patient/form'],
                    'visible' => User::canRoute('/patient/form')],
                [
                    'label' => 'Список пациентов',
                    'url'=>['/patient/list'],
                    'visible' => User::canRoute('/patient/list')],
                [
                    'label' => 'Список удалённых',
                    'url'=>['/patient/deleted'],
                    'visible' => User::canRoute('/patient/deleted')],
                ]
            ],
            ['label' => 'Файлы', 'items' => [
                ['label' => 'Добавить пациентов из файла', 'url'=>['/file/excel'], 'visible' => User::canRoute('/file/excel')],
                ['label' => 'Загрузить свой файл', 'url'=>['/file/upload'], 'visible' => User::canRoute('/file/upload')],
                ['label' =>'Загруженные файлы', 'url'=>['/file/serverfiles'], 'visible' => User::canRoute(['/file/serverfiles'])],
                ]
            ],
            ['label' => 'Работа с базой', 'items' => [
                [
                    'label' =>'Анализ сиквенсов', 'url'=>['/api/stanfordapi'],
                    'options'=> [
                        'id'=> 'api-stanford'
                    ],
                ],
                [
                    'label' => 'Сформировать годовой отчет',
                    'url'=>['/report/basereport'],
                    'visible' => User::canRoute('/report/basereport'),
                    'options'=> [
                        'id'=> 'report-generate'
                    ],
                ],
                [
                    'label' =>'Выгрузка базы', 'url' => ['/dump/generate-excel'],
                    'visible' => User::canRoute(['/dump/generate-excel']),
                    'options' => [
                        'id' => 'dump-db'
                    ],
                ],
                ['label' => 'Проверка на контаминацию', 'url' => ['/api/contamination'],],
                ['label' => 'Cluster', 'url' => ['/api/cluster'],],
                ['label' => 'Работа с дубликатами', 'url' => ['/file/possible-dup'],],
                ['label' => 'Список годовых отчётов', 'url' => ['/report/archive'], 'visible' => User::canRoute('/report/archive')],
                ]
            ],
            ['label' => 'Документы', 'url' => 'https://ruhiv.ru/dokuments/', 'linkOptions' => ['target' => '_blank']],
            ['label' => 'Статистика', 'url' => 'https://ruhiv.ru/statistics/', 'linkOptions' => ['target' => '_blank']],
            ['label' => 'О Проекте', 'url' => 'https://ruhiv.ru/about/', 'linkOptions' => ['target' => '_blank']],
            ['label' => 'Администрирование', 'items' =>
                Common::adminMenuItems(),
            ],
            ['label' => $login_text, 'url' => [$login_url], 'visible' => User::canRoute('user-management/auth/logout')],
        ],
    ]);
    echo GhostNav::widget([
        'options' => ['class' => 'nav navbar-nav pull-left nav-left-item', 'role' => 'navigation'],
        'encodeLabels' => false,
        'activateParents' => true,
        'dropDownCaret' => Html::tag('span', '', ['class' => 'caret']),
        'items' => [
            [
                'label' => $logoText,
                'options'=> [
                    'id'=> 'logo-text'
                ],
                'url'=>[Yii::$app->homeUrl]
            ],
        ],
    ]);
    NavBar::end();
    ?>

    <div class="container">
        <?= Breadcrumbs::widget([
            'links' => isset($this->params['breadcrumbs']) ? $this->params['breadcrumbs'] : [],
        ]) ?>
        <?//= Alert::widget() ?>
        <?= $content ?>
    </div>
</div>

    <footer class="navbar footer-navbar">
        <div class="container">
            <p class="pull-left">&copy; <?= date('Y') ?> ФБУН ЦНИИ Эпидемиологии Роспотребнадзора</p>
        </div>
    </footer>

<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
