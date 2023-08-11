<?php

namespace app\models;

use Yii;
use yii\helpers\Html;
use app\components\Common;
use webvimark\modules\UserManagement\models\User;
use yii\helpers\ArrayHelper;
use yii\data\ActiveDataProvider;
use app\components\CountAndStat;
use app\components\CachedBehavior;


/**
 * This is the model class for table "patient".
 *
 * @property int $id
 * @property string|null $card_number
 * @property int|null $gender
 * @property string|null $birthday
 * @property int|null $infection_code
 * @property int|null $inspection_code
 * @property string|null $first_hiv_blot_date_year
 * @property int|null $first_hiv_blot_date_month
 * @property int|null $infection_way
 * @property int|null $first_hiv_blot_date_day
 * @property int|null $infection_date_day
 * @property string|null $infection_date_year
 * @property int|null $infection_date_month
 * @property int|null $living_city_id
 * @property int|null $living_region_id
 * @property int|null $living_district_id
 * @property int|null $living_country_id
 * @property int|null $infection_country_id
 * @property int|null $possible_dup_id
 * @property int|null $possible_dup
 * @property int|null $infection_region_id
 * @property int|null $infection_district_id
 * @property int|null $arvp
 * @property int|null $TDR_flag
 * @property int $user_id
 * @property int $center_id
 * @property int $modify_at
 * @property int $project_type
 * @property int $created_at
 * @property string|null $comment
 * @property int $editor_id
 * @property int $card_number_old
 * @property string $hasTherapy
 * @property int $birthday_year
 * @property int $dkp
 */
class Patient extends \yii\db\ActiveRecord
{
    public $go_after;
    public $card_number_old;
    public $first_hiv_blot_date;
    public $infection_date;
    public $hasTherapy; // флаг, означающий, что у пациента заполнена хотя бы одна терапия

    const DATE_ERROR_TEXT = 'Недопустимая дата';
    const MASKED_CLIENT_OPTIONS = [
        'alias' => '[9][9].[9][9].9999',
        'removeMaskOnSubmit' => false
    ];
    const MASKED_DATE_PLACEHOLDER = 'день.месяц.год';

    //В случае обновления/удаления/добавления записей сбросить кэш
    public function behaviors()
    {
        return [
            'CachedBehavior' => [
                'class' => CachedBehavior::class,
                'cache_key' => ['models_patient'],
            ]
        ];
    }

    // перед удалением записи клонируем её в архив
    public function beforeDelete()
    {
        if (!parent::beforeDelete()) {
            return false;
        }
        $className = 'app\models\Del' . explode('\\', self::class)[2];

        $model = new $className();
        $model->attributes = $this->attributes;
        $model->save(false);

        return true;
    }

    // после удаления перемещаем в архив все связанные записи
    public function afterDelete()
    {
        parent::afterDelete();

        $models_array = [
            $this->diseaseStage,
            $this->viralLoad,
            $this->cdTest,
            $this->hla,
            $this->therapy,
            $this->sequence
        ];

        foreach ($models_array as $models) {
            foreach ($models as $model) {
                if (!$model->delete()) Yii::error("Не удалось удалить модель " . get_class($model));
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'patient';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
                [['gender', 'infection_code', 'inspection_code', 'first_hiv_blot_date_month', 'infection_way', 'first_hiv_blot_date_day', 'infection_date_day', 'infection_date_month', 'living_city_id', 'living_region_id', 'living_district_id', 'living_country_id', 'infection_country_id', 'infection_region_id', 'infection_district_id',  'arvp', 'user_id', 'first_hiv_blot_date_year', 'infection_date_year', 'center_id', 'modify_at', 'created_at', 'go_after', 'created_at', 'editor_id', 'TDR_flag', 'id', 'dkp', 'possible_dup', 'possible_dup_id'], 'integer'],
            [['birthday', 'first_hiv_blot_date_year', 'infection_date_year', 'hla_b5701_year', 'first_hiv_blot_date', 'infection_date', 'hasTherapy', 'birthday_year'], 'safe'],
            [['birthday'], 'birthdayValidation', 'when' => function($model) {
                return strpos($model->birthday, '.') !== false;
            }],
            [['arvp'], 'arvpValidation'],
            [['first_hiv_blot_date', 'infection_date'], 'partialDateValidation'],
            [['card_number', 'card_number_old'], 'string', 'max' => 100],
            [['project_type'], 'string', 'max' => 200],
            [['comment'], 'string'],
            [['card_number'], 'required'],
        ];
    }

    // валидация ARVP (нельзя ставить значение "нет", если заполнена хотя бы одна терапия
    public function arvpValidation($attribute, $params)
    {
        if ($this->hasTherapy > 0 && $this->$attribute == 0) {
            $this->addError($attribute, '"Опыт получения АРВП" не может принимать значение "Нет" при наличии заполненных терапий');
        }

        return true;
    }

    // валидация дня рождения
    public function birthdayValidation($attribute, $params)
    {
        $values_array = explode('.', $this->$attribute);
        $thisYear = date('Y', time());
        $error = false;

        foreach ($values_array as $key => $value) {
            if (strpos($value, '_') !== false || $value == 0) {
                $error = true;
                break;
            }

            // день не должен быть больше 31, месяц не больше 12, год не больше текущего
            switch ($key) {
                case 0:
                    if ($value > 31) $error = true;
                    break;

                case 1:
                    if ($value > 12) $error = true;
                    break;

                case 2:
                    if ($value > $thisYear || $value < 1800) $error = true;
                    break;
            }
        }

        if ($error) $this->addError($attribute, 'Недопустимая дата');

        return true;
    }

    // валидация неполных дат
    public function partialDateValidation($attribute, $params)
    {
        if (self::partialDateCommonError($this->$attribute)) $this->addError($attribute, self::DATE_ERROR_TEXT);

        return true;
    }

    // валидация уникального номера пациента
    /*public function uniqueByUser($attribute, $params)
    {
        $card_number = trim($this->$attribute);
        $check_unique = self::find()
            ->where(['card_number' => $card_number, 'user_id' => Yii::$app->user->id])
            ->one();

        if ($check_unique) {
            $this->addError($attribute, 'Пациент с номером ' . $card_number . ' уже существует. Вы можете ' . Html::a('дополнить', '/patient/edit/' . Common::easyCrypt($check_unique->id, 'in'), ['target' => '_blank']) . ' эту запись.');
        }

        return true;
    }*/

    // общая часть функции валидации неполных дат
    public static function partialDateCommonError($attribute)
    {
        $values_array = explode('.',$attribute);
        $thisYear = date('Y', time());
        $error = false;

        foreach ($values_array as $key => $value) {
            // день не должен быть больше 31, месяц не больше 12, год не больше текущего
            switch ($key) {
                case 0:
                    if ($value > 31) $error = true;
                    break;

                case 1:
                    if ($value > 12) $error = true;
                    break;

                case 2:
                    if ($value > $thisYear || $value < 1900) $error = true;
                    break;
            }
        }

        return $error;
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'card_number' => 'Уникальный номер пациента (Номер карты)',
            'gender' => 'Пол',
            'birthday' => 'Дата рождения',
            'infection_code' => 'Код инфицирования',
            'inspection_code' => 'Код обследования',
            'first_hiv_blot_date_year' => 'Дата первого ВИЧ+ блота (год)',
            'first_hiv_blot_date_month' => 'Дата первого ВИЧ+ блота (месяц)',
            'infection_way' => 'Предполагаемый путь инфицирования',
            'first_hiv_blot_date' => 'Дата первого ВИЧ+ блота',
            'first_hiv_blot_date_day' => 'Дата первого ВИЧ+ блота (день)',
            'infection_date' => 'Предполагаемая дата инфицирования',
            'infection_date_day' => 'Предполагаемая дата инфицирования (день)',
            'infection_date_year' => 'Предполагаемая дата инфицирования (год)',
            'infection_date_month' => 'Предполагаемая дата инфицирования (месяц)',
            'living_city_id' => 'Город проживания',
            'living_country_id' => 'Страна проживания',
            'living_region_id' => 'Регион проживания',
            'living_district_id' => 'Федеральный округ проживания',
            'infection_country_id' => 'Страна заражения',
            'infection_region_id' => 'Регион заражения',
            'infection_district_id' => 'Федеральный округ заражения',
            'arvp' => 'Опыт получения АРВП',
            'project_type' => 'Тип исследовательского проекта',
            'center_id' => 'Центр',
            'user_id' => 'Кем внесено',
            'go_after' => 'После сохранения',
            'modify_at' => 'Изменено',
            'created_at' => 'Дата загрузки',
            'comment' => 'Комментарий',
            'editor_id' => 'Внесший запись',
            'TDR_flag' => 'Флаг лек. уст.',
            'dkp' => 'Доконтактная профилактика',
            'possible_dup' => 'Возможный дубликат'
        ];
    }

    public function getLivingCity()
    {
        return $this->hasOne(FederalCity::class, ['id' => 'living_city_id']);
    }

    public function getInfectionRegion()
    {
        return $this->hasOne(FederalRegion::class, ['id' => 'infection_region_id']);
    }

    public function getLivingCountry()
    {
        return $this->hasOne(FederalCountry::class, ['id' => 'living_country_id']);
    }

    public function getInfectionCountry()
    {
        return $this->hasOne(FederalCountry::class, ['id' => 'infection_country_id']);
    }

    public function getLivingRegion()
    {
        return $this->hasOne(FederalRegion::class, ['id' => 'living_region_id']);
    }

    public function getSpravInfectionCode()
    {
        return $this->hasOne(SpravInfectionCode::class, ['code' => 'infection_code']);
    }

    public function getApiStanford()
    {
        return $this->hasMany(PatientSequencesApi::class, ['patient_id' => 'id']);
    }

    public function getSpravInspectionCode()
    {
        return $this->hasOne(SpravInspectionCode::class, ['code' => 'inspection_code']);
    }

    public function getSpravInfectionWay()
    {
        return $this->hasOne(SpravInfectionWay::class, ['id' => 'infection_way']);
    }

    public function getPatientDkp()
    {
        return $this->hasMany(PatientDkp::class, ['patient_id' => 'id']);
    }

    public function getLivingDistrict()
    {
        return $this->hasOne(FederalDistrict::class, ['id' => 'living_district_id']);
    }

    public function getCenter()
    {
        return $this->hasOne(Center::class, ['id' => 'center_id']);
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
    public function getUserProfile()
    {
        return $this->hasOne(UserProfile::class, ['user_id' => 'user_id']);
    }

    public function getDiseaseStage()
    {
        return $this->hasMany(PatientDiseaseStage::class, ['patient_id' => 'id']);
    }

    public function getViralLoad()
    {
        return $this->hasMany(PatientViralLoad::class, ['patient_id' => 'id']);
    }

    public function getCdTest()
    {
        return $this->hasMany(PatientCdTest::class, ['patient_id' => 'id']);
    }

    public function getHla()
    {
        return $this->hasMany(PatientHla::class, ['patient_id' => 'id']);
    }

    public function getTherapy()
    {
        return $this->hasMany(PatientTherapy::class, ['patient_id' => 'id'])->orderBy(['date_begin_year' => SORT_DESC]);
    }

    public function getSequence()
    {
        return $this->hasMany(PatientSequence::class, ['patient_id' => 'id']);
    }

    // удалённые связи
    public function getPatientDiseaseStageDel()
    {
        return $this->hasMany(DelPatientDiseaseStage::class, ['patient_id' => 'id']);
    }

    public function getPatientViralLoadDel()
    {
        return $this->hasMany(DelPatientViralLoad::class, ['patient_id' => 'id']);
    }

    public function getPatientCdTestDel()
    {
        return $this->hasMany(DelPatientCdTest::class, ['patient_id' => 'id']);
    }

    public function getPatientHlaDel()
    {
        return $this->hasMany(DelPatientHla::class, ['patient_id' => 'id']);
    }

    public function getPatientTherapyDel()
    {
        return $this->hasMany(DelPatientTherapy::class, ['patient_id' => 'id']);
    }

    public function getPatientSequenceDel()
    {
        return $this->hasMany(DelPatientSequence::class, ['patient_id' => 'id']);
    }

    public function getPatientDkpDel()
    {
        return $this->hasMany(DelPatientDkp::class, ['patient_id' => 'id']);
    }

    // функция формирует массив данных для построения динамической формы добавления записи пациента
    public static function dynamicArrayGet($dynamic_models_array)
    {
        // $models_disease, $models_viral, $models_cd4, $models_hla

        return [
            [
                'model' => $dynamic_models_array[0],
                'title' => Yii::t('app', 'patient_disease_stage'),
                'title_mini' => 'Стадия',
                'value_name' => 'stage_id',
                'value_type' => 'select',
                'hide_search' => false,
                'value_title' => 'стадия *',
                'value_data' => SpravDiseaseStage::selectInfoGet(),
            ],
            [
                'model' => $dynamic_models_array[1],
                'title' => Yii::t('app', 'patient_viral_load'),
                'title_mini' => 'Нагрузка',
                'value_name' => 'value',
                'value_type' => 'input',
                'value_title' => 'показатель *',
                'value_data' => null,
                'value_unit' => 'копий/мл',
                'prepend' => true
            ],
            [
                'model' => $dynamic_models_array[2],
                'title' => Yii::t('app', 'patient_cd4_test'),
                'title_mini' => 'Уровень',
                'value_name' => 'value',
                'value_type' => 'input',
                'value_title' => 'показатель *',
                'value_data' => null,
                'value_unit' => 'клеток/мл'
            ],
            [
                'model' => $dynamic_models_array[3],
                'title' => Yii::t('app', 'patient_hla_b5701'),
                'title_mini' => 'Наличие',
                'value_name' => 'value',
                'value_type' => 'select',
                'hide_search' => true,
                'value_title' => 'наличие *',
                'value_data' => array_slice(Yii::$app->params['binary_array'], 1),
            ]
        ];
    }

    // функция формирует модель по айди
    public static function selectById($patient_id)
    {
        $andWhere = User::hasRole('Moderator') ? [] : ['user_id' => Yii::$app->user->id];

        return self::find()
            ->where(['id' => $patient_id])
            //->andWhere($andWhere)
            ->one();
    }

    // функция стандартизирует отображение полей для чтения
    public static function echoField($model, $attribute, $empty_text_gender, $array_values = null, $unit = '') {
        $dom_result = '';
        if ($attribute == 'drugs') {
            $attribute_value = $model->$attribute ? implode(', ', SpravDrug::initialValuesSelect(implode(':', $model->$attribute))) : null;
        } elseif ($model::className() == 'app\models\PatientViralLoad') {
            if (is_null($model->$attribute) && $attribute == 'value') {
                if ($model->value_less_500) {
                    $attribute_value = '< 500';
                } elseif ($model->value_less_50) {
                    $attribute_value = '< 50';
                } else {
                    $attribute_value = $model->$attribute;
                }
            } else {
                $attribute_value = $model->$attribute;
            }
        } else {
            if ($array_values && !is_null($model->$attribute)) {
                $attribute_value = $array_values[$model->$attribute];
            } else {
                $attribute_value = $model->$attribute;
            }
        }

        switch ($empty_text_gender) {
            case 'm': // мужской
                $empty_text_gender = 'Не задан';
                break;

            case 'f': // женский
                $empty_text_gender = 'Не задана';
                break;

            default: // средний
                $empty_text_gender = 'Не задано';
                break;
        }

        $dom_result .= '<div class="form-group">';
        $dom_result .= '<label class="control-label"><b>';
        $dom_result .=  $model->getAttributeLabel($attribute);
        $dom_result .=  '</b></label>';
        $dom_result .=  '<div class="echo-field">';
        $dom_result .=  ($attribute_value ? $attribute_value : $empty_text_gender) . ' ' . $unit;
        $dom_result .=  '</div>';
        $dom_result .=  '</div>';

        return $dom_result;
    }
	
	//Функция получает id по номеру карты
	public static function idGet($cardNumber, $modify){

            $modelPatient = self::find()
                ->where(['card_number' => $cardNumber, 'user_id' => Yii::$app->user->id])
                ->andWhere(['modify_at' => $modify])
                ->one();

        return $modelPatient->id;
    }

    // функция формирует массивы для подставления в Typeahead input
    public static function suggestionInfoGet($type)
    {
        $andWhere = User::hasRole('Moderator') ? [] : ['user_id' => Yii::$app->user->id];
        $query = self::find()
            ->where(['!=', $type, ''])
            ->andWhere($andWhere)
            ->orderBy(['modify_at' => SORT_DESC])
            ->groupBy([$type])
            ->asArray()
            ->all();

        $result_array = ArrayHelper::getColumn($query, $type);

        return $result_array;
    }

    // функция формирует модель по номеру карточки пациента
    public static function modelGetByCardNumber($card_number, $user_id)
    {
        return self::find()
            ->where(['card_number' => $card_number, 'user_id' => $user_id])
            ->one();
    }

    //Функция получает данные по пациенту для таблиц и графиков главной страницы модератора и ФБУН ЦНИИЭ
    public static function mainPagePatientFBUN()
    {
        return  self::find()
                ->with([
                    'livingDistrict',
                    'apiStanford',
                    'sequence',
                    'center'
                ])
                ->where(['=', 'possible_dup_id', 0])
                ->andWhere(['center_id' => Yii::$app->params['ru_center_id']])
                ->all();
    }

    //Функция получает данные по пациенту для таблиц и графиков главной страницы модератора и ФБУН ЦНИИЭ по определенному региону и тип сиквенсов
    public static function mainPagePatientFBUN2()
    {
        return  self::find()
            ->with([
                'livingDistrict',
                'sequence',
                'center'
            ])
            ->leftJoin('patient_sequence', 'patient.id=patient_sequence.patient_id')
            ->where(['patient.arvp' => 0])
            ->andWhere(['patient.living_country_id' => 134])
            ->andWhere(['patient_sequence.type' => [1, 4, 9]])
            ->andWhere((['patient.center_id' => 1]))
            ->all();
    }

    //Функция выбирает всех пациентов без ВЕЦА с дублями или нет в зависимости от параметра $duplicate
    public static function uniquePatient($duplicate = false)
    {
	if(!$duplicete) {
		$where =  ['=', 'possible_dup_id', 0];
	} else {
		$where = [];
	}
	    
        return self::find()
            ->with(['sequence'])
            ->where(['=', 'possible_dup_id', 0])
            ->andWhere(['center_id' => Yii::$app->params['ru_center_id']])
            ->distinct()
            ->all();
    }

    //Функция получает данные по пациенту для таблиц и графиков главной страницы по центрам
    public static function mainPagePatientCenter($centerId)
    {
        return  self::find()
            ->with([
                'livingDistrict',
                'apiStanford',
                'sequence',
                'center'
            ])
            ->where(['center_id' => $centerId])
            ->all();
    }

    //Функция получает данные по пациенту для генерации отчета
    public static function annualReportPatient()
    {
        return self::find()
            ->with([
                'livingDistrict',
                'spravInfectionCode',
                'apiStanford',
                'sequence',
                'spravInfectionWay',
                'center'
            ])
            ->leftJoin('patient_sequences_api', 'patient.id=patient_sequences_api.patient_id')
            ->where(['patient_sequences_api.qc' => 1])
            ->all();
    }

    // Функция собирает массив для дата-провайдера страницы предупреждения дубликатов
    public static function userAlert($dupIdArray)
    {
        $query = self::find()
            ->where(['id' => $dupIdArray])
            ->orderBy(['card_number' => SORT_NATURAL,'modify_at' => SORT_DESC]);

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'pageSize' => 20,
            ],
        ]);

        return $dataProvider;
    }

    // Функция собирает ID пациентов с потенциальными дублями в показателях
    public static function possibleDupCheck($where=true)
    {
        //Определение центра к которому принадлежит пользователь
        $modelUser = \app\models\User::find()->with('profile')->where(['id' => Yii::$app->user->id])->one();
        $centerId = $modelUser->profile->organization_id;
        Yii::warning($centerId);
        // Сбор сведений о пациентах в разрезе центра
        if ($where) {
            $query = Patient::find()
                ->with(['diseaseStage', 'viralLoad', 'cdTest', 'hla', 'therapy', 'sequence'])
                ->where(['!=', 'possible_dup', 0])
                ->orderBy(['card_number' => SORT_NATURAL,'modify_at' => SORT_DESC])
                ->all();
        } else {
            $query = Patient::find()
                ->with(['diseaseStage', 'viralLoad', 'cdTest', 'hla', 'therapy', 'sequence'])
                ->where(['center_id' => $centerId])
                ->andWhere(['!=', 'possible_dup_id', '0'])
                ->andWhere(['!=', 'possible_dup', 0])
                ->orderBy(['card_number' => SORT_NATURAL,'modify_at' => SORT_DESC])
                ->all();
        }

        return $query;
    }

    // Функция собирает данные по центрам для первой таблицы годового отчета
    public static function annualCenterTable()
    {
	    return self::find()
            ->with([
                'center',
                'sequence'
            ])
            ->leftJoin('patient_sequence', 'patient.id=patient_sequence.patient_id')
            //->where('patient_sequence.type' != 3)
            //->where(['!=', 'patient_sequence.type', 3])
            ->all();
    }

    //Функция получает данные по пациенту для таблиц и графиков главной страницы модератора и ФБУН ЦНИИЭ
    public static function dumpDbExcel($modelUserProfile, $eeca = false)
    {
        if (!$eeca) {
            $where = $modelUserProfile->organization_id == 1 ? ['patient.center_id' => Yii::$app->params['ru_center_id']] : ['patient.center_id' => $modelUserProfile->organization_id];
        } else {
            $where = ['patient.center_id' => Yii::$app->params['EECA_center_id']];
        }

        return  self::find()
            ->leftJoin('patient_sequence', 'patient.id=patient_sequence.patient_id')
            ->with([
                'spravInfectionWay',
                'therapy',
                'livingCity',
                'livingRegion',
                'livingDistrict',
                'livingCountry',
                'infectionCountry',
                'infectionRegion',
                'apiStanford',
                'sequence',
                'center',
                'hla',
                'cdTest',
                'viralLoad',
                'diseaseStage',
                'userProfile'
            ])
            ->where($where)
            ->orderBy(['card_number' => SORT_ASC,'date_year' => SORT_ASC, 'date_month' => SORT_ASC, 'possible_dup_id' => SORT_ASC])
            ->all();
    }

    //Функция получает данные по номерам карт и данные о батче для определения возможных дубликатов
    public static function patientUpload($userId, $centerId = null)
    {
        //Раскоментить центер при проде
        return self::find()
                ->select([
                    'id',
                    'card_number',
                    'gender',
                    'birthday',
                    'first_hiv_blot_date_day',
                    'first_hiv_blot_date_month',
                    'first_hiv_blot_date_year'
                ])
                ->where(['user_id' => $userId/*, 'center_id' => $centerId*/] )
                ->all();
    }

    //Функция находит пациента по id
    public static function findById($id)
    {
        return self::find()
            ->where(['id' => $id])
            ->one();
    }

    //Функция выюирает пациентов и сиквенсы для проверки на контоминацию
    public static function contaminationCheck($fromDate, $toDate)
    {
        return self::find()
            ->with('sequence')
            ->where(['between', 'created_at', $fromDate, $toDate])
            ->all();
    }

    //Функци собирает id_patient только RuHIV и возвращает массив id
    public static function ruhivPatientId()
    {
        $idArray = [];

       $query = self::find()
            ->select('id')
            ->where(['center_id' => Yii::$app->params['ru_center_id']])
            ->all();

       foreach ($query as $patientId) {
           $idArray[] = $patientId->id;
       }

       return $idArray;

    }

}

