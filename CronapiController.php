<?php

namespace app\controllers;
use app\models\Center;
use app\models\Patient;
use app\models\PatientSequencesApi;
use yii\web\Response;
use app\models\cPatient;
use yii\rest\ActiveController;
use app\components\CountAndStat;
use yii\filters\auth\CompositeAuth;
use yii\filters\auth\HttpBasicAuth;
use yii\filters\auth\QueryParamAuth;

class CronapiController extends ActiveController
{
    public $modelClass = cPatient::class;
    const NO_DATA = 'нет данных';

    public function init()
    {
        parent::init();
        \Yii::$app->user->enableSession = false;
    }

    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['contentNegotiator']['formats']['text/html'] = Response::FORMAT_HTML;
        $behaviors['authenticator'] = [
            'class' => CompositeAuth::class,
            'authMethods' => [
                HttpBasicAuth::class,
                QueryParamAuth::class
            ],
        ];

        return $behaviors;
    }

    public function actionNumbers()
    {
        //Общее количество пациентов
        $count = cPatient::patientCount();
        //Пациенты и их последовательности без опыта терапии
        $noARTVProRevModels = Patient::noARVTProRev();
        $prevalenceAllData = [];

        //Блок сбора для графика ЛУ
        //Собираем массив данных по пациенту seqId => [date(timestamp), patient_id]
        // для отсева seqId которые будут запрашиваться для анализа
        foreach ($noARTVProRevModels as $patientModel) {
            foreach ($patientModel->sequence as $patientSequenceData) {
                if($patientSequenceData->type == 1) {
                    $seqId = $patientSequenceData->id;
                    $seqPatientId = $patientSequenceData->patient_id;
                    $seqIdToPatientIdLU[$seqId] = [$seqPatientId];
                }
            }
        }

        $patientIdToSeqId = array_keys($seqIdToPatientIdLU);
        $dataStanfordLU = PatientSequencesApi::analysisDataFromId($patientIdToSeqId);
        $seqId = [];
        foreach ($dataStanfordLU as $stanfordData) {
            if ($stanfordData->qc == 1) {

                $seqIDD = array_values($seqId);
                if (!in_array($stanfordData->patient_seq_id, $seqIDD)) {
                    $seqId[] = $stanfordData->patient_seq_id;
                    $counterSeq += 1;
                    $updateDataStanford[] = $stanfordData;
                }
            }
        }

        foreach ($updateDataStanford as $stanford) {
            if ($stanford->ATV) {
                $prevalenceAllData['ATV'][] = $stanford->ATV;
            }
            if ($stanford->DRV) {
                $prevalenceAllData['DRV'][] = $stanford->DRV;
            }
            if ($stanford->FPV) {
                $prevalenceAllData['FPV'][] = $stanford->FPV;
            }
            if ($stanford->IDV) {
                $prevalenceAllData['IDV'][] = $stanford->IDV;
            }
            if ($stanford->LPV) {
                $prevalenceAllData['LPV'][] = $stanford->LPV;
            }
            if ($stanford->NFV) {
                $prevalenceAllData['NFV'][] = $stanford->NFV;
            }
            if ($stanford->SQV) {
                $prevalenceAllData['SQV'][] = $stanford->SQV;
            }
            if ($stanford->TPV) {
                $prevalenceAllData['TPV'][] = $stanford->TPV;;
            }
            if ($stanford->ABC) {
                $prevalenceAllData['ABC'][] = $stanford->ABC;
            }
            if ($stanford->D4T) {
                $prevalenceAllData['D4T'][] = $stanford->D4T;
            }
            if ($stanford->DDI) {
                $prevalenceAllData['DDI'][] = $stanford->DDI;
            }
            if ($stanford->FTC) {
                $prevalenceAllData['FTC'][] = $stanford->FTC;
            }
            if ($stanford->three_TC) {
                $prevalenceAllData['three_TC'][] = $stanford->three_TC;
            }
            if ($stanford->DOR) {
                $prevalenceAllData['DOR'][] = $stanford->DOR;
            }
            if ($stanford->EFV) {
                $prevalenceAllData['EFV'][] = $stanford->EFV;
            }
            if ($stanford->ETR) {
                $prevalenceAllData['ETR'][] = $stanford->ETR;
            }
            if ($stanford->NVP) {
                $prevalenceAllData['NVP'][] = $stanford->NVP;
            }
            if ($stanford->RPV) {
                $prevalenceAllData['RPV'][] = $stanford->RPV;
            }
        }

        foreach ($prevalenceAllData as $drugName => $drugData) {
            $prevalenceData[$drugName] = array_count_values($prevalenceAllData[$drugName]);

        }
        $numberArray = [1, 2, 3, 4, 5];
        foreach ($numberArray as $number) {
            foreach ($prevalenceData as $drugName => $prevalenceArray) {
                if (!array_key_exists($number, $prevalenceArray)) {
                    $prevalenceData[$drugName][$number] = 0;
                }
            }
        }

        $prevalenceArray = [];
        foreach ($prevalenceData as $drugName => $drugDataArray) {
            foreach ($drugDataArray as $key => $dataValue) {
                if ($key != 1 && $key != 2) {
                    $percentValue = ($dataValue/$counterSeq)*100;
                    $prevalenceArray[$drugName][$key] = round($percentValue, 2);
                }
            }
        }

        foreach ($prevalenceArray as $drugName => $drugPercentArray) {
            if ($drugName == 'three_TC') {
                $labelPrevalence[] = '3TC';
            } else {
                $labelPrevalence[] = $drugName;
            }
            $dataPrevalenceLow[] = $drugPercentArray[3];
            $dataPrevalenceMedium[] = $drugPercentArray[4];
            $dataPrevalenceHigh[] = $drugPercentArray[5];
        }

        //Блок основных цифр

        //Количество центров
        $centerCount = Center::idWithoutEECA();
        //Пациентов без опыта терапии и регионами
        $noArvtModels = cPatient::noArvtPatients();
        $noArvt = count($noArvtModels);
        //Процент тех кто без терапии от общего числа
        $arvtPersent = round(($noArvt * 100)/$count, 1);

        //Общее количество последовательностей
        $allPatientsData = Patient::uniqueSequences();
        foreach ($allPatientsData as $patient) {
            foreach ($patient->sequence as $seq) {
                if ($seq) {
                    $stanfordSeq += 1;
                }
            }
        }

        //Данные для графика распределения числа последовательностей по федеральным округам
        $federalRegion = [];

        foreach ($noArvtModels as $modelPatient) {
            $infectionDistrict = $modelPatient->living_district_id;
            if (!$infectionDistrict) {
                $federalRegion[] = self::NO_DATA;
            } else {
                $infectionDistrictName = $modelPatient->livingDistrict->name;
                $federalRegion[] = $infectionDistrictName;
            }
        }
        $federalRegionArray = CountAndStat::tableArray($federalRegion);
        unset($federalRegionArray['Всего']);

        //Блок графика субтипов
        //Собираем массив данных по пациенту seqId => [date(timestamp), patient_id]
        //для отсева seqId которые будут запрашиваться для анализа
        $dataForSubtype = Patient::patientsProRev();

        foreach ($dataForSubtype as $patientModel) {
            foreach ($patientModel->sequence as $patientSequenceData) {
                if($patientSequenceData->type == 1) {
                    $seqId = $patientSequenceData->id;
                    $seqPatientId = $patientSequenceData->patient_id;
                    $seqIdToPatientIdSubtype[$seqId] = [$seqPatientId];
                }
            }
        }

        $patientIdToSeqIdSubtype = array_keys($seqIdToPatientIdSubtype);
        //Общее количество записей

        $allSubtypesArray = [];
        $dataStanfordSubtype = PatientSequencesApi::analysisDataFromId($patientIdToSeqIdSubtype);
        foreach ($dataStanfordSubtype as $stanfordPatientModel) {
            $checkDup = array_keys($allSubtypesArray);
            if (!in_array($stanfordPatientModel->patient_id, $checkDup)) {
                $allSubtypesArray[$stanfordPatientModel->patient_id] = $stanfordPatientModel->subtype;
            }
        }

        $pieData = array_count_values($allSubtypesArray);
        $mutationsNames = array_keys($pieData);
        $mutationsValues = array_values($pieData);

        //Блок графика тренда
        $totalPatientsTrend = [];

        //Отбираем только тех у кого есть год в иммуноблоте
        foreach ($noARTVProRevModels as $noArvtModel) {
            if($noArvtModel->first_hiv_blot_date_year) {
                $patientModelExistYear[] = $noArvtModel;
            }
        }

        //Отсеиваем все последовательности кроме pro-rev и собираем ассоциативный массив patient_id => blot_year
        foreach ($patientModelExistYear as $patientModelYearExist) {
            foreach ($patientModelYearExist->sequence as $patientSequenceData) {
                if($patientSequenceData->type == 1) {
                    $seqIdYearExist = $patientSequenceData->id;
                    $seqPatientIdYearExist = $patientSequenceData->patient_id;
                    $seqIdToPatientIdYearExist[$seqIdYearExist] = [$seqPatientIdYearExist];
                    $totalPatientsTrend[$seqPatientIdYearExist] = $patientModelYearExist->first_hiv_blot_date_year;
                }
            }
        }

        //Вытаскиваем обработанные Стенфордом последовательности по seq_id по условию пройденного контроля качества
        $patientIdToSeqIdYearExist = array_keys($seqIdToPatientIdYearExist);
        $dataStanfordYearExist= PatientSequencesApi::analysisDataFromId($patientIdToSeqIdYearExist);
        $trendDataArray = [];

        //Если есть мутации в TDR, то есть флаг = 1, суммируем, если нет, то суммируем общее кол-во
        foreach ($dataStanfordYearExist as $dataStanfordSeq) {
            if($dataStanfordSeq->TDR_flag != 1) {
                if($trendDataArray[$totalPatientsTrend[$dataStanfordSeq->patient_id]]) {
                    $trendDataArray[$totalPatientsTrend[$dataStanfordSeq->patient_id]][0] += 1;
                } else {
                    $trendDataArray[$totalPatientsTrend[$dataStanfordSeq->patient_id]] = [0, 0];
                }
            } else {
                if($trendDataArray[$totalPatientsTrend[$dataStanfordSeq->patient_id]]) {
                    $trendDataArray[$totalPatientsTrend[$dataStanfordSeq->patient_id]][1] += 1;
                } else {
                    $trendDataArray[$totalPatientsTrend[$dataStanfordSeq->patient_id]] = [0, 0];
                }
            }
        }
        ksort($trendDataArray);

        //Собираем итоговый ассоциативный массив вида hiv_blot_year => percent
        $finalTrendArray = [];
        foreach ($trendDataArray as $year => $patientDataTrend) {
            if ($patientDataTrend[0] && $patientDataTrend[0] !=0) {
                $finalTrendArray[$year] = (int)round($patientDataTrend[1] * 100/$patientDataTrend[0]);
            } else {
                $finalTrendArray[$year] = 0;
            }
        }
        $trendYears = array_keys($finalTrendArray);
        $trendValues = array_values($finalTrendArray);

        //Итоговый массив
        $infoToJson = [
            "mainNumbers" => [
                "countPatient" => $count,
                "noArvt" => $noArvt,
                "stanfordSeq" => $stanfordSeq,
                "arvtPercent" => $arvtPersent,
                "federalRegionArray" => $federalRegionArray,
                "centerCount" => $centerCount,
                "labelPrevalence" => $labelPrevalence,
                "prevalenceLow" => $dataPrevalenceLow,
                "prevalenceMedium" => $dataPrevalenceMedium,
                "prevalenceHigh" => $dataPrevalenceHigh,
                "counterSeq" => $counterSeq,
                'mutationsNames' => $mutationsNames,
                'mutationsValues' => $mutationsValues,
                'trendYears' => $trendYears,
                'trendValues' => $trendValues
            ]
        ];

        //return $this->render('numbers');
        return json_encode($infoToJson);
    }
}