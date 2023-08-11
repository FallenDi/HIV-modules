<?php
namespace app\controllers;

use app\models\PatientSequencesApi;
use app\models\UserProfile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Yii;
use yii\helpers\FileHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use app\models\Patient;
use app\components\CountAndStat;
use app\models\SpravDrug;
use app\models\SpravDrugShort;
use app\components\Excel;

class DumpController extends _Controller
{
    //Экшн генерирует эксель файл
    public function actionGenerateExcel()
    {
        $drugFullArray = [];
        $drugShortArray = [];
        $drugObjs = SpravDrug::find()->all();
        $drugShortObjs = SpravDrugShort::find()->all();

        $proRev = Yii::$app->params['seq_type_array'][1];
        $env = Yii::$app->params['seq_type_array'][2];
        $int = Yii::$app->params['seq_type_array'][3];
        $proRevInt = Yii::$app->params['seq_type_array'][4];
        $full = Yii::$app->params['seq_type_array'][9];
        $binary = Yii::$app->params['binary_array'];

        foreach ($drugObjs as $drugObj) {
            $drugFullArray[$drugObj->short_name_id] = $drugObj->id;
        }
        foreach ($drugShortObjs as $drugShortObj) {
            $drugShortArray[$drugShortObj->name] = $drugShortObj->id;
        }
        $modelUserProfile = UserProfile::findProfileByUserId(Yii::$app->user->id);
        $dataGet = Yii::$app->request->get('dump');
        if ($dataGet) {
            if ($dataGet == 'dump') {
                $modelsPatient = Patient::dumpDbExcel($modelUserProfile);
            } elseif ($dataGet == 'dumpEECA') {
                $modelsPatient = Patient::dumpDbExcel($modelUserProfile, true);
            }

            $modelsApi = PatientSequencesApi::apiForDump();
            $counter = 0;
            $excelArray = [];
            $apiArray = [];
            $cardNumber = '';
            $counterDup = 1;
            foreach ($modelsApi as $api) {
                $apiArray[$api->patient_seq_id] = $api;
            }

            foreach ($modelsPatient as $modelPatient) {
                /* @var $modelPatient \app\models\Patient */
                //Массив основных данных в еденичном числе

                //Дописывание постфикса дубликатам
                if ($cardNumber == $modelPatient->card_number && $modelPatient->possible_dup_id != 0) {
                    //$excelArray[$counter - 1]['main']['card_number'] = $modelPatient->card_number . '_DUP' . $counterDup;
                    $dupCardNumber = $modelPatient->card_number . '_DUP' . $counterDup;
                    $counterDup += 1;
                } else {
                    $counterDup = 1;
                    $dupCardNumber = '';
                }
                $cardNumber = $modelPatient->card_number;

                $excelArray[$counter] = [
                    'main' => [
                        'type_project' => $modelPatient->project_type,
                        'card_number' => $dupCardNumber ? $dupCardNumber : $cardNumber,
                        'sex' => Yii::$app->params['genders'][$modelPatient->gender],
                        'bDay' => $modelPatient->birthday,
                        'HIVBlotDate' => $modelPatient->first_hiv_blot_date_day . '-' . $modelPatient->first_hiv_blot_date_month . '-' .
                            $modelPatient->first_hiv_blot_date_year,
                        'HIVBlotYear' => $modelPatient->first_hiv_blot_date_year,
                        'infectionCode' => $modelPatient->spravInfectionCode->text,
                        'inspectionCode' => $modelPatient->spravInspectionCode->code,
                        'infectionWay' => $modelPatient->spravInfectionWay->text,
                        'infectionDate' => $modelPatient->infection_date_day . '-' . $modelPatient->infection_date_month . '-' .
                            $modelPatient->infection_date_year,
                        'infectionYear' => $modelPatient->infection_date_year,
                        'ARVP' => $binary[$modelPatient->arvp],
                        'organization' => $modelPatient->center->full_name,
                        'comment' => $modelPatient->comment,
                        'save_date' => date('d-m-Y', $modelPatient->created_at),
                        'timestamp' => $modelPatient->created_at,
                        'residence' => [
                            'residenceCity' => $modelPatient->livingCity->name,
                            'residenceRegion' => $modelPatient->livingRegion->name,
                            'residenceFO' => $modelPatient->livingDistrict->name,
                            'residenceCountry' => $modelPatient->livingCountry->name
                        ],
                        'infectionRegion' => [
                            'infectionCountry' => $modelPatient->infectionCountry->name,
                            'infectionCity' => $modelPatient->infectionRegion->name
                        ],
                    ],
                ];

                $patientId = $modelPatient->id;

                //Стадии заболевания
                foreach ($modelPatient->diseaseStage as $stageArray) {
                    if ($stageArray->date_day && $stageArray->date_month && $stageArray->date_year) {
                        $fullDateStage = $stageArray->date_day . '.' . $stageArray->date_month . '.' . $stageArray->date_year;
                        $excelArray[$counter]['multiple']['curseStage'][] = [$fullDateStage, $stageArray->date_year, $stageArray->stageName->name];
                    } elseif ($stageArray->date_month && $stageArray->date_year) {
                        $withoutDayStage = $stageArray->date_month . '.' . $stageArray->date_year;
                        $excelArray[$counter]['multiple']['curseStage'][] = [$withoutDayStage, $stageArray->date_year, $stageArray->stageName->name];
                    } elseif ($stageArray->date_year) {
                        $excelArray[$counter]['multiple']['curseStage'][] = ['', $stageArray->date_year, $stageArray->stageName->name];
                    } else {
                        $excelArray[$counter]['multiple']['curseStage'][] = ['', '', $stageArray->stageName->name];
                    }
                }

                //Вирусная нагрузка
                foreach ($modelPatient->viralLoad as $viralArray) {
                    if ($viralArray->value == -50) {
                        $actualViralValue = 'менее 50';
                    } elseif ($viralArray->value == -500) {
                        $actualViralValue = 'менее 500';
                    } else {
                        $actualViralValue = $viralArray->value;
                    }
                    if ($viralArray->date_day && $viralArray->date_month && $viralArray->date_year) {
                        $fullDateViral = $viralArray->date_day . '.' . $viralArray->date_month . '.' . $viralArray->date_year;
                        $excelArray[$counter]['multiple']['viralLoad'][] = [$fullDateViral, $viralArray->date_year, $actualViralValue];
                    } elseif ($viralArray->date_month && $viralArray->date_year) {
                        $withoutDayViral = $viralArray->date_month . '.' . $viralArray->date_year;
                        $excelArray[$counter]['multiple']['viralLoad'][] = [$withoutDayViral, $viralArray->date_year, $actualViralValue];
                    } elseif ($viralArray->date_year) {
                        $excelArray[$counter]['multiple']['viralLoad'][] = ['', $viralArray->date_year, $actualViralValue];
                    } else {
                        $excelArray[$counter]['multiple']['viralLoad'][] = ['', '', $actualViralValue];
                    }
                }

                //СD4 Test
                foreach ($modelPatient->cdTest as $cdArray) {
                    if ($cdArray->date_day && $cdArray->date_month && $cdArray->date_year) {
                        $fullDateCd = $cdArray->date_day . '.' . $cdArray->date_month . '.' . $cdArray->date_year;
                        $excelArray[$counter]['multiple']['cdTest'][] = [$fullDateCd, $cdArray->date_year, $cdArray->value];
                    } elseif ($cdArray->date_month && $cdArray->date_year) {
                        $withoutDayCd = $cdArray->date_month . '.' . $cdArray->date_year;
                        $excelArray[$counter]['multiple']['cdTest'][] = [$withoutDayCd, $cdArray->date_year, $cdArray->value];
                    } elseif ($cdArray->date_year) {
                        $excelArray[$counter]['multiple']['cdTest'][] = ['', $cdArray->date_year, $cdArray->value];
                    } else {
                        $excelArray[$counter]['multiple']['cdTest'][] = ['', '', $cdArray->value];
                    }
                }

                //HLA
                foreach ($modelPatient->hla as $hlaArray) {
                    if ($hlaArray->date_day && $hlaArray->date_month && $hlaArray->date_year) {
                        $fullDateHla = $hlaArray->date_day . '.' . $hlaArray->date_month . '.' . $hlaArray->date_year;
                        $excelArray[$counter]['multiple']['hla'][] = [$fullDateHla, $hlaArray->date_year, $hlaArray->value];
                    } elseif ($hlaArray->date_month && $hlaArray->date_year) {
                        $withoutDayHla = $hlaArray->date_month . '.' . $hlaArray->date_year;
                        $excelArray[$counter]['multiple']['hla'][] = [$withoutDayHla, $hlaArray->date_year, $hlaArray->value];
                    } elseif ($hlaArray->date_year) {
                        $excelArray[$counter]['multiple']['hla'][] = ['', $hlaArray->date_year, $hlaArray->value];
                    } else {
                        $excelArray[$counter]['multiple']['hla'][] = ['', '', $hlaArray->value];
                    }
                }

                //Therapy
                foreach ($modelPatient->therapy as $therapyArray) {
                    $shortDrugs = CountAndStat::shortToId($therapyArray->drugs, $drugFullArray, $drugShortArray, 'out');
                    $excelArray[$counter]['multiple']['therapy'][] = [
                        $therapyArray->date_begin_day . '.' . $therapyArray->date_begin_month . '.' . $therapyArray->date_begin_year,
                        $therapyArray->date_end_day . '.' . $therapyArray->date_end_month . '.' . $therapyArray->date_end_year,
                        $shortDrugs,
                        $therapyArray->drugs
                    ];
                }

                //Sequences and Api
                foreach ($modelPatient->sequence as $sequenceArray) {
                    $type = Yii::$app->params['seq_type_array'][$sequenceArray->type];
                    $method = Yii::$app->params['method_of_sequencing'][$sequenceArray->method_of_sequencing];
                    if ($sequenceArray->date_day && $sequenceArray->date_month && $sequenceArray->date_year) {
                        $fullDateSeq = $sequenceArray->date_day . '.' . $sequenceArray->date_month . '.' . $sequenceArray->date_year;
                        $excelArray[$counter]['multiple']['sequence'][] = [$sequenceArray->seq, $type, $method, $fullDateSeq];
                    } elseif ($sequenceArray->date_month && $sequenceArray->date_year) {
                        $withoutDaySeq = $sequenceArray->date_month . '.' . $sequenceArray->date_year;
                        $excelArray[$counter]['multiple']['sequence'][] = [$sequenceArray->seq, $type, $method, $withoutDaySeq];
                    } elseif ($sequenceArray->date_year) {
                        $excelArray[$counter]['multiple']['sequence'][] = [$sequenceArray->seq, $type,$method, $sequenceArray->date_year];
                    } else {
                        $excelArray[$counter]['multiple']['sequence'][] = [$sequenceArray->seq, $type, $method, ''];
                    }

                    $reasonArray = [];
                    if ($apiArray[$sequenceArray->id]->qc_reasons) {
                        $preExcelReasonString = explode(':', $apiArray[$sequenceArray->id]->qc_reasons);
                        foreach ($preExcelReasonString as $reason) {
                            $reasonArray[] = Yii::$app->params['nReasonsPro'][$reason];
                        }
                        $reasonToExcel = implode('. ', $reasonArray);
                    } else {
                        $reasonToExcel = '';
                    }
                    if (Yii::$app->params['seq_type_array'][$sequenceArray->type] == $proRev) {
                        $excelArray[$counter]['main']['nReasonPro'] = $reasonToExcel;
                        $excelArray[$counter]['main']['qcPro'] = $apiArray[$sequenceArray->id]->qc;
                        $excelArray[$counter]['main']['qcSubtype'] = $apiArray[$sequenceArray->id]->subtype;
                        $excelArray[$counter]['main']['accessoryMutations'] = $apiArray[$sequenceArray->id]->Pro_Pr_Accessory;
                        $excelArray[$counter]['main']['otherMutations'] = $apiArray[$sequenceArray->id]->Pro_Pr_Other;
                        $excelArray[$counter]['main']['majorMutations'] = $apiArray[$sequenceArray->id]->Pro_PI_Major;
                        $excelArray[$counter]['main']['NRTI'] = $apiArray[$sequenceArray->id]->Rev_NRTI;
                        $excelArray[$counter]['main']['NNRTI'] = $apiArray[$sequenceArray->id]->Rev_NNRTI;
                        $excelArray[$counter]['main']['otherRev'] = $apiArray[$sequenceArray->id]->Rev_Rt_Other;
                        $excelArray[$counter]['main']['ATV'] = $apiArray[$sequenceArray->id]->ATV;
                        $excelArray[$counter]['main']['DRV'] = $apiArray[$sequenceArray->id]->DRV;
                        $excelArray[$counter]['main']['FPV'] = $apiArray[$sequenceArray->id]->FPV;
                        $excelArray[$counter]['main']['IDV'] = $apiArray[$sequenceArray->id]->IDV;
                        $excelArray[$counter]['main']['LPV'] = $apiArray[$sequenceArray->id]->LPV;
                        $excelArray[$counter]['main']['NFV'] = $apiArray[$sequenceArray->id]->NFV;
                        $excelArray[$counter]['main']['SQV'] = $apiArray[$sequenceArray->id]->SQV;
                        $excelArray[$counter]['main']['TPV'] = $apiArray[$sequenceArray->id]->TPV;
                        $excelArray[$counter]['main']['ABC'] = $apiArray[$sequenceArray->id]->ABC;
                        $excelArray[$counter]['main']['AZT'] = $apiArray[$sequenceArray->id]->AZT;
                        $excelArray[$counter]['main']['D4T'] = $apiArray[$sequenceArray->id]->D4T;
                        $excelArray[$counter]['main']['DDI'] = $apiArray[$sequenceArray->id]->DDI;
                        $excelArray[$counter]['main']['FTC'] = $apiArray[$sequenceArray->id]->FTC;
                        $excelArray[$counter]['main']['3TC'] = $apiArray[$sequenceArray->id]->three_TC;
                        $excelArray[$counter]['main']['TDF'] = $apiArray[$sequenceArray->id]->TDF;
                        $excelArray[$counter]['main']['DOR'] = $apiArray[$sequenceArray->id]->DOR;
                        $excelArray[$counter]['main']['EFV'] = $apiArray[$sequenceArray->id]->EFV;
                        $excelArray[$counter]['main']['ETR'] = $apiArray[$sequenceArray->id]->ETR;
                        $excelArray[$counter]['main']['NVP'] = $apiArray[$sequenceArray->id]->NVP;
                        $excelArray[$counter]['main']['RPV'] = $apiArray[$sequenceArray->id]->RPV;
                    } elseif (Yii::$app->params['seq_type_array'][$sequenceArray->type] == $int) {
                        $excelArray[$counter]['main']['nReasonInt'] = $reasonToExcel;
                        $excelArray[$counter]['main']['qcInt'] = $apiArray[$sequenceArray->id]->qc;
                        $excelArray[$counter]['main']['qcSubtypeInt'] = $apiArray[$sequenceArray->id]->subtype;
                        $excelArray[$counter]['main']['accessoryMutationsInt'] = $apiArray[$sequenceArray->id]->Pro_Pr_Accessory;
                        $excelArray[$counter]['main']['otherMutationsInt'] = $apiArray[$sequenceArray->id]->Pro_Pr_Other;
                        $excelArray[$counter]['main']['majorMutationsInt'] = $apiArray[$sequenceArray->id]->Pro_PI_Major;
                        $excelArray[$counter]['main']['BIC'] = $apiArray[$sequenceArray->id]->BIC;
                        $excelArray[$counter]['main']['CAB'] = $apiArray[$sequenceArray->id]->CAB;
                        $excelArray[$counter]['main']['DTG'] = $apiArray[$sequenceArray->id]->DTG;
                        $excelArray[$counter]['main']['EVG'] = $apiArray[$sequenceArray->id]->EVG;
                        $excelArray[$counter]['main']['RAL'] = $apiArray[$sequenceArray->id]->RAL;
                    } elseif (Yii::$app->params['seq_type_array'][$sequenceArray->type] == $env) {
                        $excelArray[$counter]['main']['FPR'] = $apiArray[$sequenceArray->id]->fpr;
                        $excelArray[$counter]['main']['coreceptor'] = Yii::$app->params['receptor'][$apiArray[$sequenceArray->id]->receptor];
                    }
                }
                $counter++;
            }

            //Счетчики на поличество полей терапий
            $countersParametersArray = CountAndStat::maxPatientParameters($excelArray);

            //Создание Эксель файла
            $folder = Yii::getAlias('@app/runtime/') . 'upload/';

            if (!is_dir($folder)) {
                FileHelper::createDirectory($folder);
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            //Центрирование содержимого ячеек
            $sheet->getStyle('A:EO')->getAlignment()->setHorizontal('center');
            $sheet->getStyle('A:EO')->getAlignment()->setVertical('center');

            $cellsCounter = 3;

            //Создаем шапку файла
            //Базовые данные по пациенту
            $staticData = [
                'type' => 1,
                'card_number' => 2,
                'sex' => 3,
                'bday' => 4,
                'blot_date' => 5,
                'blot_year' => 6,
                'exam_code' => 7,
                'inf_code' => 8,
                'inf_way' => 9,
                'inf_date' => 10,
                'inf_year' => 11,
                'arvt' => 12
            ];
            $sheet->setCellValueByColumnAndRow($staticData['type'], 1, 'Тип исследовательского проекта');
            $sheet->setCellValueByColumnAndRow($staticData['card_number'], 1, 'Уникальный номер пациента (Номер карты)');
            $sheet->setCellValueByColumnAndRow($staticData['sex'], 1, 'Пол');
            $sheet->setCellValueByColumnAndRow($staticData['bday'], 1, 'Дата рождения');
            $sheet->setCellValueByColumnAndRow($staticData['blot_date'], 1, 'Дата первого ВИЧ+ блота');
            $sheet->setCellValueByColumnAndRow($staticData['blot_year'], 1, 'Год первого ВИЧ+ блота');
            $sheet->setCellValueByColumnAndRow($staticData['exam_code'], 1, 'Код обследования');
            $sheet->setCellValueByColumnAndRow($staticData['inf_code'], 1, 'Код инфицирования');
            $sheet->setCellValueByColumnAndRow($staticData['inf_way'], 1, 'Предполагаемый путь инфицирования');
            $sheet->setCellValueByColumnAndRow($staticData['inf_date'], 1, 'Дата инфицирования');
            $sheet->setCellValueByColumnAndRow($staticData['inf_year'], 1, 'Год инфицирования');
            $sheet->setCellValueByColumnAndRow($staticData['arvt'], 1, 'Получение АРВП');
            foreach ($staticData as $columnNumber) {
                $sheet->mergeCells((Coordinate::stringFromColumnIndex($columnNumber)) . '1:' . (Coordinate::stringFromColumnIndex($columnNumber)) . '2');
            }

            $regionData = [
                'region_header' => 13,
                'country' => 13,
                'town' => 14,
                'region' => 15,
                'district' => 16,
                'inf_region_header' => 17,
                'inf_country' => 17,
                'inf_region' => 18,
            ];
            //Регионы
            $regionLines = ['Страна', 'Город', 'Регион', 'ФО'];
            $sheet->setCellValueByColumnAndRow($regionData['region_header'], 1, 'Регион проживания');
            $sheet->setCellValueByColumnAndRow($regionData['country'], 2, $regionLines[0]);
            $sheet->setCellValueByColumnAndRow($regionData['town'], 2, $regionLines[1]);
            $sheet->setCellValueByColumnAndRow($regionData['region'], 2, $regionLines[2]);
            $sheet->setCellValueByColumnAndRow($regionData['district'], 2, $regionLines[3]);
            $sheet->mergeCells((Coordinate::stringFromColumnIndex($regionData['region_header'])) . '1:' . (Coordinate::stringFromColumnIndex($regionData['region_header'] + 3)) . '1');
            $sheet->setCellValueByColumnAndRow($regionData['inf_region_header'], 1, 'Регион инфицирования');
            $sheet->setCellValueByColumnAndRow($regionData['inf_country'], 2, $regionLines[0]);
            $sheet->setCellValueByColumnAndRow($regionData['inf_region'], 2, $regionLines[2]);
            $sheet->mergeCells((Coordinate::stringFromColumnIndex($regionData['inf_region_header'])) . '1:' . (Coordinate::stringFromColumnIndex($regionData['inf_region_header'] + 1)) . '1');

            $startColumn = 19;//count($staticData) + count($regionData);
            //Начало счетчика стадий
            $startColumnStage = $startColumn;
            //Стадии заболевания
            $maxStage = $countersParametersArray['curseStage'];
            $stageHeaderCounter = 1;
            $stageLines = ['Дата исследования', 'Год исследования', 'Стадия'];
            while ($maxStage > 0) {
                $startCellStage = Coordinate::stringFromColumnIndex($startColumn);
                $sheet->setCellValueByColumnAndRow($startColumn, 1, 'Стадия заболевания ' . $stageHeaderCounter);
                $sheet->setCellValueByColumnAndRow($startColumn, 2, $stageLines[0]);
                $sheet->setCellValueByColumnAndRow($startColumn + 1, 2, $stageLines[1]);
                $sheet->setCellValueByColumnAndRow($startColumn + 2, 2, $stageLines[2]);
                $maxStage -= 1;
                $stageHeaderCounter += 1;
                $startColumn += count($stageLines);
                $endCellStage = Coordinate::stringFromColumnIndex($startColumn - 1);
                $sheet->mergeCells($startCellStage . '1:' . $endCellStage . '1');
            }
            //Финальное значение счетчика после которого начинается новый параметр для проверки
            $endColumnStage = $startColumn - 1;

            //Вирусная нагрузка
            $maxViral = $countersParametersArray['viralLoad'];
            $startColumnViral = $startColumn;
            $viralHeaderCounter = 1;
            $viralLines = ['Дата', 'Год', 'Показатель'];
            while ($maxViral > 0) {
                $startCellViral = Coordinate::stringFromColumnIndex($startColumn);
                $sheet->setCellValueByColumnAndRow($startColumn, 1, 'Вирусная нагрузка ' . $viralHeaderCounter);
                $sheet->setCellValueByColumnAndRow($startColumn, 2, $viralLines[0]);
                $sheet->setCellValueByColumnAndRow($startColumn + 1, 2, $viralLines[1]);
                $sheet->setCellValueByColumnAndRow($startColumn + 2, 2, $viralLines[2]);
                $maxViral -= 1;
                $viralHeaderCounter += 1;
                $startColumn += 3;
                $endCellViral = Coordinate::stringFromColumnIndex($startColumn - 1);
                $sheet->mergeCells($startCellViral . '1:' . $endCellViral . '1');
            }
            $endColumnViral = $startColumn - 1;

            //Уровень CD4
            $maxCd4 = $countersParametersArray['cdTest'];
            $startColumnCd4 = $startColumn;
            $cd4HeaderCounter = 1;
            $cd4Lines = ['Дата', 'Год', 'Показатель'];
            while ($maxCd4 > 0) {
                $startCellCd4 = Coordinate::stringFromColumnIndex($startColumn);
                $sheet->setCellValueByColumnAndRow($startColumn, 1, 'Уровень CD4 ' . $cd4HeaderCounter);
                $sheet->setCellValueByColumnAndRow($startColumn, 2, $cd4Lines[0]);
                $sheet->setCellValueByColumnAndRow($startColumn + 1, 2, $cd4Lines[1]);
                $sheet->setCellValueByColumnAndRow($startColumn + 2, 2, $cd4Lines[2]);
                $maxCd4 -= 1;
                $cd4HeaderCounter += 1;
                $startColumn += 3;
                $endCellCd4 = Coordinate::stringFromColumnIndex($startColumn - 1);
                $sheet->mergeCells($startCellCd4 . '1:' . $endCellCd4 . '1');
            }
            $endColumnCd4 = $startColumn - 1;

            //Аллель
            $startColumnAllele = $startColumn;
            $startCellAllele = Coordinate::stringFromColumnIndex($startColumn);
            $sheet->setCellValueByColumnAndRow($startColumn, 1, 'Аллель');
            $sheet->setCellValueByColumnAndRow($startColumn, 2, 'Дата');
            $sheet->setCellValueByColumnAndRow($startColumn + 1, 2, 'Год');
            $sheet->setCellValueByColumnAndRow($startColumn + 2, 2, 'Наличие аллели');
            $startColumn += 3;
            $endCellAllele = Coordinate::stringFromColumnIndex($startColumn - 1);
            $sheet->mergeCells($startCellAllele . '1:' . $endCellAllele . '1');
            $endColumnAllele = $startColumn - 1;

            //Терапия
            $maxTherapy = $countersParametersArray['therapy'];
            $startColumnTherapy = $startColumn;
            $therapyHeaderCounter = 1;
            $therapyLines = ['Дата начала', 'Дата окончания', 'Приверженность', 'Препараты'];
            while ($maxTherapy > 0) {
                $startCellTherapy = Coordinate::stringFromColumnIndex($startColumn);
                $sheet->setCellValueByColumnAndRow($startColumn, 1, 'Терапия ' . $therapyHeaderCounter);
                $sheet->setCellValueByColumnAndRow($startColumn, 2, $therapyLines[0]);
                $sheet->setCellValueByColumnAndRow($startColumn + 1, 2, $therapyLines[1]);
                $sheet->setCellValueByColumnAndRow($startColumn + 2, 2, $therapyLines[2]);
                $sheet->setCellValueByColumnAndRow($startColumn + 3, 2, $therapyLines[3]);
                $maxTherapy -= 1;
                $therapyHeaderCounter += 1;
                $startColumn += 4;
                $endCellTherapy = Coordinate::stringFromColumnIndex($startColumn - 1);
                $sheet->mergeCells($startCellTherapy . '1:' . $endCellTherapy . '1');
            }
            $endColumnTherapy = $startColumn - 1;

            //Последовательность
            $startCellSeq = Coordinate::stringFromColumnIndex($startColumn);

            $sheet->setCellValueByColumnAndRow($startColumn, 1, 'Последовательность');
            //pro-rev
            $startColumnSeqPro = $startColumn;
            $sheet->setCellValueByColumnAndRow($startColumn, 2, 'Дата забора образца pro-rev');
            $sheet->setCellValueByColumnAndRow($startColumn + 1, 2, 'Способ секвенирования pro-rev');
            $sheet->setCellValueByColumnAndRow($startColumn + 2, 2,'pro-rev');
            //pro-rev-int
            $startColumnSeqProRevInt = $startColumn + 3;
            $sheet->setCellValueByColumnAndRow($startColumn + 3, 2, 'Дата забора образца pro-rev-int');
            $sheet->setCellValueByColumnAndRow($startColumn + 4, 2,'Способ секвенирования pro-rev-int');
            $sheet->setCellValueByColumnAndRow($startColumn + 5, 2, 'pro-rev-int');
            //int
            $startColumnSeqInt = $startColumn + 6;
            $sheet->setCellValueByColumnAndRow($startColumn + 6, 2, 'Дата забора образца int');
            $sheet->setCellValueByColumnAndRow($startColumn + 7, 2,'Способ секвенирования int');
            $sheet->setCellValueByColumnAndRow($startColumn + 8, 2,'int');
            //env
            $startColumnSeqEnv = $startColumn + 9;
            $sheet->setCellValueByColumnAndRow($startColumn + 9, 2, 'Дата забора образца env');
            $sheet->setCellValueByColumnAndRow($startColumn + 10, 2, 'Способ секвенирования env');
            $sheet->setCellValueByColumnAndRow($startColumn + 11, 2, 'env');
            //full
            $startColumnSeqFull = $startColumn + 12;
            $sheet->setCellValueByColumnAndRow($startColumn + 12, 2, 'Дата забора образца full');
            $sheet->setCellValueByColumnAndRow($startColumn + 13, 2,'Способ секвенирования full');
            $sheet->setCellValueByColumnAndRow($startColumn + 14, 2, 'full');
            $startColumn += 15;
            $endCellSeq = Coordinate::stringFromColumnIndex($startColumn - 1);
            $sheet->mergeCells($startCellSeq . '1:' . $endCellSeq . '1');

            $firstAnalysePro =[
                'full_org_name' => $startColumn,
                'comment' => $startColumn + 1,
                'user' => $startColumn + 2,
                'add_date' => $startColumn + 3,
                'qc' => $startColumn + 4,
                'fail_qc_reasons' => $startColumn + 5,
                'identity_check' => $startColumn + 6,
                'subtype' => $startColumn + 7
            ];
            $sheet->setCellValueByColumnAndRow($firstAnalysePro['full_org_name'], 1, 'Полное название организации');
            $sheet->setCellValueByColumnAndRow($firstAnalysePro['comment'], 1, 'Комментарий');
            $sheet->setCellValueByColumnAndRow($firstAnalysePro['user'], 1, 'Пользователь');
            $sheet->setCellValueByColumnAndRow($firstAnalysePro['add_date'], 1, 'Дата внесения записи');
            $sheet->setCellValueByColumnAndRow($firstAnalysePro['qc'], 1, 'Контроль качества pro-rev/full');
            $sheet->setCellValueByColumnAndRow($firstAnalysePro['fail_qc_reasons'], 1, 'QC-nReason pro-rev/full');
            $sheet->setCellValueByColumnAndRow($firstAnalysePro['identity_check'], 1, 'Проверка на идеентичность pro-rev/full');
            $sheet->setCellValueByColumnAndRow($firstAnalysePro['subtype'], 1, 'Субтип pro-rev/full');
            foreach ($firstAnalysePro as $columnNumber) {
                $sheet->mergeCells((Coordinate::stringFromColumnIndex($columnNumber)) . '1:' . (Coordinate::stringFromColumnIndex($columnNumber)) . '2');
            }
            $startColumn += 8;

            $proteaseMutations = [
                'mutation_header' => $startColumn,
                'main' => $startColumn,
                'accessory' => $startColumn + 1,
                'other' => $startColumn + 2
            ];
            $startCellProtMut = $proteaseMutations['mutation_header'];
            $sheet->setCellValueByColumnAndRow($proteaseMutations['mutation_header'], 1,'Мутации в протеазе');
            $sheet->setCellValueByColumnAndRow($proteaseMutations['main'], 2, 'Основные');
            $sheet->setCellValueByColumnAndRow($proteaseMutations['accessory'], 2, 'Дополнительные');
            $sheet->setCellValueByColumnAndRow($proteaseMutations['other'], 2, 'Другие');
            $startColumn += 3;
            $endCellProtMut = $startColumn - 1;
            $sheet->mergeCells(Coordinate::stringFromColumnIndex($startCellProtMut) . '1:' . Coordinate::stringFromColumnIndex($endCellProtMut) . '1');


            $transcriptaseMutations = [
                'mutation_header' => $startColumn,
                'NIOT' => $startColumn,
                'NNIOT' => $startColumn + 1,
                'other' => $startColumn + 2
            ];
            $startCellTrascriptMut = $transcriptaseMutations['mutation_header'];
            $sheet->setCellValueByColumnAndRow($transcriptaseMutations['mutation_header'], 1, 'Мутации в обратной транскриптазе');
            $sheet->setCellValueByColumnAndRow($transcriptaseMutations['NIOT'], 2, 'НИОТ');
            $sheet->setCellValueByColumnAndRow($transcriptaseMutations['NNIOT'], 2, 'ННИОТ');
            $sheet->setCellValueByColumnAndRow($transcriptaseMutations['other'], 2, 'Другие');
            $startColumn += 3;
            $endCellTrascriptMut = $startColumn - 1;
            $sheet->mergeCells(Coordinate::stringFromColumnIndex($startCellTrascriptMut) . '1:' . Coordinate::stringFromColumnIndex($endCellTrascriptMut) . '1');

            $resistanceProtease = [
                'resistance_header' => $startColumn,
                'ATV' => $startColumn, 'DRV' => $startColumn + 1, 'FPV' => $startColumn + 2, 'IDV' => $startColumn + 3,
                'LPV' => $startColumn + 4, 'NFV' => $startColumn + 5, 'SQV' => $startColumn + 6, 'TPV' => $startColumn + 7
            ];
            $startCellResistPro = $resistanceProtease['resistance_header'];
            $sheet->setCellValueByColumnAndRow($resistanceProtease['resistance_header'], 1, 'Уровень резистентности к ИП');
            $sheet->setCellValueByColumnAndRow($resistanceProtease['ATV'], 2, 'ATV');
            $sheet->setCellValueByColumnAndRow($resistanceProtease['DRV'], 2, 'DRV');
            $sheet->setCellValueByColumnAndRow($resistanceProtease['FPV'], 2, 'FPV');
            $sheet->setCellValueByColumnAndRow($resistanceProtease['IDV'], 2, 'IDV');
            $sheet->setCellValueByColumnAndRow($resistanceProtease['LPV'], 2, 'LPV');
            $sheet->setCellValueByColumnAndRow($resistanceProtease['NFV'], 2, 'NFV');
            $sheet->setCellValueByColumnAndRow($resistanceProtease['SQV'], 2, 'SQV');
            $sheet->setCellValueByColumnAndRow($resistanceProtease['TPV'], 2, 'TPV');
            $startColumn += 8;
            $endCellResistPro = $startColumn - 1;
            $sheet->mergeCells(Coordinate::stringFromColumnIndex($startCellResistPro) . '1:' . Coordinate::stringFromColumnIndex($endCellResistPro) . '1');

            $resistanceNiot = [
                'resistance_header' => $startColumn,
                'ABC' => $startColumn, 'AZT' => $startColumn + 1, 'D4T' => $startColumn + 2, 'DDI' => $startColumn + 3,
                'FTC' => $startColumn + 4, '3TC' => $startColumn + 5, 'TDF' => $startColumn + 6
            ];
            $startCellResistNiot = $resistanceNiot['resistance_header'];
            $sheet->setCellValueByColumnAndRow($resistanceNiot['resistance_header'], 1,  'Уровень резистентности к НИОТ');
            $sheet->setCellValueByColumnAndRow($resistanceNiot['ABC'], 2, 'ABC');
            $sheet->setCellValueByColumnAndRow($resistanceNiot['AZT'], 2, 'AZT');
            $sheet->setCellValueByColumnAndRow($resistanceNiot['D4T'], 2, 'D4T');
            $sheet->setCellValueByColumnAndRow($resistanceNiot['DDI'], 2, 'DDI');
            $sheet->setCellValueByColumnAndRow($resistanceNiot['FTC'], 2, 'FTC');
            $sheet->setCellValueByColumnAndRow($resistanceNiot['3TC'], 2, '3TC');
            $sheet->setCellValueByColumnAndRow($resistanceNiot['TDF'], 2, 'TDF');
            $startColumn += 7;
            $endCellResistNiot = $startColumn - 1;
            $sheet->mergeCells(Coordinate::stringFromColumnIndex($startCellResistNiot) . '1:' . Coordinate::stringFromColumnIndex($endCellResistNiot) . '1');

            $resistanceNNiot = [
                'resistance_header' => $startColumn,
                'DOR' => $startColumn, 'EFV' => $startColumn + 1, 'ETR' => $startColumn + 2,
                'NVP' => $startColumn + 3, 'RPV' => $startColumn + 4
            ];
            $startCellResistNNiot = $resistanceNNiot['resistance_header'];
            $sheet->setCellValueByColumnAndRow($resistanceNNiot['resistance_header'], 1, 'Уровень резистентности к ННИОТ');
            $sheet->setCellValueByColumnAndRow($resistanceNNiot['DOR'], 2, 'DOR');
            $sheet->setCellValueByColumnAndRow($resistanceNNiot['EFV'], 2, 'EFV');
            $sheet->setCellValueByColumnAndRow($resistanceNNiot['ETR'], 2, 'ETR');
            $sheet->setCellValueByColumnAndRow($resistanceNNiot['NVP'], 2, 'NVP');
            $sheet->setCellValueByColumnAndRow($resistanceNNiot['RPV'], 2, 'RPV');
            $startColumn += 5;
            $endCellResistNNiot = $startColumn - 1;
            $sheet->mergeCells(Coordinate::stringFromColumnIndex($startCellResistNNiot) . '1:' . Coordinate::stringFromColumnIndex($endCellResistNNiot) . '1');

            $firstAnalyseInt = [
                'int_qc' => $startColumn,
                'fail_qc_reason' => $startColumn + 1,
                'identity' => $startColumn + 2,
                'subtype' => $startColumn + 3
            ];
            $sheet->setCellValueByColumnAndRow($firstAnalyseInt['int_qc'], 1, 'Контроль качества int/full');
            $sheet->setCellValueByColumnAndRow($firstAnalyseInt['fail_qc_reason'], 1, 'QC-nReason int/full');
            $sheet->setCellValueByColumnAndRow($firstAnalyseInt['identity'], 1, 'Проверка на идеентичность int/full');
            $sheet->setCellValueByColumnAndRow($firstAnalyseInt['subtype'], 1, 'Субтип int/full');
            foreach ($firstAnalyseInt as $columnNumber) {
                $sheet->mergeCells((Coordinate::stringFromColumnIndex($columnNumber)) . '1:' . (Coordinate::stringFromColumnIndex($columnNumber)) . '2');
            }
            $startColumn += 4;

            $integrasMutations = [
                'mutation_header' => $startColumn,
                'main' => $startColumn,
                'accessory' => $startColumn + 1,
                'other' => $startColumn + 2
            ];
            $startCellIntMut = $integrasMutations['mutation_header'];
            $sheet->setCellValueByColumnAndRow($integrasMutations['mutation_header'], 1, 'Мутации в интегразе');
            $sheet->setCellValueByColumnAndRow($integrasMutations['main'], 2, 'Основные');
            $sheet->setCellValueByColumnAndRow($integrasMutations['accessory'], 2, 'Дополнительные');
            $sheet->setCellValueByColumnAndRow($integrasMutations['other'], 2, 'Другие');
            $startColumn += 3;
            $endCellIntMut = $startColumn - 1;
            $sheet->mergeCells(Coordinate::stringFromColumnIndex($startCellIntMut) . '1:' . Coordinate::stringFromColumnIndex($endCellIntMut) . '1');

            $ingibhitIntResistance = [
                'resistance_header' => $startColumn,
                'BIC' => $startColumn, 'CAB' => $startColumn + 1, 'DTG' => $startColumn + 2,
                'EVG' => $startColumn + 3, 'RAL' => $startColumn + 4
            ];
            $startCellIntResist = $ingibhitIntResistance['resistance_header'];
            $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['resistance_header'], 1, 'Уровень резистентности к ИИ');
            $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['BIC'], 2, 'BIC');
            $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['CAB'], 2, 'CAB');
            $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['DTG'], 2, 'DTG');
            $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['EVG'], 2, 'EVG');
            $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['RAL'], 2, 'RAL');
            $startColumn += 5;
            $endCellIntResist = $startColumn - 1;
            $sheet->mergeCells(Coordinate::stringFromColumnIndex($startCellIntResist) . '1:' . Coordinate::stringFromColumnIndex($endCellIntResist) . '1');

            $identityCheck = [
                'check' => $startColumn,
                'FPR' => $startColumn + 1,
                'coreceptor' => $startColumn + 2
            ];
            $sheet->setCellValueByColumnAndRow($identityCheck['check'], 1, 'Проверка на идеентичность int/full');
            $sheet->setCellValueByColumnAndRow($identityCheck['FPR'], 1, 'Значение FPR');
            $sheet->setCellValueByColumnAndRow($identityCheck['coreceptor'], 1, 'Корецептор');
            foreach ($identityCheck as $columnNumber) {
                $sheet->mergeCells((Coordinate::stringFromColumnIndex($columnNumber)) . '1:' . (Coordinate::stringFromColumnIndex($columnNumber)) . '2');
            }
            //$sheet->setCellValueByColumnAndRow($startColumn + 3, 1, 'Таймштамп');

            $counterRows = 3;

            foreach ($excelArray as $dataArray) {
                foreach ($dataArray['multiple']['sequence'] as $seq) {
                    $sheet->setCellValueByColumnAndRow($staticData['type'], $counterRows, $dataArray['main']['type_project']);
                    //Номер карты
                    $sheet->setCellValueByColumnAndRow($staticData['card_number'], $counterRows, $dataArray['main']['card_number']);
                    //Пол
                    $sheet->setCellValueByColumnAndRow($staticData['sex'], $counterRows, $dataArray['main']['sex']);
                    //Дата рождения
                    $sheet->setCellValueByColumnAndRow($staticData['bday'], $counterRows, $dataArray['main']['bDay']);
                    //Дата и год  первого ВИЧ+ блота
                    $sheet->setCellValueByColumnAndRow($staticData['blot_date'], $counterRows, $dataArray['main']['HIVBlotDate']);
                    $sheet->setCellValueByColumnAndRow($staticData['blot_year'], $counterRows, $dataArray['main']['HIVBlotYear']);
                    //Код инфицирования
                    $sheet->setCellValueByColumnAndRow($staticData['inf_code'], $counterRows, $dataArray['main']['infectionCode']);
                    //Код заражения
                    $sheet->setCellValueByColumnAndRow($staticData['exam_code'], $counterRows, $dataArray['main']['inspectionCode']);
                    //Путь заражения
                    $sheet->setCellValueByColumnAndRow($staticData['inf_way'], $counterRows, $dataArray['main']['infectionWay']);
                    //Дата и год инфицирования
                    $sheet->setCellValueByColumnAndRow($staticData['inf_date'], $counterRows, $dataArray['main']['infectionDate']);
                    $sheet->setCellValueByColumnAndRow($staticData['inf_year'], $counterRows, $dataArray['main']['infectionYear']);
                    //Получение АРВП
                    $sheet->setCellValueByColumnAndRow($staticData['arvt'], $counterRows, $dataArray['main']['ARVP']);
                    //Город регион округ проживания
                    $sheet->setCellValueByColumnAndRow($regionData['country'], $counterRows, $dataArray['main']['residence']['residenceCountry']);
                    $sheet->setCellValueByColumnAndRow($regionData['town'], $counterRows, $dataArray['main']['residence']['residenceCity']);
                    $sheet->setCellValueByColumnAndRow($regionData['region'], $counterRows, $dataArray['main']['residence']['residenceRegion']);
                    $sheet->setCellValueByColumnAndRow($regionData['district'], $counterRows, $dataArray['main']['residence']['residenceFO']);

                    //Город регион округ инфицирования
                    $sheet->setCellValueByColumnAndRow($regionData['inf_country'], $counterRows, $dataArray['main']['infectionRegion']['infectionCountry']);
                    $sheet->setCellValueByColumnAndRow($regionData['inf_region'], $counterRows, $dataArray['main']['infectionRegion']['infectionCity']);

                    //Запись значений стадии заболевания
                    $sheet = Excel::multipleWriter($sheet, $dataArray['multiple']['curseStage'], $startColumnStage, $counterRows, 3);
                    //Запись значений вирусной нагрузки
                    $sheet = Excel::multipleWriter($sheet, $dataArray['multiple']['viralLoad'], $startColumnViral, $counterRows, 3);
                    //Запись значений тестов на CD4 клетки
                    $sheet = Excel::multipleWriter($sheet, $dataArray['multiple']['cdTest'], $startColumnCd4, $counterRows, 3);
                    //HLA
                    $sheet = Excel::multipleWriter($sheet, $dataArray['multiple']['hla'], $startColumnAllele, $counterRows, 3);
                    //Запись значений терапий
                    $sheet = Excel::multipleWriter($sheet, $dataArray['multiple']['therapy'], $startColumnTherapy, $counterRows, 4, true);

                    if ($seq[1] == $proRev) {
                        $sheet->setCellValueByColumnAndRow($startColumnSeqPro, $counterRows, $seq[3]);
                        $sheet->setCellValueByColumnAndRow($startColumnSeqPro + 1, $counterRows, $seq[2]);
                        $sheet->setCellValueByColumnAndRow($startColumnSeqPro + 2, $counterRows, $seq[0]);
                    } elseif ($seq[1] == $int) {
                        $sheet->setCellValueByColumnAndRow($startColumnSeqInt, $counterRows, $seq[3]);
                        $sheet->setCellValueByColumnAndRow($startColumnSeqInt + 1, $counterRows, $seq[2]);
                        $sheet->setCellValueByColumnAndRow($startColumnSeqInt + 2, $counterRows, $seq[0]);
                    } elseif ($seq[1] == $env) {
                        $sheet->setCellValueByColumnAndRow($startColumnSeqEnv, $counterRows, $seq[3]);
                        $sheet->setCellValueByColumnAndRow($startColumnSeqEnv + 1, $counterRows, $seq[2]);
                        $sheet->setCellValueByColumnAndRow($startColumnSeqEnv + 2, $counterRows, $seq[0]);
                    } elseif ($seq[1] == $full) {
                        $sheet->setCellValueByColumnAndRow($startColumnSeqFull, $counterRows, $seq[3]);
                        $sheet->setCellValueByColumnAndRow($startColumnSeqFull + 1, $counterRows, $seq[2]);
                        $sheet->setCellValueByColumnAndRow($startColumnSeqFull + 2, $counterRows, $seq[0]);
                    } elseif ($seq[1] == $proRevInt) {
                        $sheet->setCellValueByColumnAndRow($startColumnSeqProRevInt, $counterRows, $seq[3]);
                        $sheet->setCellValueByColumnAndRow($startColumnSeqProRevInt + 1, $counterRows, $seq[2]);
                        $sheet->setCellValueByColumnAndRow($startColumnSeqProRevInt + 2, $counterRows, $seq[0]);
                    }

                    $sheet->setCellValueByColumnAndRow($firstAnalysePro['full_org_name'], $counterRows, $dataArray['main']['organization']);
                    $sheet->setCellValueByColumnAndRow($firstAnalysePro['comment'], $counterRows, $dataArray['main']['comment']);
                    $sheet->setCellValueByColumnAndRow($firstAnalysePro['add_date'], $counterRows, $dataArray['main']['save_date']);

                    $sensitivity = Yii::$app->params['sensitivity'];
                    if($seq[1] == $proRev) {
                        //Pro-Rev
                        $sheet->setCellValueByColumnAndRow($firstAnalysePro['qc'], $counterRows, $binary[$dataArray['main']['qcPro']]);
                        $sheet->setCellValueByColumnAndRow($firstAnalysePro['fail_qc_reasons'], $counterRows, $dataArray['main']['nReasonPro']);
                        $sheet->setCellValueByColumnAndRow($firstAnalysePro['identity_check'], $counterRows, $dataArray['main']['Идеентичность']);
                        $sheet->setCellValueByColumnAndRow($firstAnalysePro['subtype'], $counterRows, $dataArray['main']['qcSubtype']);
                        $sheet->setCellValueByColumnAndRow($proteaseMutations['main'], $counterRows, $dataArray['main']['majorMutations']);
                        $sheet->setCellValueByColumnAndRow($proteaseMutations['accessory'], $counterRows, $dataArray['main']['accessoryMutations']);
                        $sheet->setCellValueByColumnAndRow($proteaseMutations['other'], $counterRows, $dataArray['main']['otherMutations']);
                        $sheet->setCellValueByColumnAndRow($transcriptaseMutations['NIOT'], $counterRows, $sensitivity[$dataArray['main']['NRTI']]);
                        $sheet->setCellValueByColumnAndRow($transcriptaseMutations['NNIOT'], $counterRows, $sensitivity[$dataArray['main']['NNRTI']]);
                        $sheet->setCellValueByColumnAndRow($transcriptaseMutations['other'], $counterRows, $sensitivity[$dataArray['main']['otherRev']]);
                        $sheet->setCellValueByColumnAndRow($resistanceProtease['ATV'], $counterRows, $sensitivity[$dataArray['main']['ATV']]);
                        $sheet->setCellValueByColumnAndRow($resistanceProtease['DRV'], $counterRows, $sensitivity[$dataArray['main']['DRV']]);
                        $sheet->setCellValueByColumnAndRow($resistanceProtease['FPV'], $counterRows, $sensitivity[$dataArray['main']['FPV']]);
                        $sheet->setCellValueByColumnAndRow($resistanceProtease['IDV'], $counterRows, $sensitivity[$dataArray['main']['IDV']]);
                        $sheet->setCellValueByColumnAndRow($resistanceProtease['LPV'], $counterRows, $sensitivity[$dataArray['main']['LPV']]);
                        $sheet->setCellValueByColumnAndRow($resistanceProtease['NFV'], $counterRows, $sensitivity[$dataArray['main']['NFV']]);
                        $sheet->setCellValueByColumnAndRow($resistanceProtease['SQV'], $counterRows, $sensitivity[$dataArray['main']['SQV']]);
                        $sheet->setCellValueByColumnAndRow($resistanceProtease['TPV'], $counterRows, $sensitivity[$dataArray['main']['TPV']]);
                        //НИОТ
                        $sheet->setCellValueByColumnAndRow($resistanceNiot['ABC'], $counterRows, $sensitivity[$dataArray['main']['ABC']]);
                        $sheet->setCellValueByColumnAndRow($resistanceNiot['AZT'], $counterRows, $sensitivity[$dataArray['main']['AZT']]);
                        $sheet->setCellValueByColumnAndRow($resistanceNiot['D4T'], $counterRows, $sensitivity[$dataArray['main']['D4T']]);
                        $sheet->setCellValueByColumnAndRow($resistanceNiot['DDI'], $counterRows, $sensitivity[$dataArray['main']['DDI']]);
                        $sheet->setCellValueByColumnAndRow($resistanceNiot['FTC'], $counterRows, $sensitivity[$dataArray['main']['FTC']]);
                        $sheet->setCellValueByColumnAndRow($resistanceNiot['3TC'], $counterRows, $sensitivity[$dataArray['main']['3TC']]);
                        $sheet->setCellValueByColumnAndRow($resistanceNiot['TDF'], $counterRows, $sensitivity[$dataArray['main']['TDF']]);
                        //ННИОТ
                        $sheet->setCellValueByColumnAndRow($resistanceNNiot['DOR'], $counterRows, $sensitivity[$dataArray['main']['DOR']]);
                        $sheet->setCellValueByColumnAndRow($resistanceNNiot['EFV'], $counterRows, $sensitivity[$dataArray['main']['EFV']]);
                        $sheet->setCellValueByColumnAndRow($resistanceNNiot['ETR'], $counterRows, $sensitivity[$dataArray['main']['ETR']]);
                        $sheet->setCellValueByColumnAndRow($resistanceNNiot['NVP'], $counterRows, $sensitivity[$dataArray['main']['NVP']]);
                        $sheet->setCellValueByColumnAndRow($resistanceNNiot['RPV'], $counterRows, $sensitivity[$dataArray['main']['RPV']]);
                    } elseif ($seq[1] == $int) {
                        //Int
                        $sheet->setCellValueByColumnAndRow($firstAnalyseInt['int_qc'], $counterRows, $binary[$dataArray['main']['qcInt']]);
                        $sheet->setCellValueByColumnAndRow($firstAnalyseInt['fail_qc_reason'], $counterRows, $dataArray['main']['nReasonInt']);
                        $sheet->setCellValueByColumnAndRow($firstAnalyseInt['subtype'], $counterRows, $dataArray['main']['qcSubtypeInt']);

                        $sheet->setCellValueByColumnAndRow($integrasMutations['main'], $counterRows, $dataArray['main']['majorMutationsInt']);
                        $sheet->setCellValueByColumnAndRow($integrasMutations['accessory'], $counterRows, $dataArray['main']['accessoryMutationsInt']);
                        $sheet->setCellValueByColumnAndRow($integrasMutations['other'], $counterRows, $dataArray['main']['otherMutationsInt']);

                        $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['BIC'], $counterRows, $sensitivity[$dataArray['main']['BIC']]);
                        $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['CAB'], $counterRows, $sensitivity[$dataArray['main']['CAB']]);
                        $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['DTG'], $counterRows, $sensitivity[$dataArray['main']['DTG']]);
                        $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['EVG'], $counterRows, $sensitivity[$dataArray['main']['EVG']]);
                        $sheet->setCellValueByColumnAndRow($ingibhitIntResistance['RAL'], $counterRows, $sensitivity[$dataArray['main']['RAL']]);
                    } elseif ($seq[1] == $env) {
                        //Env
                        $sheet->setCellValueByColumnAndRow($identityCheck['FPR'], $counterRows, $dataArray['main']['FPR']);
                        $sheet->setCellValueByColumnAndRow($identityCheck['coreceptor'], $counterRows, $dataArray['main']['coreceptor']);
                    }
                    //$sheet->setCellValueByColumnAndRow($identityCheck['coreceptor'] + 1, $counterRows, $dataArray['main']['timestamp']);
                    $cellsCounter++;
                    $counterRows++;

                }
            }

            $writer = new Xlsx($spreadsheet);
            $fileRandomName = uniqid(time());
            $writer->save(Yii::getAlias('@app/runtime/') . 'upload/' . 'dump' . $fileRandomName . '.xlsx');

            $path = Yii::getAlias('@app/runtime/') . 'upload/';
            $file = $path . 'dump' . $fileRandomName . '.xlsx';
            if (file_exists($file)) {
                Yii::$app->response->sendFile(Yii::getAlias('@app/runtime/') . 'upload/' . 'dump' . $fileRandomName . '.xlsx');
            } else {
                throw new \Exception('File not found');
            }
            return $this->render('generate-excel', [
                'modelUserProfile' => $modelUserProfile,
            ]);
        }

        return $this->render('generate-excel', [
            'modelUserProfile' => $modelUserProfile,
        ]);

    }

}
