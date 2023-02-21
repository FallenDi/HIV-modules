<?php

namespace app\controllers;

use app\models\ExcelUpload;
use app\models\ReportApi;
use app\models\User as pUser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\httpclient\Client;
use app\models\Patient;
use app\models\PatientSequence;
use Yii;
use app\components\CountAndStat;
use app\models\PatientSequencesApi;
use app\components\Common;
use app\models\User;
use yii\web\UploadedFile;

class ApiController extends _Controller
{
    const STATUS_VALID = 'valid';
    const STATUS_INVALID = 'invalid';
    const STATUS_EMPTY = 'empty';

    public function actionResistApi()
    {
        $success = [];
        $failed = [];
        $client = new Client();
        $resist_host = Common::hostSelect();

        @$response = $client->createRequest()
            ->setFormat(Client::FORMAT_JSON)
            ->setMethod('GET')
            ->setUrl($resist_host . '/api/nbr-export?k=' . Yii::$app->params['nbr_api_key'])
            ->send();

        // проверяем доступен ли удалённый хост
        $url_is_up = Common::url_test($resist_host);

        if ($url_is_up && $response->isOk) {
            $data = isset($response->data) ? $response->data : null;
            $status = ArrayHelper::getValue($data, 'status');

            if ($status == self::STATUS_VALID) {
                $data_arrays = ArrayHelper::getValue($data, 'json_array');

                foreach ($data_arrays as $data_array) {
                    $data_array = json_decode($data_array, true);
                    $username = ArrayHelper::getValue($data_array, 'username');
                    $model_user = User::userGetByUsername($username);
                    $model_user_profile = null;

                    if ($model_user) {
                        /* @var $model_user_profile \app\models\UserProfile */
                        $model_user_profile = $model_user->profile;
                    }

                    // если такой пациент существует, получаем модель, иначе создаём новую
                    $user_id = $model_user ? $model_user->id : 1;
                    $card_number = ArrayHelper::getValue($data_array, 'card_number');
                    $model_patient = $user_id ? Patient::modelGetByCardNumber($card_number, $user_id) : null;
                    $error = false;

                    if (!$model_patient) {
                        $model_patient = new Patient();
                        $model_patient->card_number = $card_number;
                        $model_patient->gender = ArrayHelper::getValue($data_array, 'gender');
                        $model_patient->birthday = ArrayHelper::getValue($data_array, 'birthday');
                        $model_patient->user_id = $user_id;
                        $model_patient->center_id = $model_user_profile ? $model_user_profile->organization_id : null;

                        if (!$model_patient->save()) {
                            $error = true;
                            Yii::error($model_patient->errors);
                        }
                    }

                    if ($model_patient && !$error) {
                        $model_sequence = new PatientSequence();
                        $date = ArrayHelper::getValue($data_array, 'material_date');

                        if ($date) {
                            $date_split = $date ? explode('-', $date) : null;
                            $model_sequence->date_day = $date_split[2];
                            $model_sequence->date_month = $date_split[1];
                            $model_sequence->date_year = $date_split[0];
                        }
                        $model_sequence->patient_id = $model_patient->id;
                        $model_sequence->seq = ArrayHelper::getValue($data_array, 'sequence');
                        $model_sequence->type = ArrayHelper::getValue($data_array, 'seq_type');

                        if (!$model_sequence->save()) Yii::error($model_sequence->errors);
                    }
                }
            }

            $i = 1;

            foreach ($response->data as $jsonData) {
                $modelPatient = new Patient();
                $modelSequence = new PatientSequence();

                //Распаковка данных из json
                $jsonDecode = json_decode($jsonData, true);

                //TODO Транзакции
                //Запись в таблицу пациентов
                $modelPatient->card_number = 'resist' . '-' . $i;
                $modelPatient->user_id = Yii::$app->user->id;
                $modelPatient->gender = $jsonDecode['sex'];
                $modelPatient->birthday = $jsonDecode['bday'];
                $modelPatient->created_at = time();
                $modelPatient->modify_at = time();

                if ($modelPatient->validate()) {
                    //$modelPatient->save();

                    //после сохранения получаем id пациента
                    $patientId = Patient::idGet('resist' . '-' . $i);

                    //Запись в таблицу сиквенсов
                    $modelSequence->patient_id = $patientId;
                    $modelSequence->date_day = CountAndStat::strToDate($jsonDecode['date'], 'd');
                    $modelSequence->date_month = CountAndStat::strToDate($jsonDecode['date'], 'm');
                    $modelSequence->date_year = CountAndStat::strToDate($jsonDecode['date'], 'Y');
                    $modelSequence->seq = $jsonDecode['seq'];
                    $modelSequence->type = $jsonDecode['seq_type'];

                    if ($modelSequence->validate()) {
                        //$modelSequence->save();
                        $success[] = $jsonData['id'];
                    } else {
                        //Транзакция пред записи пациента
                    }
                } else {
                    Yii::error($modelPatient->errors);
                    $failed[] = [$jsonData['id']];
                }
                $i++;
            }

            /*$responseOk = $client->createRequest()
                ->setFormat(Client::FORMAT_JSON)
                ->setMethod('PUT')
                ->setUrl($baseUrl . 'update?key=' . Yii::$app->params['secret_resist_api_key'])
                ->setData(json_encode($success))
                ->send();
            if ($responseOk->isOk) {
                Yii::warning('OK');
            }*/
        }

        return $this->render('resist-api');
    }

    //Экшн получает из базы последовательности и отправляет их в стенфорд на обработку
    //После обработки пишет очищенные данные в таблицу api_stanford_sequences
    public function actionStanfordapi()
    {
        //Выборка чисто пациентов из файла
        $ruhivPatientId = Patient::ruhivPatientId();
        $modelSequencesPro = PatientSequence::stanfordApiSeqProRev(1, $ruhivPatientId);
        $modelSequencesInt = PatientSequence::stanfordApiSeqProRev(3, $ruhivPatientId);
        $sequencesIntArray = [];
        $sequencesProArray = [];
        $idToPatientId = [];
        $errorLog = [];

        foreach ($modelSequencesPro as $modelSequencePro) {
            $idToPatientId[$modelSequencePro->id] = $modelSequencePro->patient_id;
            $sequencesProArray[] = ["header" => $modelSequencePro->id, "sequence" => $modelSequencePro->seq];
        }

        foreach ($modelSequencesInt as $modelSequenceInt) {
            $idToPatientId[$modelSequenceInt->id] = $modelSequenceInt->patient_id;
            $sequencesIntArray[] = ["header" => $modelSequenceInt->id, "sequence" => $modelSequenceInt->seq];
        }

        if ($modelSequencesPro) {
            $totalProSequence = count($sequencesProArray);
            $stepQuery = 100;
            $startPoint = 0;

            while ($startPoint < $totalProSequence) {
                if ($totalProSequence - $startPoint < 100) {
                    $stepQuery = $totalProSequence - $startPoint;
                }
                $query = [
                    'query' => 'query ($sequences: [UnalignedSequenceInput]!) { 
                    viewer { 
                        sequenceAnalysis(sequences: $sequences) {
                            # Begin of sequenceAnalysis fragment
                              inputSequence {
                                header,
                                sequence,
                                MD5,
                              },
                              validationResults {
                                level,
                                message
                              },
                              alignedGeneSequences {
                                gene {
                                    name,
                                },
                                firstAA,
                                lastAA,
                                prettyPairwise {
                                    alignedNAsLine,
                                },
                                APOBEC: mutations(filterOptions: [APOBEC]) {
                                    text
                                },
                                SDRM: mutations(filterOptions: [SDRM]) {
                                    text
                                },
                              },
                              mutations {
                                isInsertion,
                                isDeletion,
                                isApobecMutation,
                                isUnusual,
                                hasStop,
                                isAmbiguous,
                              }
                              frameShifts {
                                  text
                              },
                              bestMatchingSubtype { display },
                              drugResistance {
                                drugScores {
                                  drug { displayAbbr },
                                  level,
                                },
                                mutationsByTypes {
                                  mutationType,
                                  mutations {
                                    text,
                                  }
                                }
                              }
                            # End of sequenceAnalysis fragment
                        }
                    } 
                }',
                    'variables' => ['sequences' => array_slice($sequencesProArray, $startPoint, $stepQuery)]
                ];

                $client = new Client();
                $response = $client->createRequest()
                    ->setFormat(Client::FORMAT_JSON)
                    ->setMethod('POST')
                    ->setUrl('https://hivdb.stanford.edu/graphql')
                    ->setData($query)
                    ->send();

                if ($response->isOk) {
                    //Точка входа к данным минуя справочную информацию
                    $entryPoint = $response->data['data']['viewer']['sequenceAnalysis'];

                    foreach ($entryPoint as $dot) {
                        //Доступ к протеазе и ревертазе
                        $alignedGeneSequences = $dot['alignedGeneSequences'];
                        $firstSecondAA = [];
                        $apobecMutations = [];
                        $prettyPairwise = [];
                        $sdrm = [];

                        foreach ($alignedGeneSequences as $data) {
                            //Первые 2 элемента протеаза, потом ревертаза - всегда 4 эл-та
                            $firstSecondAA[] = $data['firstAA'];
                            $firstSecondAA[] = $data['lastAA'];
                            $apobecMutations = array_merge($apobecMutations, $data['APOBEC']);
                            $sdrm = array_merge($sdrm, $data['SDRM']);
                            $prettyPairwise = array_merge($prettyPairwise, $data['prettyPairwise']['alignedNAsLine']);
                        }

                        /*$trimSequence = implode('', $prettyPairwise);
                        $BDHVN = false;
                        foreach (Yii::$app->params['BDHVN'] as $letter) {
                            if (stripos($letter, $trimSequence)) {
                                $BDHVN = true;
                            }
                        }*/

                        $isInsertion = 0;
                        $isDeletion = 0;
                        $isApobecMutation = 0;
                        $isUnusual = 0;
                        $hasStop = 0;
                        $isAmbiguous = 0;
                        $frameShifts = $dot['frameShifts'];

                        //Если что то из ошибок существует то активируем еденицей
                        foreach ($dot['mutations'] as $mutation) {
                            if ($mutation['isInsertion']) $isInsertion += 1;
                            if ($mutation['isDeletion']) $isDeletion += 1;
                            if ($mutation['isApobecMutation']) $isApobecMutation += 1;
                            if ($mutation['isUnusual']) $isUnusual += 1;
                            if ($mutation['hasStop']) $hasStop += 1;
                            if ($mutation['isAmbiguous']) $isAmbiguous += 1;
                        }

                        $apobecFlag = false;
                        if ($apobecMutations) {
                            foreach ($apobecMutations as $apobecMutation) {
                                if (in_array($apobecMutation, Yii::$app->params['apobecMutations'])) {
                                    $apobecFlag = true;
                                }
                            }
                        }

                        $modelSequenceApi = new PatientSequencesApi();
                        $modelSequence = PatientSequence::find()->where(['id' => (int)$dot['inputSequence']['header']])->one();

                        //Номер карты
                        $patientId = $idToPatientId[(int)$dot['inputSequence']['header']];
                        $modelSequenceApi->patient_seq_id = (int)$dot['inputSequence']['header'];
                        $modelSequenceApi->patient_id = $patientId;
                        $modelPatient = Patient::findById($patientId);
                        $cardNumber = $modelPatient->card_number;

                        if ($firstSecondAA[0] > 10 || $firstSecondAA[1] < 93 || $firstSecondAA[2] > 41 || $firstSecondAA[3] < 238 ||
                        $isInsertion > 1 || $isDeletion || $isApobecMutation > 3 || $apobecFlag || $isUnusual > 3 || $hasStop || $frameShifts ||
                            $isAmbiguous > 2) {

                            $qcReason = [];

                            if ($firstSecondAA[0] > 10) $qcReason[] = 1;
                            if ($firstSecondAA[1] < 93) $qcReason[] = 1;
                            if ($firstSecondAA[2] > 41) $qcReason[] = 1;
                            if ($firstSecondAA[3] < 238) $qcReason[] = 1;
                            if ($isInsertion > 1) $qcReason[] = 5;
                            if ($isDeletion) $qcReason[] = 6;
                            if ($isUnusual > 3) $qcReason[] = 7;
                            if ($isApobecMutation) $qcReason[] = 8;
                            if ($hasStop) $qcReason[] = 9;
                            if ($frameShifts) $qcReason[] = 10;
                            if ($apobecFlag) $qcReason[] = 11;
                            if ($isAmbiguous > 2) $qcReason[] = 12;

                            $modelSequenceApi->qc = 0;
                            $modelSequenceApi->qc_reasons = implode(':', $qcReason);

                            if (!$modelSequenceApi->save()) {
                                Yii::error($modelSequenceApi->errors);
                            }
                        } else {
                            $modelSequenceApi->qc = 1;
                            //Субтип
                            $bestMatchingSubtypeArray = explode(' ', $dot['bestMatchingSubtype']['display']);
                            $subtype = $bestMatchingSubtypeArray[0];

                            //Массивы резистентности по ЛУ и Мутациям
                            $resistanceStanford = $dot['drugResistance'];

                            //0 - PRO, 1 - REV
                            //Резистентность
                            $proResistanceStanford = $resistanceStanford[0]['drugScores'];
                            $revResistanceStanford = $resistanceStanford[1]['drugScores'];
                            $proDrugResistance = CountAndStat::drugResistance($proResistanceStanford);
                            $revDrugResistance = CountAndStat::drugResistance($revResistanceStanford);

                            //Мутации
                            $proMutationStanford = $resistanceStanford[0]['mutationsByTypes'];
                            $revMutationsStanford = $resistanceStanford[1]['mutationsByTypes'];
                            $proMutations = CountAndStat::mutationsListApi($proMutationStanford);
                            $revMutations = CountAndStat::mutationsListApi($revMutationsStanford);

                            //Соединяем в строку все мутации PRO
                            $stringPro = $proMutations['Major'] . ':' . $proMutations['Accessory'] . ':' . $proMutations['Other'];
                            $stringArrayPro = explode(':', $stringPro);

                            //Соединяем в строку все мутации REV
                            $stringRev = $revMutations['NRTI'] . ':' . $revMutations['NNRTI'] . ':' . $revMutations['Other'];
                            $stringArrayRev = explode(':', $stringRev);

                            $stringPI = CountAndStat::compareMutations($stringArrayPro, 'PI');
                            $stringNNRTI = CountAndStat::compareMutations($stringArrayRev, 'NNRTI');
                            $stringNRTI = CountAndStat::compareMutations($stringArrayRev, 'NRTI');

                            if ($modelPatient) {
                                if ($stringPI || $stringNNRTI || $stringNRTI) {
                                    $flagTDR = 1;
                                    $modelPatient->TDR_flag = $flagTDR;
                                } else {
                                    $flagTDR = 0;
                                    $modelPatient->TDR_flag = $flagTDR;
                                }

                                if (!$modelPatient->save()) {
                                    Yii::error($modelPatient->errors);
                                }
                            }

                            //Запись в базу
                            $modelSequenceApi->subtype = $subtype;
                            $modelSequenceApi->Pro_PI_Major = $proMutations['Major'];
                            $modelSequenceApi->Pro_Pr_Accessory = $proMutations['Accessory'];
                            $modelSequenceApi->Pro_Pr_Other = $proMutations['Other'];
                            $modelSequenceApi->Rev_NRTI = $revMutations['NRTI'];
                            $modelSequenceApi->Rev_NNRTI = $revMutations['NNRTI'];
                            $modelSequenceApi->Rev_Rt_Other = $revMutations['Other'];
                            $modelSequenceApi->TDR_PI = $stringPI;
                            $modelSequenceApi->TDR_NRTI = $stringNRTI;
                            $modelSequenceApi->TDR_NNRTI = $stringNNRTI;
                            $modelSequenceApi->TDR_flag = $flagTDR;
                            $modelSequenceApi->ATV = $proDrugResistance['ATV/r'];
                            $modelSequenceApi->DRV = $proDrugResistance['DRV/r'];
                            $modelSequenceApi->FPV = $proDrugResistance['FPV/r'];
                            $modelSequenceApi->IDV = $proDrugResistance['IDV/r'];
                            $modelSequenceApi->LPV = $proDrugResistance['LPV/r'];
                            $modelSequenceApi->NFV = $proDrugResistance['NFV'];
                            $modelSequenceApi->SQV = $proDrugResistance['SQV/r'];
                            $modelSequenceApi->TPV = $proDrugResistance['TPV/r'];
                            $modelSequenceApi->ABC = $revDrugResistance['ABC'];
                            $modelSequenceApi->AZT = $revDrugResistance['AZT'];
                            $modelSequenceApi->D4T = $revDrugResistance['D4T'];
                            $modelSequenceApi->DDI = $revDrugResistance['DDI'];
                            $modelSequenceApi->FTC = $revDrugResistance['FTC'];
                            $modelSequenceApi->three_TC = $revDrugResistance['3TC'];
                            $modelSequenceApi->TDF = $revDrugResistance['TDF'];
                            $modelSequenceApi->DOR = $revDrugResistance['DOR'];
                            $modelSequenceApi->EFV = $revDrugResistance['EFV'];
                            $modelSequenceApi->ETR = $revDrugResistance['ETR'];
                            $modelSequenceApi->NVP = $revDrugResistance['NVP'];
                            $modelSequenceApi->RPV = $revDrugResistance['RPV'];
                            // тут флаг что посл уже прогнана в стенфорд но писать в сиквенсы

                            if (!$modelSequenceApi->save()) {
                                Yii::error($modelSequenceApi->errors);
                            }
                        }

                        $modelSequence->stanford_api = 1;
                        if (!$modelSequence->save()) {
                            Yii::error($modelSequence->errors);
                            $errorLog[$cardNumber] = $modelSequence->errors;
                        }
                    }
                } else {
                    Yii::error('error');
                }

                $startPoint += 100;
                sleep(10);
            }
        }

        //Интеграза
        if ($modelSequencesInt) {
            $totalIntSequence = count($sequencesIntArray);
            $stepQuery = 100;
            $startPoint = 0;

            while ($startPoint < $totalIntSequence) {
                if ($totalIntSequence - $startPoint < 100) {
                    $stepQuery = $totalIntSequence - $startPoint;
                }
                $query = [
                    'query' => 'query ($sequences: [UnalignedSequenceInput]!) { 
                    viewer { 
                        sequenceAnalysis(sequences: $sequences) {
                            # Begin of sequenceAnalysis fragment
                              inputSequence {
                                header,
                                sequence,
                                MD5,
                              },
                              validationResults {
                                level,
                                message
                              },
                              alignedGeneSequences {
                                gene {
                                    name,
                                },
                                firstAA,
                                lastAA,
                                prettyPairwise {
                                    alignedNAsLine,
                                },
                                APOBEC: mutations(filterOptions: [APOBEC]) {
                                    text
                                },
                                SDRM: mutations(filterOptions: [SDRM]) {
                                    text
                                },
                              },
                              mutations {
                                isInsertion,
                                isDeletion,
                                isApobecMutation,
                                isUnusual,
                                hasStop,
                                isAmbiguous,
                              }
                              frameShifts {
                                  text
                              },
                              bestMatchingSubtype { display },
                              drugResistance {
                                drugScores {
                                  drug { displayAbbr },
                                  level,
                                },
                                mutationsByTypes {
                                  mutationType,
                                  mutations {
                                    text,
                                  }
                                }
                              }
                            # End of sequenceAnalysis fragment
                        }
                    } 
                }',
                    'variables' => ['sequences' => array_slice($sequencesIntArray, $startPoint, $stepQuery)]
                ];

                $client = new Client();
                $response = $client->createRequest()
                    ->setFormat(Client::FORMAT_JSON)
                    ->setMethod('POST')
                    ->setUrl('https://hivdb.stanford.edu/graphql')
                    ->setData($query)
                    ->send();

                if ($response->isOk) {
                    //Точка входа к данным минуя справочную информацию
                    $entryPoint = $response->data['data']['viewer']['sequenceAnalysis'];
                    foreach ($entryPoint as $dot) {
                        //Доступ к интегразе
                        $alignedGeneSequences = $dot['alignedGeneSequences'];
                        $firstSecondAA = [];
                        $apobecMutations = [];
                        $prettyPairwise = [];
                        $sdrm = [];

                        foreach ($alignedGeneSequences as $data) {
                            $firstSecondAA[] = $data['firstAA'];
                            $firstSecondAA[] = $data['lastAA'];
                            $apobecMutations = array_merge($apobecMutations, $data['APOBEC']);
                            $sdrm = array_merge($sdrm, $data['SDRM']);
                            $prettyPairwise = array_merge($prettyPairwise, $data['prettyPairwise']['alignedNAsLine']);
                        }

                        /*$trimSequence = implode('', $prettyPairwise);
                        $BDHVN = false;
                        foreach (Yii::$app->params['BDHVN'] as $letter) {
                            if (stripos($letter, $trimSequence)) {
                                $BDHVN = true;
                            }
                        }*/

                        $isInsertion = 0;
                        $isDeletion = 0;
                        $isApobecMutation = 0;
                        $isUnusual = 0;
                        $hasStop = 0;
                        $isAmbiguous = 0;
                        $frameShifts = $dot['frameShifts'];

                        //Если что то из ошибок существует то активируем еденицей
                        foreach ($dot['mutations'] as $mutation) {
                            if ($mutation['isInsertion']) $isInsertion += 1;
                            if ($mutation['isDeletion']) $isDeletion += 1;
                            if ($mutation['isApobecMutation']) $isApobecMutation += 1;
                            if ($mutation['isUnusual']) $isUnusual += 1;
                            if ($mutation['hasStop']) $hasStop += 1;
                            if ($mutation['isAmbiguous']) $isAmbiguous += 1;
                        }

                        $apobecFlag = false;
                        if ($apobecMutations) {
                            foreach ($apobecMutations as $apobecMutation) {
                                if (in_array($apobecMutation, Yii::$app->params['apobecMutations'])) {
                                    $apobecFlag = true;
                                }
                            }
                        }

                        $modelSequenceApi = new PatientSequencesApi();
                        $modelSequence = PatientSequence::find()->where(['id' => (int)$dot['inputSequence']['header']])->one();
                        //Номер карты
                        $patientId = $idToPatientId[(int)$dot['inputSequence']['header']];
                        $modelSequenceApi->patient_seq_id = (int)$dot['inputSequence']['header'];
                        $modelSequenceApi->patient_id = $patientId;
                        $modelPatient = Patient::findById($patientId);
                        $cardNumber = $modelPatient->card_number;

                        if ($firstSecondAA[0] > 51 || $firstSecondAA[1] < 263 ||
                            $isInsertion || $isDeletion || $isApobecMutation > 3 ||
                            $apobecFlag || $isUnusual > 3 || $hasStop || $frameShifts || $isAmbiguous > 2){
                            $qcReason = [];
                            if ($firstSecondAA[0] > 51) $qcReason[] = 13;
                            if ($firstSecondAA[1] < 263) $qcReason[] = 13;
                            if ($isInsertion) $qcReason[] = 5;
                            if ($isDeletion) $qcReason[] = 6;
                            if ($isUnusual > 3) $qcReason[] = 7;
                            if ($isApobecMutation) $qcReason[] = 8;
                            if ($hasStop) $qcReason[] = 9;
                            if ($frameShifts) $qcReason[] = 10;
                            if ($apobecFlag) $qcReason[] = 11;
                            if ($isAmbiguous > 2) $qcReason[] = 12;

                            $modelSequenceApi->qc = 0;
                            $modelSequenceApi->qc_reasons = implode(':', $qcReason);

                            if (!$modelSequenceApi->save()) {
                                Yii::error($modelSequenceApi->errors);
                            }

                        } else {
                            $modelSequenceApi->qc = 1;
                            //Субтип
                            $bestMatchingSubtypeArray = explode(' ', $dot['bestMatchingSubtype']['display']);
                            $subtype = $bestMatchingSubtypeArray[0];

                            //Массивы резистентности по ЛУ и Мутациям
                            $resistanceStanford = $dot['drugResistance'];

                            //0 - INT
                            //Резистентность
                            $intResistanceStanford = $resistanceStanford[0]['drugScores'];
                            $intDrugResistance = CountAndStat::drugResistance($intResistanceStanford);

                            //Мутации
                            $proMutationStanford = $resistanceStanford[0]['mutationsByTypes'];
                            $revMutationsStanford = $resistanceStanford[1]['mutationsByTypes'];

                            $proMutations = CountAndStat::mutationsListApi($proMutationStanford);
                            $revMutations = CountAndStat::mutationsListApi($revMutationsStanford);

                            //Соединяем в строку все мутации ITN
                            $stringPro = $proMutations['Major'] . ':' . $proMutations['Accessory'] . ':' . $proMutations['Other'];
                            $stringArrayPro = explode(':', $stringPro);

                            //Соединяем в строку все мутации REV
                            $stringRev = $revMutations['NRTI'] . ':' . $revMutations['NNRTI'] . ':' . $revMutations['Other'];
                            $stringArrayRev = explode(':', $stringRev);
                            $stringPI = CountAndStat::compareMutations($stringArrayPro, 'PI');
                            $stringNNRTI = CountAndStat::compareMutations($stringArrayRev, 'NNRTI');
                            $stringNRTI = CountAndStat::compareMutations($stringArrayRev, 'NRTI');

                            if ($modelPatient) {
                                if ($stringPI || $stringNNRTI || $stringNRTI) {
                                    $flagTDR = 1;
                                    $modelPatient->TDR_flag = $flagTDR;
                                } else {
                                    $flagTDR = 0;
                                    $modelPatient->TDR_flag = $flagTDR;
                                }

                                if (!$modelPatient->save()) {
                                    Yii::error($modelPatient->errors);
                                }
                            }

                            //Запись в базу
                            $modelSequenceApi->subtype = $subtype;
                            $modelSequenceApi->qc = 1;
                            $modelSequenceApi->Pro_PI_Major = $proMutations['Major'];
                            $modelSequenceApi->Pro_Pr_Accessory = $proMutations['Accessory'];
                            $modelSequenceApi->Pro_Pr_Other = $proMutations['Other'];
                            $modelSequenceApi->Rev_NRTI = $revMutations['NRTI'];
                            $modelSequenceApi->Rev_NNRTI = $revMutations['NNRTI'];
                            $modelSequenceApi->Rev_Rt_Other = $revMutations['Other'];
                            $modelSequenceApi->TDR_PI = $stringPI;
                            $modelSequenceApi->TDR_NRTI = $stringNRTI;
                            $modelSequenceApi->TDR_NNRTI = $stringNNRTI;
                            $modelSequenceApi->TDR_flag = $flagTDR;
                            $modelSequenceApi->RAL = $intDrugResistance['RAL'];
                            $modelSequenceApi->EVG = $intDrugResistance['EVG'];
                            $modelSequenceApi->DTG = $intDrugResistance['DTG'];
                            $modelSequenceApi->BIC = $intDrugResistance['BIC'];
                            $modelSequenceApi->CAB = $intDrugResistance['CAB'];
                            //for tag
                            // тут флаг что посл уже прогнана в стенфорд но писать в сиквенсы

                            if (!$modelSequenceApi->save()) {
                                Yii::error($modelSequenceApi->errors);
                            }
                        }

                        $modelSequence->stanford_api = 1;

                        if (!$modelSequence->save()) {
                            Yii::error($modelSequence->errors);
                            $errorLog[$cardNumber] = $modelSequence->errors;
                        }
                    }
                } else {
                    Yii::error('error');
                }

                $startPoint += 100;
                sleep(10);
            }
        }

        $modelSequenceV3 = PatientSequence::stanfordApiSeqProRev(2, $ruhivPatientId);

        if ($modelSequenceV3) {
            // формируем массив запроса
            foreach ($modelSequenceV3 as $consensus) {
                $modelSequenceApi = new PatientSequencesApi();
                $url = 'https://coreceptor.geno2pheno.org';
                $modelSequence = PatientSequence::find()->where(['id' => $consensus->id, 'type' => 2])->one();
                $params = [ // в http://localhost/post.php это будет $_POST['param1'] == '123'
                    'cMethod' => '1', // 2. Choose Prediction Method
                    'slev_cxcr4' => '9', // 3. Significance Levels
                    'v3seq' => $consensus->seq, // 4. Sequence containing the V3 region of gp120
                    'action' => '1', // 6. Action
                    'go' => '1', // submit
                ];
                $result = @file_get_contents($url, false, stream_context_create([
                    'http' => [
                        'method'  => 'POST',
                        'header'  => 'Content-type: application/x-www-form-urlencoded',
                        'content' => http_build_query($params)
                    ]
                ]));

                $receptor = 0;
                if ($result) {
                    // определяем тип вируса
                    preg_match('/(00ff00)|(ff0000)/', $result, $matches);
                    if ($matches[0] == '00ff00') {
                        $receptor = 1;
                    } elseif ($matches[0] == 'ff0000') {
                        $receptor = 2;
                    }

                    $modelSequenceApi->patient_id = $consensus->patient_id;
                    $modelSequenceApi->qc = 1;
                    $modelSequenceApi->patient_seq_id = $consensus->id;
                    $modelSequenceApi->receptor = $receptor;

                    // определяем FPR
                    preg_match_all('|[<center>]\n(\d+.?\d?)%|U', $result, $matches_fpr);

                    $modelSequenceApi->fpr = $matches_fpr[1][0];

                    if (!$modelSequenceApi->save()) {
                        Yii::error($modelSequenceApi->errors);
                    }

                    $modelSequence->stanford_api = 1;

                    if (!$modelSequence->save()) {
                        Yii::error($modelSequence->errors);
                        $errorLog[$cardNumber] = $modelSequence->errors;
                    }
                    sleep(5);
                } else {
                    echo 'Не удалось получить ответ от "api".';
                }
            }
        }

        return $this->render('stanfordapi', [
            'errorLog' => $errorLog,
        ]);
    }

    public function actionContamination()
    {
        $postData = Yii::$app->request->post();
        $patientSequence = new PatientSequence();

        if ($postData) {
            $compareSeqCounter = 0;
            $seqType = ArrayHelper::getValue($postData, 'PatientSequence.type');
            $fromDate = strtotime($postData['from_date']);
            $toDate = strtotime($postData['to_date']) + 86400;
            $resultArray = [];
            $comparedArray = [];
            $queryData = Patient::contaminationCheck($fromDate, $toDate);
            foreach ($queryData as $patientData) {
                foreach ($patientData->sequence as $seqData) {
                    $compareSeqCounter += 1;
                    if ($seqData->type == $seqType) {
                        $comparedArray[] = [
                            $patientData->card_number,
                            $patientData->created_at,
                            $seqData->seq,
                        ];
                    }
                }
            }

            $runtimePath = Yii::getAlias('@runtime') . '/ext_soft/blast_db/';
            if (!is_dir($runtimePath)) {
                FileHelper::createDirectory($runtimePath);
            }
            $time = time();
            $output_dir = $runtimePath . $time;
            $makeBlast = Yii::getAlias('@runtime') . '/ext_soft/ncbi-blast/bin/';

            if (!is_dir($output_dir)) {
                FileHelper::createDirectory($output_dir);
            }

            file_put_contents($output_dir . '/db.fas', '');
            file_put_contents($output_dir . '/seq.fas', '');
            $fasta_output = $output_dir . '/db.fas';
            $fasta_output_seq = $output_dir . '/seq.fas';
            $current = file_get_contents($fasta_output);

            //Запись в файл всех сиквенсов для создания базы данных
            //$counterSeq нужен для создания уникальных тайтлов, дубли в Бласте запрещены
            $counterSeq = 0;
            foreach ($comparedArray as $keyCompare => $mainData) {
                $name = $counterSeq . ':' . $mainData[0] . ':' . $mainData[1];
                $seq = $mainData[2];
                $current .= ">" . $name . "\n" . $seq . "\n";
                $counterSeq += 1;
            }
            //shell_exec('chmod -R 777 ' . $runtimePath);
            //shell_exec('chmod -R 777 ' . $output_dir);
            file_put_contents($fasta_output, $current);
            chdir($output_dir);
            //Создание базы для сравнения
            exec($makeBlast . 'makeblastdb -in db.fas -dbtype nucl -parse_seqids', $output, $retval);
            $counterResults = 1;
            foreach ($comparedArray as $dataAnalyzed) {

                //Создание записи которая будет проверятся в базе
                $name2 = $dataAnalyzed[0] . ' ' . $dataAnalyzed[1];
                $seq2 = $dataAnalyzed[2];
                $currentSeq = file_get_contents($fasta_output_seq);
                $currentSeq .= ">" . $name2 . "\n" . $seq2 . "\n";
                file_put_contents($fasta_output_seq, $currentSeq);

                // исполнение команды сравнения
                exec($makeBlast . 'blastn -query seq.fas -db db.fas -out result.json -outfmt 13 -dust no -parse_deflines', $output2, $retval2);

                $newFileName = 'result_' . $counterResults . '.json';

                $txt_file = file_get_contents($output_dir . '/' . $newFileName);
                $rows = explode("\n", $txt_file);
                array_shift($rows);

                //Парсер json в массив
                $jsonIterator = new RecursiveIteratorIterator(
                    new RecursiveArrayIterator(json_decode($txt_file, TRUE)),
                    RecursiveIteratorIterator::SELF_FIRST);

                $shipments = json_decode(file_get_contents($newFileName), true);

                $entryPointJson = $shipments['BlastOutput2']['report']['results']['search'];
                //Номер карты и дата создания последовательности которую сравниваем с базой
                $nameAnalyzedSeq = $entryPointJson['query_id'];
                $dateAnalyzedSeq = $entryPointJson['query_title'];

                foreach ($entryPointJson['hits'] as  $comparingData) {
                    $mismatchKey = [];
                    $analyzedLetters = [];
                    $comparedLetters = [];
                    if ($resultArray) {
                        foreach ($resultArray as $resultKey => $resultData) {
                            $findOut[] = $resultData['cardNumber1'];
                        }
                    }
                    $stringNameToArray = explode(':', $comparingData['description'][0]['id']);
                    $nameComparedSeq = $stringNameToArray[1];
                    $dateCompareSeq = $stringNameToArray[2];

                    if ($entryPointJson['search']['message'] != 'No hits found') {
                        //Проверка что расхождение меньше 98%
                        $needleParameters = $comparingData['hsps'][0];
                        $mismatchCounter = 0;
                        $matchesArray = str_split($needleParameters['midline']);
                        $sequenceLength = $needleParameters['align_len'];
                        foreach ($matchesArray as $key => $value) {
                            if ($value == ' ') {
                                $mismatchCounter += 1;
                                $mismatchKey[] = $key;
                            }
                        }
                        $absoluteMismatch = round((($sequenceLength / ($sequenceLength + $mismatchCounter))) * 100 , 2);

                        if ($absoluteMismatch > 98) {
                            //Анализируемая и сравниваемая последовательности
                            $analyzedSequence = str_split($needleParameters['qseq']);
                            $comparedSequence = str_split($needleParameters['hseq']);
                            $mismatchRules = Yii::$app->params['mismatches_rules'];
                            $classicLetters = ['A', 'T', 'G', 'C', '-'];

                            foreach ($mismatchKey as $needleKey) {
                                $analyzedLetters[] = $analyzedSequence[$needleKey];
                                $comparedLetters[] = $comparedSequence[$needleKey];
                            }

                            //Алгоритм проверки за бластом
                            $absoluteCont = 0;
                            $relativeCont = 0;
                            foreach ($analyzedLetters as $key => $letter) {
                                $classicAnalyzed = in_array($analyzedLetters[$key], $classicLetters);
                                $classicCompared = in_array($comparedLetters[$key], $classicLetters);
                                $isNotGap = $comparedLetters[$key] != '-';
                                $notClassicAnalyzed = !in_array($analyzedLetters[$key], $classicLetters);
                                $notClassicCompared = !in_array($comparedLetters[$key], $classicLetters);
                                if ($classicAnalyzed && $classicCompared && $isNotGap) {
                                    $absoluteCont += 1;
                                } elseif ($notClassicAnalyzed && $classicCompared && $isNotGap) {
                                    if (in_array($comparedLetters[$key], $mismatchRules[$analyzedLetters[$key]])) {
                                        $relativeCont += 1;
                                    } else {
                                        $absoluteCont += 1;
                                    }
                                } elseif ($classicAnalyzed && $notClassicCompared && $isNotGap) {
                                    if (in_array($analyzedLetters[$key], $mismatchRules[$comparedLetters[$key]])) {
                                        $relativeCont += 1;
                                    } else {
                                        $absoluteCont += 1;
                                    }
                                } elseif ($notClassicAnalyzed && $notClassicCompared && $isNotGap) {
                                    $checkAnalysed = $mismatchRules[$analyzedLetters[$key]];
                                    $checkCompared = $mismatchRules[$comparedLetters[$key]];
                                    if (array_intersect($checkAnalysed, $checkCompared)) {
                                        $relativeCont += 1;
                                    } else {
                                        $absoluteCont += 1;
                                    }
                                } else {
                                    if ($comparedLetters[$key] != '-') {
                                        $absoluteCont += 1;
                                    }
                                }
                            }
                            //Проверка что и после нашего алгоритма все еще выше 98%
                            $relativeMismatch = round(($sequenceLength / ($sequenceLength + $absoluteCont)) * 100, 2);

                            if ($relativeMismatch > 98) {
                                if ($nameAnalyzedSeq != $nameComparedSeq) {
                                    if ($resultArray) {
                                        if (!in_array($nameComparedSeq, array_values($findOut))) {
                                            $resultArray[] = [
                                                'cardNumber1' => $nameAnalyzedSeq,
                                                'cardNumber2' => $nameComparedSeq,
                                                'dateCreate1' => $dateAnalyzedSeq,
                                                'dateCreate2' => $dateCompareSeq,
                                                'analyzedSeq' => $analyzedSequence,
                                                'comparedSeq' => $comparedSequence,
                                                'length' => $sequenceLength,
                                                'absoluteMismatch' => $absoluteMismatch,
                                                'absMismatchNum' => $sequenceLength - $mismatchCounter,
                                                'relativeMismatch' => $relativeMismatch,
                                                'relMismatchNum' => $sequenceLength - $absoluteCont,
                                            ];
                                        }
                                    } else {
                                        $resultArray[] = [
                                            'cardNumber1' => $nameAnalyzedSeq,
                                            'cardNumber2' => $nameComparedSeq,
                                            'dateCreate1' => $dateAnalyzedSeq,
                                            'dateCreate2' => $dateCompareSeq,
                                            'analyzedSeq' => $analyzedSequence,
                                            'comparedSeq' => $comparedSequence,
                                            'length' => $sequenceLength,
                                            'absoluteMismatch' => $absoluteMismatch,
                                            'absMismatchNum' => $sequenceLength - $mismatchCounter,
                                            'relativeMismatch' => $relativeMismatch,
                                            'relMismatchNum' => $sequenceLength - $absoluteCont,
                                        ];
                                    }
                                }
                            }
                        }
                    } else {
                        Yii::warning('No hits found');
                    }
                }
                $counterResults ++;
            }
            FileHelper::removeDirectory($output_dir);
        }

        return $this->render('contamination', [
            'patientSequence' => $patientSequence,
            'resultArray' => $resultArray,
            'compareSeqCounter' => $compareSeqCounter
        ]);
    }

    public function actionCluster() {

        $modelUpload = new ExcelUpload();

        if ($modelUpload->load(Yii::$app->request->post())) {
            $userId = Yii::$app->user->id;
            $userProfile = pUser::userOrganizationId($userId);
            $centerName = $userProfile->profile->organization_id;
            $modelUpload->excelFile = UploadedFile::getInstances($modelUpload, 'excelFile');
            $folder = Yii::getAlias('@runtime/') . 'ext_soft/cluster/';
            $filenameHash = time();

            if (!is_dir($folder)) {
                FileHelper::createDirectory($folder);
            }

            if ($modelUpload->excelFile) {
                foreach ($modelUpload->excelFile as $file) {
                    $filename = $folder . Yii::$app->user->id . '-' . $centerName . '-' . $filenameHash . '-' . md5($file->name) . '.fas';
                    $name = Yii::$app->user->id . '-' . $centerName . '-' . $filenameHash . '-' . md5($file->name) . '.fas';
                    $file->saveAs($filename, false);
                    $session = Yii::$app->session;
                    $session->setFlash('correct-data', Yii::$app->params['excelOK']);
                }

                chdir($folder);
                $gd = 'gd' . Yii::$app->user->id . '-' . $centerName . '-' . $filenameHash . '-' . md5($file->name) . '.csv';
                //Создание базы для сравнения
                $output = shell_exec('tn93 -t 0.05 -o ' . $gd . ' ' . $name);

                //Yii::warning($retval);
                $output2 = shell_exec('hivnetworkcsv -i ' . $gd . ' -J -f plain -s ' . $name . ' -n report -O result.json');
                //Yii::warning($output2);
                $file = $folder . 'result.json';
                $json = json_decode(file_get_contents($file), true);

                foreach ($json['Edges']['sequences'] as $key => $seq) {
                    if($json['Edges']['length'][$key] < 0.05 && $json['Edges']['length'][$key] > 0.005) {
                        $finalArray[$key] = [count($seq), implode(',', $seq),  $json['Edges']['length'][$key]];
                    }
                }
                Yii::warning($finalArray);

                $folder = Yii::getAlias('@app/runtime/') . 'upload/';
                /*unlink($filename);
                unlink($gd);
                unlink($file);*/
                if (!is_dir($folder)) {
                    FileHelper::createDirectory($folder);
                }

                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $cellsCounter = 10;

                $staticData = [
                    'id' => 1,
                    'num_cluster' => 2,
                    'sequence' => 3,
                    'gd' => 4
                ];
                $counterRows = 10;
                $sheet->setCellValueByColumnAndRow($staticData['id'], 1, '#');
                $sheet->setCellValueByColumnAndRow($staticData['num_cluster'], 1, 'Количество сиквенсов в кластере');
                $sheet->setCellValueByColumnAndRow($staticData['sequence'], 1, 'Номера сиквенсов');
                $sheet->setCellValueByColumnAndRow($staticData['gd'], 1, 'Генетическая дистанция');

                foreach ($finalArray as $key => $dataArray) {
                    $sheet->setCellValueByColumnAndRow($staticData['id'], $counterRows, $key+1);
                    //Номер карты
                    $sheet->setCellValueByColumnAndRow($staticData['num_cluster'], $counterRows, $dataArray[0]);
                    //Пол
                    $sheet->setCellValueByColumnAndRow($staticData['sequence'], $counterRows, $dataArray[1]);
                    //Дата рождения
                    $sheet->setCellValueByColumnAndRow($staticData['gd'], $counterRows, $dataArray[2]);
                    $counterRows++;
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

                //return $this->redirect('cluster');
                return $this->render('cluster', [
                    'modelUpload' => $modelUpload
                ]);

            } else {
                $session = Yii::$app->session;
                $session->setFlash('incorrect-data-cluster', Yii::$app->params['errorClusterFile']);
                Yii::error($modelUpload->errors);
            }
        }

        return $this->render('cluster', [
            'modelUpload' => $modelUpload
        ]);
    }

}