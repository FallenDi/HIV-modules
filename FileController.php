<?php

namespace app\controllers;

use app\components\Excel;
use app\models\Center;
use app\models\ExcelUpload;
use app\models\FederalCountry;
use app\models\Patient;
use app\models\PatientDkp;
use app\models\SpravDrug;
use app\models\SpravDrugShort;
use app\models\User as pUser;
use Yii;
use app\models\User;
use app\models\PatientDiseaseStage;
use app\models\PatientViralLoad;
use app\models\PatientCdTest;
use app\models\PatientHla;
use app\models\PatientTherapy;
use app\models\PatientSequence;
use yii\web\UploadedFile;
use yii\helpers\FileHelper;
use \PhpOffice\PhpSpreadsheet\Writer\Csv;
use app\components\CountAndStat;
use app\components\MyReadFilter;
use app\models\SpravInfectionWay;
use app\models\FederalCity;
use app\models\FederalRegion;
use app\models\FederalDistrict;
use app\models\SpravDiseaseStage;

class FileController extends _Controller
{
    //Загрузка шаблонного файла с пациентами
    public function actionExcel()
    {
        $model = new ExcelUpload();

        if ($model->load(Yii::$app->request->post())) {
            $model->excelFile = UploadedFile::getInstances($model, 'excelFile');
            $fileRandomName = uniqid(time());
            $folder = Yii::getAlias('@app/runtime/') . 'upload/';
            //$currentSeqMd5 = array_keys($currentSequences);
            if (!is_dir($folder)) {
                FileHelper::createDirectory($folder);
            }

            $viralLoadDupPatientId = [];
            $curseStageDupPatientId = [];
            $cd4DupPatientId = [];
            $therapyDupPatientId = [];
            $sequenceDupPatientId = [];
            $alleleDupPatientId = [];

            if ($model->excelFile && $model->validate()) {
                foreach ($model->excelFile as $file) {
                    $filename = $folder . $file->name;
                    $file->saveAs($filename);
                }
                //Получаем Эксель файл
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                $reader->setReadFilter(new MyReadFilter());
                $spreadsheet = $reader->load($filename);

                //Создаем csv файл
                $writer = new Csv($spreadsheet);
                $writer->setUseBOM(true);
                $writer->setDelimiter(',');
                $fname = Yii::getAlias('@app/runtime/') . 'upload/' . $fileRandomName . '.csv';
                $writer->save($fname);
                unlink($filename);

                //Разбор csv в массив и удаляем файл csv с сервера
                $dataArray = Excel::patientFromCSVFile($fname);
                unlink($fname);

                // Проверяем наличие дублей номеров карт и бандлов в батче
                if ($dataArray) {
                    $dupBatchCardsNumbers = CountAndStat::checkBatchDup($dataArray, 'card');
                    $dupBatchBundle = CountAndStat::checkBatchDup($dataArray, 'bundle');
                    //$dupSequence = CountAndStat::checkBatchDup($dataArray, 'sequence');
                }
                $session = Yii::$app->session;
                $session->remove('incorrect-data');
                $session->remove('correct-data');
                if (!$dataArray || $dupBatchCardsNumbers) {

                    // устанавливаем значение flash сообщения если найдены дубли в батче
                    /*if ($dupBatchBundle) {
                        $session->setFlash('incorrect-data', 'В файле содержатся возможные дубли, проверьте номера карт: ' . implode(', ', $dupBatchBundle));
                    } else*/
                    if ($dupBatchCardsNumbers) {
                        $session->setFlash('incorrect-data', 'В файле содержатся одинаковые номера карт: ' . implode(', ', $dupBatchCardsNumbers));
                    } elseif (!$dataArray) {
                        $session->setFlash('incorrect-data', 'Некорректно заполненный файл, скачайте шаблон и загрузите корректные данные');
                    } /*elseif ($dupSequence) {
                        $session->setFlash('incorrect-data', 'В файле содержатся идеентичные сиквенсы, проверьте следующие последовательности: ' . implode('<br><br>', $dupSequence));

                    }*/ else {
                        $session->setFlash('incorrect-data', Yii::$app->params['excelErrorValidation']);
                    }

                    return $this->redirect('excel');

                } else {
                    //Собираем массивы справочников, что бы не нагружать базу запросами
                    //Пути заражения
                    $infectionWayArray = [];
                    $infectionWayObjs = SpravInfectionWay::find()->all();
                    foreach ($infectionWayObjs as $infectionObj) {
                        $infectionWayArray[$infectionObj->text] = $infectionObj->id;
                    }

                    //Федеральные округа, регионы, города, стадии заболевания
                    $federalCityArray = CountAndStat::nameToId(FederalCity::find()->all());
                    $federalRegionArray = CountAndStat::nameToId(FederalRegion::find()->all());
                    $federalDistrictArray = CountAndStat::nameToId(FederalDistrict::find()->all());
                    $federalCountryArray = CountAndStat::nameToId(FederalCountry::find()->all());
                    $spravDiseaseStageArray = CountAndStat::nameToId(SpravDiseaseStage::find()->all());

                    $drugFullArray = [];
                    $drugObjs = SpravDrug::find()->all();
                    foreach ($drugObjs as $drugObj) {
                        $drugFullArray[$drugObj->short_name_id] = $drugObj->id;
                    }

                    $drugShortArray = [];
                    $drugShortObjs = SpravDrugShort::find()->all();
                    foreach ($drugShortObjs as $drugShortObj) {
                        $drugShortArray[$drugShortObj->name] = $drugShortObj->id;
                    }

                    // Получаем организацию текущего пользователя (раскомментить при заливке не от superadmin и добавить в finalArray)
                    $modelUser = User::userForFileUpload();
                    $centerID = $modelUser->profile->organization_id;
                    //Собираем итоговый массив из данных файла проверяя на дубликаты
                    $finalArray = [];
                    foreach ($dataArray as $patientArray) {
                        $finalArray[$patientArray[1]] = CountAndStat::finalArray($patientArray, $infectionWayArray, $federalCityArray, $federalRegionArray, $federalDistrictArray, $federalCountryArray, $spravDiseaseStageArray, $drugFullArray, $drugShortArray);
                    }

                    //Получаем все номера карт которые уже есть в базе (убрать комментарий проверяя)
                    $cardsNumberDbArray = [];
                    $bundleDbData = [];
                    $possibleDupArray = [];
                    $modelsCardsDb = Patient::patientUpload(Yii::$app->user->id);

                    // Массивы номеров карт и Бандлов (пол др вич+блот)
                    foreach ($modelsCardsDb as $modelCard) {
                        // Номера карт
                        $cardsNumberDbArray[] = $modelCard->card_number;

                        // Бандлы для проверки наличия таких же в базе
                        $bundleDbData[] = $modelCard->gender . CountAndStat::strToDate($modelCard->birthday) . CountAndStat::strToDate($modelCard->first_hiv_blot_date_day . '-' . $modelCard->first_hiv_blot_date_month . '-' . $modelCard->first_hiv_blot_date_year);

                        //Массив для обратного вывода из бандла в id
                        $possibleDupArray[$modelCard->gender . CountAndStat::strToDate($modelCard->birthday) . CountAndStat::strToDate($modelCard->first_hiv_blot_date_day . '-' . $modelCard->first_hiv_blot_date_month . '-' . $modelCard->first_hiv_blot_date_year)] = $modelCard->id;
                    }
                    $currentSequences = PatientSequence::hashIdDupCheck();
                    $currentSeqMd5 = array_keys($currentSequences);
                    foreach ($finalArray as $patientNumber => $patientDataArray) {
                        //Если номера карты нет и бандлов нет, пишем основные данные
                        $mainInfo = $patientDataArray['main'];
                        $currentBundle = $mainInfo['sex'] . $mainInfo['bDay'] . $mainInfo['HIVBlotDate'];
                        $modelPatient = new Patient();
                        if (in_array($centerID . '_' . $patientNumber, $cardsNumberDbArray)) {
                            //Если такой номер карты есть
                            $originalPatient = Patient::find()
                                ->where(['card_number' => $centerID . '_' . $patientNumber])
                                ->one();

                        } elseif (in_array($currentBundle, $bundleDbData)) {
                            //Если бандл есть среди данных в базе ищем этого пациента
                            $originalPatient = Patient::find()
                                ->where([
                                    'gender' => $mainInfo['sex'],
                                    'birthday' => CountAndStat::strToDate($mainInfo['bDay'], 'Y-m-d'),
                                    'first_hiv_blot_date_year' => CountAndStat::strToDate($mainInfo['HIVBlotDate'], 'Y'),
                                    'first_hiv_blot_date_month' => (int)CountAndStat::strToDate($mainInfo['HIVBlotDate'], 'm'),
                                    'first_hiv_blot_date_day' => (int)CountAndStat::strToDate($mainInfo['HIVBlotDate'], 'd'),
                                    ])
                                ->one();
                        }
                        $cutoff = $mainInfo['save_date']/*time()*/;
                        $modify = time();

                        $modelPatient->card_number = $centerID . '_' . $patientNumber;
                        $modelPatient->birthday = CountAndStat::strToDate($mainInfo['bDay'], 'Y-m-d');
                        $modelPatient->gender = $mainInfo['sex'];
                        $modelPatient->possible_dup_id = (string)($originalPatient->id) ? (string)($originalPatient->id) : '0';
                        if ($originalPatient->id) {
                            $modelPatient->possible_dup = 1;
                        } elseif (in_array($patientNumber, $dupBatchBundle)) {
                            $modelPatient->possible_dup = 1;
                        } else {
                            $modelPatient->possible_dup = 0;
                        }
                        $modelPatient->inspection_code = $mainInfo['inspectionCode'];
                        if ($patientDataArray['main']['HIVBlotDate']) {
                            $modelPatient->first_hiv_blot_date_day = CountAndStat::strToDate($mainInfo['HIVBlotDate'], 'd');
                            $modelPatient->first_hiv_blot_date_month = CountAndStat::strToDate($mainInfo['HIVBlotDate'], 'm');
                            $modelPatient->first_hiv_blot_date_year = CountAndStat::strToDate($mainInfo['HIVBlotDate'], 'Y');
                        } else {
                            $modelPatient->first_hiv_blot_date_year = $mainInfo['HIVBlotYear'];
                        }
                        $modelPatient->infection_code = $mainInfo['infectionCode'];

                        if ($patientDataArray['main']['infectionDate']) {
                            $modelPatient->infection_date_day = CountAndStat::strToDate($mainInfo['infectionDate'], 'd');
                            $modelPatient->infection_date_month = CountAndStat::strToDate($mainInfo['infectionDate'], 'm');
                            $modelPatient->infection_date_year = CountAndStat::strToDate($mainInfo['infectionDate'], 'Y');
                        } else if ($patientDataArray['main']['infectionYear']) {
                            $modelPatient->infection_date_year = $mainInfo['infectionYear'];
                        }

                        $modelPatient->living_city_id = $mainInfo['residence']['residenceCity'];
                        $modelPatient->living_region_id = $mainInfo['residence']['residenceRegion'];
                        $modelPatient->living_district_id = $mainInfo['residence']['residenceFO'];
                        $modelPatient->living_country_id = $mainInfo['residence']['residenceCountry'];
                        $modelPatient->infection_country_id = $mainInfo['infectionRegion']['infectionCountry'];
                        $modelPatient->infection_region_id = $mainInfo['infectionRegion']['infectionRegion'];
                        $modelPatient->infection_way = $mainInfo['infectionWay'];
                        $modelPatient->arvp = $mainInfo['ARVP'];
                        $modelPatient->user_id = Yii::$app->user->id;
                        $modelPatient->created_at = $cutoff;
                        $modelPatient->comment = $mainInfo['comment'];
                        $modelPatient->center_id = $centerID;
                        $modelPatient->birthday_year = $mainInfo['bDayYear'];
                        if ($mainInfo['DKP']) {
                            $modelPatient->dkp = $mainInfo['DKP'];
                        }
                        $modelPatient->modify_at = $modify;
                        $modelPatient->editor_id = Yii::$app->user->id;
                        $modelPatient->project_type = $mainInfo['project_type'];

                        if (!$modelPatient->save()) {
                            $errorWriting[] = $patientNumber;
                            Yii::error($modelPatient->errors);
                        }

                        //Получаем $id карты пациента
                        $patientId = Patient::idGet($centerID . '_' . $patientNumber, $modify);

                        $multipleInfo = $patientDataArray['multiple'];

                        //DKP
                        $modelDKP = new PatientDkp();
                        $dkp = $multipleInfo['therapyDKP'];

                        if ($dkp['dateStart'] || $dkp['dateEnd'] || $dkp['drug']) {
                            $modelDKP->patient_id = $patientId;
                            $DKPYearStart = CountAndStat::strToDate($dkp['dateStart'], 'Y');
                            $DKPYearEnd = CountAndStat::strToDate($dkp['dateEnd'], 'Y');
                            if ($DKPYearStart >= 1950 && $DKPYearEnd >= 1950) {
                                $modelDKP->date_begin_day = CountAndStat::strToDate($dkp['dateStart'], 'd');
                                $modelDKP->date_begin_month = CountAndStat::strToDate($dkp['dateStart'], 'm');
                                $modelDKP->date_begin_year = $DKPYearStart;
                                $modelDKP->date_end_day = CountAndStat::strToDate($dkp['dateEnd'], 'd');
                                $modelDKP->date_end_month = CountAndStat::strToDate($dkp['dateEnd'], 'm');
                                $modelDKP->date_end_year = $DKPYearEnd;
                            }
                            $modelDKP->drugs = $dkp['drug'];

                            if (!$modelDKP->save()) Yii::error($modelDKP->errors);
                        }

                        //Стадия заболевания
                        //Сбор массива хешей из базы
                        //$patientStageMultipleDb = PatientDiseaseStage::hashArrayGet($patientId);
                        //Сбор массива хешей из файла
                        $patientStageMultipleExcel = CountAndStat::patientMultipleHashExcel($multipleInfo, 'curseStage');
                        //Проверка каждого хеша из массива базы с каждым из массива файла
                        foreach ($patientStageMultipleExcel as $key => $hash) {
                            if (($hash) != md5('') || !($hash)) {
                                if ($multipleInfo['curseStage'][$key]['stage'] != null) {
                                    $modelDiseaseStage = new PatientDiseaseStage();
                                    $curseStage = $multipleInfo['curseStage'];
                                    $modelDiseaseStage->patient_id = $patientId;
                                    $curseStageYear = CountAndStat::strToDate($curseStage[$key]['curseDate'], 'Y');
                                    if ($curseStageYear >= 1950) {
                                        if ($curseStage[$key]['curseDate']) {
                                            $modelDiseaseStage->date_day = CountAndStat::strToDate($curseStage[$key]['curseDate'], 'd');
                                            $modelDiseaseStage->date_month = CountAndStat::strToDate($curseStage[$key]['curseDate'], 'm');
                                            $modelDiseaseStage->date_year = $curseStageYear;
                                        } else {
                                            $modelDiseaseStage->date_year = $curseStage[$key]['curseYear'];
                                        }
                                    }

                                    $modelDiseaseStage->stage_id = $curseStage[$key]['stage'];

                                    if (!$modelDiseaseStage->save()) {
                                        Yii::error($modelDiseaseStage->errors);
                                        Yii::warning('Stage');
                                        Yii::warning($patientNumber);
                                    }
                                }
                            }
                        }

                        //Вирусная нагрузка
                        //Сбор массива хешей из базы
                        //$patientViralLoadDb = PatientViralLoad::hashArrayGet($patientId);
                        //Сбор массива хешей из файла
                        $patientViralLoadExcel = CountAndStat::patientMultipleHashExcel($patientDataArray['multiple'], 'viralLoad');
                        //Проверка каждого хеша из массива базы с каждым из массива файла
                        foreach ($patientViralLoadExcel as $key => $hash) {
                            if (($hash) != md5('') || !($hash)) {
                                if ($multipleInfo['viralLoad'][$key]['indication']) {
                                    $modelViralLoad = new PatientViralLoad();
                                    $viralLoad = $multipleInfo['viralLoad'];
                                    $modelViralLoad->patient_id = $patientId;
                                    $viralYear = CountAndStat::strToDate($viralLoad[$key]['viralLoadDate'], 'Y');
                                    if ($viralYear >= '1950') {
                                        if ($viralLoad[$key]['viralLoadDate']) {
                                            $modelViralLoad->date_day = CountAndStat::strToDate($viralLoad[$key]['viralLoadDate'], 'd');
                                            $modelViralLoad->date_month = CountAndStat::strToDate($viralLoad[$key]['viralLoadDate'], 'm');
                                            $modelViralLoad->date_year = $viralYear;
                                        } else {
                                            $modelViralLoad->date_year =  $viralLoad[$key]['viralLoadYear'];
                                        }
                                    }

                                    $modelViralLoad->value = $viralLoad[$key]['indication'];

                                    if (!$modelViralLoad->save()) {
                                        Yii::error($modelViralLoad->errors);
                                        Yii::warning('Viral');
                                        Yii::warning($patientNumber);
                                    }
                                }
                            }
                        }

                        //Уровень Cd4
                        //Сбор массива хешей из базы
                        //$patientCd4Db = PatientCdTest::hashArrayGet($patientId);
                        //Сбор массива хешей из файла
                        $patientCd4Excel = CountAndStat::patientMultipleHashExcel($patientDataArray['multiple'], 'cd4Level');
                        //Проверка каждого хеша из массива базы с каждым из массива файла
                        foreach ($patientCd4Excel as $key => $hash) {
                            if (($hash) != md5('') || !($hash)) {
                                $modelCd4 = new PatientCdTest();
                                $cd4 = $multipleInfo['cd4Level'];
                                if ($cd4[$key]['indication'] != null) {
                                    $modelCd4->patient_id = $patientId;
                                    $cd4Year = CountAndStat::strToDate($cd4[$key]['cd4Date'], 'Y');
                                    if ($cd4Year >= 1950) {
                                        if ($cd4[$key]['cd4Date']) {
                                            $modelCd4->date_day = CountAndStat::strToDate($cd4[$key]['cd4Date'], 'd');
                                            $modelCd4->date_month = CountAndStat::strToDate($cd4[$key]['cd4Date'], 'm');
                                            $modelCd4->date_year = $cd4Year;
                                        } else {
                                            $modelCd4->date_year = $cd4[$key]['cd4Year'];
                                        }
                                    }

                                    $modelCd4->value = $cd4[$key]['indication'];
                                    if (!$modelCd4->save()) {
                                        Yii::error($modelCd4->errors);
                                        Yii::warning('Stage');
                                        Yii::warning($patientNumber);
                                    }
                                }
                            }
                        }

                        //Аллель
                        //Сбор массива хешей из базы
                        //$patientAlleleHlaDb = PatientHla::hashArrayGet($patientId);
                        //Сбор массива хешей из файла
                        $patientAlleleHlaExcel = CountAndStat::patientMultipleHashExcel($patientDataArray['multiple'], 'alleleHLA');
                        //Проверка каждого хеша из массива базы с каждым из массива файла
                        foreach ($patientAlleleHlaExcel as $key => $hash) {
                            if ($hash != md5('') || !($hash)) {
                                if ($multipleInfo['alleleHLA'][$key]['alleleFound'] != null) {
                                    $modelAlleleHla = new PatientHla();
                                    $hla = $multipleInfo['alleleHLA'];
                                    $modelAlleleHla->patient_id = $patientId;
                                    $alleleYear = CountAndStat::strToDate($hla[$key]['alleleDate'], 'Y');
                                    if ($alleleYear >= 1950) {
                                        if ($hla[$key]['alleleDate']) {
                                            $modelAlleleHla->date_day = CountAndStat::strToDate($hla[$key]['alleleDate'], 'd');
                                            $modelAlleleHla->date_month = CountAndStat::strToDate($hla[$key]['alleleDate'], 'm');
                                            $modelAlleleHla->date_year = $alleleYear;
                                        } else {
                                            $modelAlleleHla->date_year = $hla[$key]['alleleYear'];
                                        }
                                    }

                                    $modelAlleleHla->value = $hla[$key]['alleleFound'] ? $hla[$key]['alleleFound'] : 0;

                                    if (!$modelAlleleHla->save()) Yii::error($modelAlleleHla->errors);
                                }
                            }
                        }

                        //Терапия
                        //Сбор массива хешей из базы
                        //$patientTherapyDb = PatientTherapy::hashArrayGet($patientId);
                        //Сбор массива хешей из файла
                        $patientTherapyExcel = CountAndStat::patientMultipleHashExcel($patientDataArray['multiple'], 'theraphy');
                        //Проверка каждого хеша из массива базы с каждым из массива файла
                        foreach ($patientTherapyExcel as $key => $hash) {
                            if (($hash) != md5('') || !($hash)) {
                                if ($multipleInfo['theraphy'][$key]['drug'] != null ) {
                                    $modelTherapy = new PatientTherapy();
                                    $therapy = $multipleInfo['theraphy'];
                                    $modelTherapy->patient_id = $patientId;
                                    $therapyYearStart = CountAndStat::strToDate($therapy[$key]['dateStart'], 'Y');
                                    $therapyYearEnd = CountAndStat::strToDate($therapy[$key]['dateEnd'], 'Y');

                                    if ($therapyYearStart >= 1950) {
                                        $modelTherapy->date_begin_day = CountAndStat::strToDate($therapy[$key]['dateStart'], 'd');
                                        $modelTherapy->date_begin_month = CountAndStat::strToDate($therapy[$key]['dateStart'], 'm');
                                        $modelTherapy->date_begin_year = $therapyYearStart;
                                    }
                                    if ($therapyYearEnd >= 1950) {
                                        $modelTherapy->date_end_day = CountAndStat::strToDate($therapy[$key]['dateEnd'], 'd');
                                        $modelTherapy->date_end_month = CountAndStat::strToDate($therapy[$key]['dateEnd'], 'm');
                                        $modelTherapy->date_end_year = $therapyYearEnd;
                                    }

                                    $modelTherapy->drugs = $therapy[$key]['drug'] ? $therapy[$key]['drug'] : 0;
                                    $modelTherapy->adherence = $therapy[$key]['adherence'];

                                    if (!$modelTherapy->save()) {
                                        Yii::error($modelTherapy->errors);
                                        Yii::warning('Therapy');
                                        Yii::warning($patientNumber);
                                    }
                                }
                            }
                        }

                        //Сиквенсы
                        //Сбор массива хешей из файла
                        $patientSequenceExcel = CountAndStat::patientMultipleHashExcel($patientDataArray['multiple'], 'sequence');
                        //Проверка каждого хеша из массива базы с каждым из массива файла
                        foreach ($patientSequenceExcel as $key => $hash) {
                            //Если нет в базе пишем новую строку, если есть то пишем что возможный дубликат
                            $modelSequence = new PatientSequence();
                            $sequence = $multipleInfo['sequences'];
                            //Если есть полное совпадение с тем который уже есть в базе пишем с кем совпадение(id пациента)
                            if (in_array(md5($sequence[$key]['sequence']), $currentSeqMd5)) {
                                $patientIdDup = $currentSequences[md5($sequence[$key]['sequence'])];
                                $modelSequence->possible_dup = $patientIdDup;
                                $sequenceDupPatientId[] = $patientId;
                            }

                            if ($key == 'pro-rev') {
                                $typeId = 1;
                            } elseif ($key == 'env') {
                                $typeId = 2;
                            } elseif ($key == 'int') {
                                $typeId = 3;
                            } else if ($key == 'full'){
                                $typeId = 9;
                            } else {
                                $typeId = 4;
                            }

                            $modelSequence->seq = $sequence[$key]['sequence'];
                            $modelSequence->type = $typeId;
                            $modelSequence->method_of_sequencing = $sequence[$key]['method'];
                            $modelSequence->patient_id = $patientId;
                            $sequenceYear = CountAndStat::strToDate($sequence[$key]['date'], 'Y');
                            if ($sequenceYear >= 1950) {
                                $modelSequence->date_day = CountAndStat::strToDate($sequence[$key]['date'], 'd');
                                $modelSequence->date_month = CountAndStat::strToDate($sequence[$key]['date'], 'm');
                                $modelSequence->date_year = $sequenceYear;
                            }

                            //if (!$modelSequence->save()) Yii::error($modelSequence->errors);
                            if (!$modelSequence->save()) {
                                Yii::error($modelSequence->errors);
                                Yii::warning('Seq');
                                Yii::warning($patientNumber);
                            }
                        }
                    }
                }
            } else {
                $session = Yii::$app->session;
                $session->setFlash('incorrect-data', Yii::$app->params['excelErrorValidation']);
                Yii::error($model->errors);
            }
            $session = Yii::$app->session;
            $session->setFlash('correct-data', Yii::$app->params['excelOK']);
            if ($errorWriting) {
                $session->setFlash('wrong-data', 'Ошибка сохранения пациентов:' . implode(', ', $errorWriting));
            }

            // Если есть дубли в анализах рендерить страницу устраннеия конфликтов
            if ($viralLoadDupPatientId || $cd4DupPatientId || $sequenceDupPatientId || $therapyDupPatientId || $alleleDupPatientId || $curseStageDupPatientId) {

                return $this->redirect('possible-dup');
            }

            return $this->render('excel', [
                'model' => $model,
            ]);
        }

        return $this->render('excel', [
            'model' => $model,
        ]);
    }

    //Обрабатывает загруженные кастомные Excel файлы пользователей
    public function actionUpload()
    {
        $modelUpload = new ExcelUpload();
        $modelCenter = new Center();

        if ($modelUpload->load(Yii::$app->request->post())) {
            $userId = Yii::$app->user->id;
            $userProfile = pUser::userOrganizationId($userId);
            $centerName = $userProfile->profile->organization_id;
            $modelUpload->excelFile = UploadedFile::getInstances($modelUpload, 'excelFile');
            $folder = Yii::getAlias('@webroot/') . 'centerfiles/';
            $filenameHash = time();

            if (!is_dir($folder)) {
                FileHelper::createDirectory($folder);
            }

            if ($modelUpload->excelFile && $modelUpload->validate()) {
                foreach ($modelUpload->excelFile as $file) {
                    $filename = $folder . Yii::$app->user->id . '-' . $centerName . '-' . $filenameHash . '-' . md5($file->name) . '.xlsx';
                    $file->saveAs($filename, false);
                }
            } else {
                $session = Yii::$app->session;
                $session->setFlash('incorrect-data', Yii::$app->params['excelErrorValidation']);
                Yii::error($modelUpload->errors);
            }
            $session = Yii::$app->session;
            $session->setFlash('correct-data', Yii::$app->params['excelOK']);

            return $this->redirect('upload');
        }

        return $this->render('upload', [
            'model' => $modelUpload,
            'modelCenter' => $modelCenter
        ]);
    }

    //Выводит на экран список загруженных пользователями файлов
    public function actionServerfiles()
    {
        return $this->render('serverfiles');
    }

    // Генерирует вывод записей с 1 в possible dup
    public function actionPossibleDup()
    {
        if (\webvimark\modules\UserManagement\models\User::hasRole('Moderator')) {
            $where = true;
        }
        $searchModel = new Patient();
        $modelUser = \app\models\User::find()->with('profile')->where(['id' => Yii::$app->user->id])->one();
        $centerId = $modelUser->profile->organization_id;
        if ($centerId == 1) {
            $where = true;
        } else {
            $where = false;
        }
        $dupIdArray = Patient::possibleDupCheck($where);
        $dataProvider = $searchModel::userAlert($dupIdArray);

        return $this->render('possible-dup', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

}