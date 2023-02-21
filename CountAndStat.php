<?php


namespace app\components;
use yii\helpers\ArrayHelper;
use Yii;


class CountAndStat
{
    //Функция собирает масиив лекарств для графика препараты АРВТ
    //Принимает полный массив данных, массив необходимых столбцов и кол-во элементов столбца
    public static function getMassiveDrugs($dateArray, $columnMassive, $arrayLength)
    {
        //Собираем начальный пустой массив (шаблон)
        $drugsArray = [
            'ИП' => ['ATV' => [], 'DRV' => [], 'LPV' => []],
            'НИОТ' => ['ABC' => [], 'AZT' => [], 'D4T' => [], 'DDI' => [], 'FTC' => [], '3TC' => [], 'TDF' => []],
            'ННИОТ' => ['EFV' => [], 'NVP' => []]
        ];

        //Собираем общий массив
        $countDrugs = $drugsArray;
        foreach ($columnMassive as $keyDrug => $drug) {
            foreach ($dateArray as $key => $array) {
                if ($array[$drug] !== '1' && $array[$drug] !== '2' && $array[$drug]) {
                    if ($drug == 'ATV' || $drug == 'DRV' || $drug == 'LPV') {
                        array_push($drugsArray['ИП'][$drug], $array[$drug]);
                    } elseif ($drug == 'ABC' || $drug == 'AZT' || $drug == 'D4T' || $drug == 'DDI' || $drug == 'FTC' || $drug == 'TDF') {
                        array_push($drugsArray['НИОТ'][$drug], $array[$drug]);
                    } elseif ($drug == 'three_TC') {
                        array_push($drugsArray['НИОТ']['3TC'], $array[$drug]);
                    } else {
                        array_push($drugsArray['ННИОТ'][$drug], $array[$drug]);
                    }
                }
            }
        }

        //Подсчитыаем кол-во значений каждого типа
        foreach ($drugsArray as $group => $drugArray) {
            foreach ($drugArray as $drug => $valueArray) {
                $countDrugs[$group][$drug] = array_count_values($valueArray);
            }
        }

        //Высчитываем процент от общего числа пациентов
        foreach ($countDrugs as $group => $drugArray) {
            foreach ($drugArray as $drug => $valueArray) {
                foreach ($valueArray as $key => $value) {
                    $drugPercent = round($value * 100 / $arrayLength, 2);
                    if ($drugPercent > 0 && $drugPercent < 0.1) {
                        $countDrugs[$group][$drug][$key] = 0.1;
                    } else {
                        $countDrugs[$group][$drug][$key] = $drugPercent;
                    }
                }
            }
        }

        return $countDrugs;
    }

    //Функция формирует массив для графика Интегразы
    public static function getDrugsInt($dataArray, $columnMassive, $arrayLength)
    {
        //Собираем начальный пустой массив (шаблон)
        $drugsArray = [
            'RAL' => [],
            'EVG' => [],
            'DTG' => [],
            'BIC' => []
        ];

        foreach ($columnMassive as $keyDrug => $drug) {
            foreach ($dataArray as $key => $array) {
                if ($array[$drug] !== '1' && $array[$drug] !== '2' && $array[$drug]) {
                    array_push($drugsArray[$drug], $array[$drug]);
                }
            }
        }

        $countDrugs = $drugsArray;
        //Подсчитыаем кол-во значений каждого типа
        foreach ($drugsArray as $drug => $valueArray) {
            $countDrugs[$drug] = array_count_values($valueArray);
        }

        foreach ($countDrugs as $drug => $drugArray) {
            foreach ($drugArray as $key => $value) {
                $drugPercent = round($value * 100 / $arrayLength, 2);
                if ($drugPercent > 0 && $drugPercent < 0.1) {
                    $countDrugs[$drug][$key] = 0.1;
                } else {
                    $countDrugs[$drug][$key] = $drugPercent;
                }

            }
        }


        return $countDrugs;
    }

    //Функция формирует массивы для таблиц sex infection year and federal region
    //Если задан $famous то добавляем в массив данные для подписи под таблицей, если она не нужна то false
    public static function tableArray($dataArray, $famous = false)
    {
        $uniqueparam = array_count_values($dataArray);
        $percentParamsArray = [];
        $noData = [];

        //Проценты от общего
        foreach ($uniqueparam as $param => $number) {
            if ($param !== 'нет данных') {
                $percentParamsArray[$param] = [$number, round(($number * 100 / count($dataArray)), 1) . '%'];
            } elseif ($param == 'нет данных') {
                $noData['нет данных'] = [$number, round(($number * 100 / count($dataArray)), 1) . '%'];
            }

        }

        $famousParams = count($dataArray) - $noData['нет данных'][0];

        if ($famous) {
            foreach ($percentParamsArray as $params => $value) {
                if ($params !== 'нет данных' && $params !== 'Всего') {
                    $percentParamsArray['famous ' . $params] = [round(($percentParamsArray[$params][0] * 100 / $famousParams), 1)];
                }
            }
        }
        ksort($percentParamsArray);
        $percentParamsArray['нет данных'] = [];
        $percentParamsArray['Всего'] = [];

        array_push($percentParamsArray['нет данных'], $noData['нет данных'][0]);
        array_push($percentParamsArray['нет данных'], $noData['нет данных'][1]);
        array_push($percentParamsArray['Всего'], count($dataArray));
        array_push($percentParamsArray['Всего'], '100%');

        return $percentParamsArray;
    }

    //Функция собирает массив для таблицы мутации по федеральным округам
    public static function regionSignature($subtypeRegion, $subtypeBody, $subtypeLength)
    {
        $percent = [];
        $finalRowRegion = [];
        $regionSubArray = [];
        $uniqueSubtypeRegion = [];
        $counterSubtypeRegion = 0;

        foreach ($subtypeRegion as $region => $subtypeArray) {
            $uniqueSubtypeRegion[$counterSubtypeRegion]['region'] = $region;
            $uniqueSubtypeRegion[$counterSubtypeRegion]['sumRegion'] = count($subtypeArray);
            $uniqueSubtypeRegion[$counterSubtypeRegion]['subtype'] = array_count_values($subtypeArray);
            foreach ($subtypeBody as $uniqueSub) {
                //Дописываем нулевые значения тем субтипам которых нет в массиве регионов, но есть в списке всех
                if (!in_array($uniqueSub, array_keys($uniqueSubtypeRegion[$counterSubtypeRegion]['subtype']))) {
                    $uniqueSubtypeRegion[$counterSubtypeRegion]['subtype'][$uniqueSub] = 0;
                }
            }
            ksort($uniqueSubtypeRegion[$counterSubtypeRegion]['subtype']);
            $counterSubtypeRegion += 1;
        }

        foreach ($uniqueSubtypeRegion as $data) {
            $regionSubArray[]  = $data['subtype'];
        }

        sort($subtypeBody);

        //Вычисляем суммы по столбцам и ссобираем массив процентов от общего числа
        foreach ($subtypeBody as $subtype) {
            $sum = array_sum(ArrayHelper::getColumn($regionSubArray, $subtype));
            $finalRowRegion[] = $sum;
            $percent[$subtype] = round(($sum*100)/$subtypeLength, 1);
        }

        arsort($percent);

        $lastRow = $counterSubtypeRegion + 1;
        $uniqueSubtypeRegion[$lastRow]['region'] = 'Всего';
        $uniqueSubtypeRegion[$lastRow]['sumRegion'] = array_sum($finalRowRegion);
        $uniqueSubtypeRegion[$lastRow]['subtype'] = array_slice($finalRowRegion, 0);
        $uniqueSubtypeRegion[$lastRow]['percent'] = $percent;

        return $uniqueSubtypeRegion;
    }

    // функция для подготовки данных для спареных графиков
    public static function tdrChartData($yearBlot, $tdrArray, $graphNum)
    {
        $result_array = [];
        if ($yearBlot) {
            $year_patients_count_array = array_count_values($yearBlot);
        } else {
            $year_patients_count_array = 1;
        }

        $year_count_tdr_array = [];

        $lambdaTable = [
            [3.689, 0],
            [5.572, 0.0254],
            [7.225, 0.242],
            [8.767, 0.619],
            [10.24, 1.09],
            [11.67, 1.623],
            [13.06, 2.202],
            [14.42, 2.814],
            [15.76, 3.454],
            [17.08, 4.115],
            [18.39, 4.795],
            [19.68, 5.491],
            [20.96, 6.201],
            [22.23, 6.922],
            [23.49, 7.654],
            [24.74, 8.395],
            [25.98, 9.145],
            [27.22, 9.903],
            [28.45, 10.67],
            [29.67, 11.44],
            [30.89, 12.22],
            [32.1, 13],
            [33.31, 13.78],
            [34.51, 14.58],
            [35.71, 15.38],
            [36.9, 16.18],
            [38.1, 16.98],
            [39.28, 17.79],
            [40.47, 18.61],
            [41.65, 19.421],
            [42.83, 20.24],
            [44, 21.06],
            [45.17, 21.89],
            [46.34, 22.72],
            [47.51, 23.55],
            [48.68, 24.38],
            [49.84, 25.21],
            [51, 26.05],
            [52.16, 26.89],
            [53.31, 27.73],
            [54.47, 28.58],
            [55.62, 29.4],
            [56.77, 30.27],
            [57.92, 31.12],
            [59.07, 31.97],
            [60.21, 32.82],
            [61.35, 33.68],
            [62.5, 34.53],
            [63.64, 35.39],
            [64.78, 36.25],
            [65.92, 37.11]
        ];

        // формируем массив год - количество
        if ($yearBlot) {
            foreach ($yearBlot as $key => $year) {
                if ($year == 'нет данных') continue;
                if (!$year_count_tdr_array[$year]) $year_count_array[$year] = 0;
                $year_count_tdr_array[$year] += $tdrArray[$key];
            }
        }

        ksort($year_count_tdr_array);

        if ($graphNum == 1) {

            // высчитываем проценты и формируем готовый массив координат для графика
            foreach ($year_count_tdr_array as $year => $count) {
                if ($year < 2006) continue;
                array_push($result_array, [
                    'x' => $year,
                    'y' => round($count * 100 / $year_patients_count_array[$year], 1)
                ]);
            }
        } else {
            // формируем массив группа лет - количество
            $minmax_array = [
                [2006, 2008],
                [2009, 2014],
                [2015, 2019]
            ];

            foreach ($minmax_array as $minmax) {
                $patient_count = 0;
                $tdr_count = 0;

                foreach ($year_count_tdr_array as $year => $count) {
                    if ($year >= $minmax[0] && $year <= $minmax[1]) {
                        $tdr_count += $count;
                        $patient_count += $year_patients_count_array[$year];
                    }
                }

                $y = $patient_count != 0 ? round($tdr_count * 100 / $patient_count, 1) : 0;

                if ($patient_count > 0) {
                    $precisionValue = [];
                    $precisionValue[0] = $tdr_count <= 50 ? $lambdaTable[$tdr_count][0] : ($tdr_count + 1.96 * sqrt($tdr_count));
                    $precisionValue[1] = $tdr_count <= 50 ? $lambdaTable[$tdr_count][1] : ($tdr_count - 1.96 * sqrt($tdr_count));

                    $lambda = [
                        round($y + round(($precisionValue[0] * 100 / $patient_count) - $y), 1),
                        round($y - round($y - ($precisionValue[1] * 100 / $patient_count)), 1)
                    ];
                } else {
                    $lambda = [0, 0];
                }

                array_push($result_array, [
                    'x' => $minmax[0] . '-' . $minmax[1],
                    'y' => $y,
                    'lambda' => $lambda
                ]);
            }
        }

        return $result_array;
    }

    //Функция собирает массив для графика ЛУ и если $level в true то для графика на старте 1 линии
    public static function patientLu($federalRegion, $patientLu, $level = false)
    {
        $supervisoryMutations = [];
        $supervisoryMutationsLevel = [];
        $signatureArray = [];
        $unsetArray = ['нет данных',];
        //Подсчет общего числа элементов для вычисления <50 условия
        $patientCount = array_count_values($federalRegion);

        // Массив регион => [Всего, сколько насчитали по данному округу, процент ЛУ]

        foreach ($patientLu as $fo => $countLu) {
            $supervisoryMutations[$fo] = [$patientCount[$fo], $countLu, (round($countLu * 100 / $patientCount[$fo], 1) . '%')];
        }
        //То же самое для НИЗКИЙ_ВЫСОКИЙ
        foreach ($patientLu as $fo => $countLuLevel) {
            $supervisoryMutationsLevel[$fo] = [$patientCount[$fo], $countLuLevel, (round($countLuLevel * 100 / $patientCount[$fo], 1) . '%')];
        }

        //Собираем массив всех ФО кто < 50 для удаления из выборки
        foreach ($patientCount as $fo => $count) {
            if ($patientCount[$fo] < 50) {
                $unsetArray[] = $fo;
            }
        }
        //Удаляем лишние данные из выборки и заносим их для подписи
        foreach ($unsetArray as $unsetValue) {
            if ($unsetValue !== 'нет данных') {
                $signatureArray[$unsetValue] = $patientCount[$unsetValue];
            }
            unset($supervisoryMutations[$unsetValue]);
            unset($supervisoryMutationsLevel[$unsetValue]);
        }

        $tableTitle = ['ФО', 'Всего пациентов', 'Пациентов с ЛУ', 'Уровень ЛУ'];
        array_unshift($supervisoryMutations, $tableTitle);

        if ($level == true) {
            return $supervisoryMutationsLevel;
        } else {
            return [$supervisoryMutations, $signatureArray];
        }
    }

    public static function replaceBadCharacters($patientArray)
    {
        $seq = strtoupper(preg_replace('/[^A-Za-z]/U', '', $patientArray));
        return preg_replace('/[^ACGTKMRSWYBDHVN -]/i', '-', trim($seq));
    }

    //Функция конструктор итогового массива, добавляет новый элемент если такого номера карты пациента еще нет в массиве
    public static function finalArray($patientArray, $infectionWayArray, $federalCityArray, $federalRegionArray, $federalDistrictArray, $federalCountryArray, $spravDiseaseStageArray, $drugFullArray, $drugShortArray)
    {
        $patientArray9 = null;
        $patientArray14 = null;
        $patientArray15 = null;
        $patientArray17 = null;

        if ($patientArray[14] == 'Орел') {
            $patientArray14 = 'Орёл';
        } elseif ($patientArray[14] == 'Ханты-Мансийский АО') {
            $patientArray14 = 'Ханты-Мансийский АО — Югра';
        }

        if (self::mb_ucfirst($patientArray[9]) == 'Половой') {
            $patientArray9 = 'Гетеросексуальный';
        }

        if ($patientArray[15] == 'Еврейская автономная область') {
            $patientArray15 = 'Еврейская АО';
        } elseif ($patientArray[15] == 'Оренбуржская область') {
            $patientArray15 = 'Оренбургская область';
        }

        if ($patientArray[17] == 'Российская Федерация') {
            $patientArray17 = 'Россия';
        } elseif ($patientArray[17] == 'РФ') {
            $patientArray17 = 'Россия';
        }

        return [
            'main' => [
                'project_type' => $patientArray[0],
                'sex' => $patientArray[2] ? array_search(trim(self::mb_ucfirst($patientArray[2])), Yii::$app->params['genders']) : 0,
                'bDay' => self::strToDate($patientArray[3]),
                'bDayYear' => $patientArray[4],
                'HIVBlotDate' => self::strToDate($patientArray[5]),
                'HIVBlotYear' => $patientArray[6],
                'infectionCode' => $patientArray[7] ? $patientArray[7] : null,
                'inspectionCode' => self::mb_ucfirst($patientArray[8]),
                'infectionWay' => $patientArray9 ? $infectionWayArray[self::mb_ucfirst($patientArray9)] : $infectionWayArray[self::mb_ucfirst($patientArray[9])],
                'infectionDate' => self::strToDate($patientArray[10]),
                'infectionYear' => $patientArray[11],
                'ARVP' => $patientArray[12] ? array_search(self::mb_ucfirst($patientArray[12]), Yii::$app->params['binary_array']) : 2,
                'DKP'=> $patientArray[13] ? array_search(self::mb_ucfirst($patientArray[13]), Yii::$app->params['binary_array']) : 2,
                'residence' => [
                    'residenceCity' => $patientArray14 ? $federalCityArray[$patientArray14] : $federalCityArray[$patientArray[14]],
                    'residenceRegion' => $patientArray15 ? $federalRegionArray[$patientArray15] : $federalRegionArray[$patientArray[15]],
                    'residenceFO' => $federalDistrictArray[$patientArray[16]],
                    'residenceCountry' => $patientArray17 ? $federalCountryArray[$patientArray17] : $federalCountryArray[$patientArray[17]]
                ],
                'infectionRegion' => [
                    'infectionRegion' => $federalRegionArray[$patientArray[18]],
                    'infectionCountry' => $federalCountryArray[$patientArray[19]]
                ],
                'comment' => $patientArray[88],
                'save_date' => strtotime($patientArray[89]) ? strtotime($patientArray[89]) : time(),
            ],
            'multiple' => [
                'curseStage' => [
                    1 => [
                        'curseDate' => self::strToDate($patientArray[20]),
                        'curseYear' => $patientArray[21] ? $patientArray[21] : null,
                        'stage' => $spravDiseaseStageArray[$patientArray[22]] ? trim($spravDiseaseStageArray[$patientArray[22]]) : null,
                    ],
                    2 => [
                        'curseDate' => self::strToDate($patientArray[23]),
                        'curseYear' => $patientArray[24] ? $patientArray[21] : null,
                        'stage' => $spravDiseaseStageArray[$patientArray[25]] ? trim($spravDiseaseStageArray[$patientArray[25]]) : null,
                    ],
                    3 => [
                        'curseDate' => self::strToDate($patientArray[26]),
                        'curseYear' => $patientArray[27] ? $patientArray[21] : null,
                        'stage' => $spravDiseaseStageArray[$patientArray[28]] ? trim($spravDiseaseStageArray[$patientArray[28]]) : null,
                    ]
                ],
                'viralLoad' => [
                    1 => [
                        'viralLoadDate' => self::strToDate($patientArray[29]),
                        'viralLoadYear' => $patientArray[30] ? $patientArray[30] : null ,
                        'indication' => self::checkViralLoadCell($patientArray[31]),
                    ],
                    2 => [
                        'viralLoadDate' => self::strToDate($patientArray[32]),
                        'viralLoadYear' => $patientArray[33] ? $patientArray[33] : null,
                        'indication' => self::checkViralLoadCell($patientArray[34]),
                    ],
                    3 => [
                        'viralLoadDate' => self::strToDate($patientArray[35]),
                        'viralLoadYear' => $patientArray[36] ? $patientArray[36] : null,
                        'indication' => self::checkViralLoadCell($patientArray[37]),
                    ]
                ],
                'cd4Level' => [
                    1 => [
                        'cd4Date' => self::strToDate($patientArray[38]),
                        'cd4Year' => $patientArray[39] ? $patientArray[39] : null,
                        'indication' => (int)$patientArray[40],
                    ],
                    2 => [
                        'cd4Date' => self::strToDate($patientArray[41]),
                        'cd4Year' => $patientArray[42] ? $patientArray[42] : null,
                        'indication' => (int)$patientArray[43],
                    ],
                    3 => [
                        'cd4Date' => self::strToDate($patientArray[44]),
                        'cd4Year' => $patientArray[45] ? $patientArray[45] : null,
                        'indication' => (int)$patientArray[46],
                    ]
                ],
                'alleleHLA' => [
                    [
                        'alleleDate' => self::strToDate($patientArray[47]),
                        'alleleYear' => $patientArray[48],
                        'alleleFound' => array_search($patientArray[49], Yii::$app->params['binary_array']),
                    ]
                ],
                'therapyDKP' => [
                    'dateStart' => self::strToDate($patientArray[50]) ? self::strToDate($patientArray[50]) : null,
                    'dateEnd' => self::strToDate($patientArray[51]) ? self::strToDate($patientArray[51]) : null,
                    'drug' => self::shortToId($patientArray[52], $drugFullArray, $drugShortArray),
                ],
                'theraphy' => [
                    1 => [
                        'dateStart' => self::strToDate($patientArray[53]),
                        'dateEnd' => self::strToDate($patientArray[54]),
                        'adherence' => array_search(self::mb_ucfirst(trim($patientArray[55])), Yii::$app->params['adherence_array_old']),
                        'drug' => self::shortToId(trim($patientArray[56]), $drugFullArray, $drugShortArray),
                    ],
                    2 => [
                        'dateStart' => self::strToDate($patientArray[57]),
                        'dateEnd' => self::strToDate($patientArray[58]),
                        'adherence' => array_search(self::mb_ucfirst(trim($patientArray[59])), Yii::$app->params['adherence_array_old']),
                        'drug' => self::shortToId(trim($patientArray[60]), $drugFullArray, $drugShortArray),
                    ],
                    3 => [
                        'dateStart' => self::strToDate($patientArray[61]),
                        'dateEnd' => self::strToDate($patientArray[62]),
                        'adherence' => array_search(self::mb_ucfirst(trim($patientArray[63])), Yii::$app->params['adherence_array_old']),
                        'drug' => self::shortToId(trim($patientArray[64]), $drugFullArray, $drugShortArray),
                    ],
                    4 => [
                        'dateStart' => self::strToDate($patientArray[65]),
                        'dateEnd' => self::strToDate($patientArray[66]),
                        'adherence' => array_search(self::mb_ucfirst(trim($patientArray[67])), Yii::$app->params['adherence_array_old']),
                        'drug' => self::shortToId(trim($patientArray[68]), $drugFullArray, $drugShortArray),
                    ],
                    5 => [
                        'dateStart' => self::strToDate($patientArray[69]),
                        'dateEnd' => self::strToDate($patientArray[70]),
                        'adherence' => array_search(self::mb_ucfirst(trim($patientArray[71])), Yii::$app->params['adherence_array_old']),
                        'drug' => self::shortToId(trim($patientArray[72]), $drugFullArray, $drugShortArray),
                    ],
                ],
                'sequences' => [
                    'pro-rev' => [
                        'date' => trim(self::strToDate($patientArray[73])),
                        'sequence' => trim(self::replaceBadCharacters($patientArray[75])),
                        'method' => trim($patientArray[74]) ? array_search(trim($patientArray[74]), Yii::$app->params['method_of_sequencing']) : 0
                    ],
                    'env' => [
                        'date' => trim(self::strToDate($patientArray[82])),
                        'sequence' => trim($patientArray[84]),
                        'method' => trim($patientArray[83]) ? array_search(trim($patientArray[83]), Yii::$app->params['method_of_sequencing']) : 0
                    ],
                    'int' => [
                        'date' => trim(self::strToDate($patientArray[79])),
                        'sequence' => trim(self::replaceBadCharacters($patientArray[81])),
                        'method' => trim($patientArray[80]) ? array_search(trim($patientArray[80]), Yii::$app->params['method_of_sequencing']) : 0
                    ],
                    'full' => [
                        'date' => trim(self::strToDate($patientArray[85])),
                        'sequence' => trim(self::replaceBadCharacters($patientArray[87])),
                        'method' => trim($patientArray[86]) ? array_search(trim($patientArray[86]), Yii::$app->params['method_of_sequencing']) : 0
                    ],
                    'pro-rev-int' => [
                        'date' => trim(self::strToDate($patientArray[76])),
                        'sequence' => trim(self::replaceBadCharacters($patientArray[78])),
                        'method' => trim($patientArray[77]) ? array_search(trim($patientArray[77]), Yii::$app->params['method_of_sequencing']) : 0
                    ]
                ],
            ]
        ];
    }

    //Функция переводит строкое представление даты в заданный формат даты (если не указан формат по дефолту день-месяц-год)
    public static function strToDate($data, $format = 'd-m-Y')
    {
        if ($data == '' || $data == null) return null;

        return date($format, strtotime($data));
    }

    //функция собирает хеш пациента графы main, если номера карт совпали, флаг указывает собирать из базы ('Db') или из массива ('Excel')
    public static function patientMainHash($model_patient, $flag)
    {
        if ($flag == 'Db') {
            $gender = $model_patient->gender;
            $birthday = self::strToDate($model_patient->birthday);
            $inspectionCode = $model_patient->inspection_code;
            $firstHivBlotDate = self::strToDate($model_patient->first_hiv_blot_date_day . '-' . $model_patient->first_hiv_blot_date_month . '-' . $model_patient->first_hiv_blot_date_year);
            $infectionCode = $model_patient->infection_code;
            $infectionWay = $model_patient->infection_way;
            $infectionDate = self::strToDate($model_patient->infection_date_day . '-' . $model_patient->infection_date_month . '-' . $model_patient->infection_date_year);
            $livingCityId = $model_patient->living_city_id;
            $livingRegionId = $model_patient->living_region_id;
            $livingDistrictId = $model_patient->living_district_id;
            $infectionCountryId = $model_patient->infection_country_id;
            $infectionRegionId = $model_patient->infection_region_id;
            $infectionDistrictId = $model_patient->infection_district_id;
            $arvp = $model_patient->arvp;

            $patientMainDbHash = md5($gender . $infectionCode . $birthday . $inspectionCode . $firstHivBlotDate . $infectionWay . $infectionDate . $livingCityId . $livingRegionId . $livingDistrictId . $infectionCountryId . $infectionRegionId . $infectionDistrictId . $arvp);

            return $patientMainDbHash;

        } elseif ($flag == 'Excel') {
            $gender = $model_patient['sex'];
            $birthday = self::strToDate($model_patient['bDay']);
            $inspectionCode = $model_patient['inspectionCode'];

            if ($model_patient['HIVBlotDate']) {
                $firstHivBlotDate = self::strToDate($model_patient['HIVBlotDate']);
            } else {
                $firstHivBlotDate = $model_patient['HIVBlotYear'];
            }

            $infectionCode = $model_patient['infectionCode'];

            if ($model_patient['infectionDate']) {
                $infectionDate = self::strToDate($model_patient['infectionDate']);
            } else {
                $infectionDate = $model_patient['infectionYear'];
            }

            $livingCityId = $model_patient['residence']['residenceCity'];
            $livingRegionId = $model_patient['residence']['residenceRegion'];
            $livingDistrictId = $model_patient['residence']['residenceFO'];
            $infectionCountryId = $model_patient['infectionRegion']['infectionCountry'];
            $infectionRegionId = $model_patient['infectionRegion']['infectionRegion'];
            $infectionDistrictId = $model_patient['infectionRegion']['infectionFO'];
            $infectionWay = $model_patient['infectionWay'];
            $arvp = $model_patient['ARVP'];

            $excelPatientMainHash = md5($gender . $infectionCode . $birthday . $inspectionCode . $firstHivBlotDate . $infectionWay . $infectionDate . $livingCityId . $livingRegionId . $livingDistrictId . $infectionCountryId . $infectionRegionId . $infectionDistrictId . $arvp);

            return $excelPatientMainHash;
        }

        return null;
    }

    //Функция генерирует хеши множественного параметра для сверки с базой
    public static function patientMultipleHashExcel($patientData, $multipleParam)
    {
        $excelHashArray = [];
        switch ($multipleParam) {
            case 'curseStage':
                foreach ($patientData['curseStage'] as $key => $data) {
                    if ($data['curseDate']) {
                        $excelHashArray[$key] = md5($data['stage'] . $data['curseDate']);
                    } else {
                        $excelHashArray[$key] = md5($data['stage'] . $data['curseYear']);
                    }
                }
                break;

            case 'viralLoad':
                foreach ($patientData['viralLoad'] as $key => $data) {
                    if ($data['viralLoadDate']) {
                        $excelHashArray[$key] = md5($data['indication'] . $data['viralLoadDate']);
                    } else {
                        $excelHashArray[$key] = md5($data['indication'] . $data['viralLoadYear']);
                    }
                }
                break;

            case 'cd4Level':
                foreach ($patientData['cd4Level'] as $key => $data) {
                    if ($data['cd4Date']) {
                        $excelHashArray[$key] = md5($data['indication'] . $data['cd4Date']);
                    } else {
                        $excelHashArray[$key] = md5($data['indication'] . $data['cd4Year']);
                    }
                }
                break;

            case 'alleleHLA':
                foreach ($patientData['alleleHLA'] as $key => $data) {
                    if ($data['alleleDate']) {
                        $excelHashArray[$key] = md5($data['alleleFound'] . $data['alleleDate']);
                    } else {
                        $excelHashArray[$key] = md5($data['alleleFound'] . $data['alleleYear']);
                    }
                }
                break;

            case 'theraphy':
                foreach ($patientData['theraphy'] as $key => $data) {
                    $excelHashArray[$key] = md5($data['drug'] . $data['adherence'] . $data['dateStart'] . $data['dateEnd']);
                }
                break;

            case 'sequence':
                foreach ($patientData['sequences'] as $key => $data) {
                    if ($data['sequence']) {
                        if ($key == 'pro-rev') {
                            $type = 1;
                        } elseif ($key == 'env') {
                            $type = 2;
                        } elseif ($key == 'int') {
                            $type = 3;
                        } elseif ($key == 'full') {
                            $type = 9;
                        } else {
                            $type = null;
                        }
                        if ($data['date']) {
                            $excelHashArray[$key] = md5($data['sequence'] . $type . $data['date']);
                        } else {
                            $excelHashArray[$key] = md5($data['sequence'] . $type);
                        }
                    }
                }
                break;
            default:
                $excelHashArray[] = '';
        }

        return $excelHashArray;
    }

    //Функция собирает массивы PRO и REV резистентности по ответу api стенфорда [Препарат => уровень резистентности]
    public static function drugResistance($resistanceStanford)
    {
        $drugResistance = [];

        if ($resistanceStanford) {
            foreach ($resistanceStanford as $resistanceArray) {
                $drugResistance[$resistanceArray['drug']['displayAbbr']] = $resistanceArray['level'];
            }
        }

        return $drugResistance;
    }

    //Функция собирает список мутаций по категориям от ответа api Стенфорда
    public static function mutationsListApi($mutationsArray)
    {
        $mutations = [];

        if ($mutationsArray) {
            foreach ($mutationsArray as $mutationTypeArray) {
                $mutationConcat = '';
                foreach ($mutationTypeArray['mutations'] as $mutationName) {

                    $mutationConcat .= ':' . $mutationName['text'];
                }
                $mutations[$mutationTypeArray['mutationType']] = trim($mutationConcat, ':');
            }
        }

        return $mutations;
    }

    //Функция собирает мутации PRO и REV ответа Стенфорда в строку и сравнивает их со списком мутаций в params
    //Возвращает строку совпавших мутаций
    public static function compareMutations($mutationArray, $flag)
    {
        $comparisonArray = [];

        //Ищем совпадения по регулярке и собираем массив [['основная мутация'] => ['буква комбинации', '-//-', etc]]
        foreach ($mutationArray as $mutation) {
            preg_match_all('|([A-Z]{1}[0-9]{1,3})([A-Z*]+)|', $mutation, $matches);
            $compared[$matches[1][0]] = str_split($matches[2][0]);
        }
        $diffKeys = array_diff_key($compared, Yii::$app->params[$flag]);

        //Удаляем расхождение по ключам оставляя только совпавшие, их будем проверять дальше
        foreach ($diffKeys as $mutationKey => $mutationValue) {
            unset($compared[$mutationKey]);
        }

        //Ели хотя бы 1 буква $compared есть в Yii::$app->params[$flag] то включаем в итоговый массив
        foreach ($compared as $key => $letterArray) {
            foreach ($letterArray as $letter) {
                if (in_array($letter, Yii::$app->params[$flag][$key])) {
                    $comparisonArray[] = $key . implode($letterArray);
                }
            }
        }

        return implode(', ', $comparisonArray);
    }

    //Функция разбивает мутации на две части после чего склеивает различные буквы из второй части с первой
    public static function stickMutations($mutationsArray)
    {
        $compared = [];
        $stickMutation = [];

        //Режем мутации на две части и разбиваем вторую часть побуквенно
        foreach ($mutationsArray as $string) {
            $mutations = explode(',', $string);
            foreach ($mutations as $mutation) {
                $mutation = trim($mutation);
                preg_match_all('|([A-Z]{1}[0-9]{1,3})([A-Z*]+)|', $mutation, $matches);
                $compared[] = [$matches[1][0] => str_split($matches[2][0])];
            }
        }

        //Приклеиваем к каждой первой части каждую букву из второй
        foreach ($compared as $wordArrays) {
            foreach ($wordArrays as $keyWords => $wordArray) {
                foreach ($wordArray as $word) {
                    if ($word != '*') {
                        $stickMutation[] = $keyWords . $word;
                    }
                }
            }
        }

        //Удаляем мутацию если последняя буква мутации совпадает с первой
        foreach ($stickMutation as $keyMutation => $mutation) {
            if (substr($mutation, -1) == substr($mutation, 0, 1) && $mutation != '') {
                $stickMutation[$keyMutation] = '';
            }
        }

        return $stickMutation;
    }

    //Функция собирает массив [name(в зависимости от переданного имени модели) => id, ...]
    public static function nameToId($desiredObjs)
    {
        $nameToIdArray = [];
        foreach ($desiredObjs as $Obj) {
            $nameToIdArray[$Obj->name] = $Obj->id;
        }

        return $nameToIdArray;
    }

    //Функция переводит первую букву слова в кодировке UTF-8 в верхний регистр
    public static function mb_ucfirst($str, $encoding = 'UTF-8')
    {
        $str = mb_ereg_replace('^[\ ]+', '', $str);
        $str = mb_strtoupper(mb_substr($str, 0, 1, $encoding), $encoding) . mb_substr($str, 1, mb_strlen($str), $encoding);

        return $str;
    }

    //Функция переводит shortDrugName в id и обратно в шорт неймы (для записи в базу и для выгрузки в эксель)
    public static function shortToId($drugString, $drugFullArray, $drugShortArray, $flag = 'in')
    {
        $drugIdArray = [];
        $drugIdFullArray = [];
        $drugArray = explode(':', $drugString);
        if ($flag == 'out') {
            //Переворачиваем ключи со значениями местами
            $flipDrugArray = array_flip($drugFullArray);
            $flipShortArray = array_flip($drugShortArray);

            //Переводим из айдишников (полных названий) лекарств в короткие айдишники
            foreach ($drugArray as $drug) {
                $drugIdFullArray[] = $flipDrugArray[$drug];
            }

            //переводим короткие айдишники в аббревиатуры
            foreach ($drugIdFullArray as $shortDrug) {
                $drugIdArray[] = $flipShortArray[$shortDrug];
            }
        } else {
            foreach ($drugArray as $drug) {
                $drugIdArray[] = $drugFullArray[$drugShortArray[$drug]];
            }
        }

        return implode(':', $drugIdArray);
    }

    //Функция проверяет что находится в ячейке вирусной нагрузки Excel и конвертирует в нужные значения
    public static function checkViralLoadCell($viralLoadCell)
    {
        $cellNumber = preg_replace('/[^0-9]/', '', $viralLoadCell);
        if ($cellNumber) {
            if ($cellNumber <= 50 && $cellNumber >= 0) {
                $cellValue = -50;
            } else if ($cellNumber <= 500 && $cellNumber > 50) {
                $cellValue = -500;
            } else if (!$cellNumber) {
                $cellValue = 0;
            } else {
                $cellValue = $cellNumber;
            }
        }

        return $cellValue;
    }

    // Функция проверяет есть ли дубликаты номеров карт или дубликаты основной информации (др пол вич+ блот)
    public static function checkBatchDup($patientsData, $type)
    {
        $patientDataArray = [];
        $patientDataArrayReverse = [];
        $dupBatchData = [];
        $sequenceProArray = [];

        if ($type == 'card') {
            // Собираем все номера карт
            foreach ($patientsData as $patientData) {
                if ($patientData[1]) {
                    $patientDataArray[] = $patientData[1];
                }
            }
            // Сбор массива дублей карт пациентов в батче
            foreach(array_count_values($patientDataArray) as $key => $val) {
                if ($val > 1) {
                    $dupBatchData[] = $key;   //Push the key to the array sice the value is more than 1
                }
            }
        } elseif ($type == 'bundle') {
            //Собирамем массив осн данных из батча и массив для реверса в номер карты
            foreach ($patientsData as $patientData) {
                if ($patientData[2] && $patientData[3] && $patientData[5]) {
                    if ($patientData[1] && ($patientData[2] || $patientData[3] || $patientData[5])) {
                        $patientDataArray[$patientData[1]] = $patientData[2] . $patientData[3] . $patientData[5];
                        $patientDataArrayReverse[$patientData[2] . $patientData[3] . $patientData[5]] = $patientData[1];
                    }
                }
            }

            $newArray = [];
            foreach ($patientDataArray as $keyData => $data) {
                if (count(Common::searcher($patientDataArray, $data)) > 1) {
                    if (!in_array($newArray, Common::searcher($patientDataArray, $data)))
                    $newArray[] = Common::searcher($patientDataArray, $data);
                }
            }
            $cardNumberArrays = array_intersect_key($newArray, array_unique(array_map('serialize', $newArray)));
            foreach ($cardNumberArrays as $numArray) {
                foreach ($numArray as $numDup) {
                    $dupBatchData[] = $numDup;
                }
            }
        } elseif ($type = 'sequence') {
            foreach ($patientsData as $patientData) {
                if ($patientData[75]) {
                    $sequenceProArray[] = $patientData[75];
                }
                if ($patientData[81]) {
                    $sequenceProArray[] = $patientData[81];
                }
                if ($patientData[84]) {
                    $sequenceProArray[] = $patientData[84];
                }

            }

            foreach (array_count_values(array_values($sequenceProArray)) as $key => $val) {
                if ($val > 1) {
                    $dupBatchData[] = $key;   //Push the key to the array sice the value is more than 1
                }
            }
        }

        return $dupBatchData;
    }

    // Функция собирает массивы дублей в базе данных
    public static function patientIdDup($indicators)
    {
        $dupIndicatorArray = [];

        //Если значение possible_dup отлично от 0, то пишем в массив
        foreach ($indicators as $indicator) {
            if ($indicator->possible_dup != 0) {
                $dupIndicatorArray[] = $indicator->patient_id;
            }
        }

        return $dupIndicatorArray;
    }

    //Функция проверяет наличие терапии на отсеквенированном участке, возвращает массив количества [ARVT, !+ARVT, NO_DATA, COUNT]
    public static function arvpByType($modelsPatient, $typeNum)
    {
        $centerByType = [];

        foreach ($modelsPatient as $modelPatient) {
            /* @var $modelPatient \app\models\Patient */
            foreach ($modelPatient->sequence as $seq) {
                if ($seq->type == $typeNum) {
                    if ($modelPatient->arvp == 1) {
                        $centerByType[$modelPatient->center->full_name]['arvp'] += 1;
                    } elseif ($modelPatient->arvp == 0) {
                        $centerByType[$modelPatient->center->full_name]['no_arvp'] += 1;
                    } else {
                        $centerByType[$modelPatient->center->full_name]['no_data'] += 1;
                    }
                    $centerByType[$modelPatient->center->full_name]['counter'] += 1;
                }
            }
        }

        $correctArray = ['arvp', 'no_arvp', 'no_data', 'counter'];
        //Заполнение нулями отсуствующих позиций для отрисовки в таблице
        foreach ($centerByType as $center => $centerData) {
            $centerDataKays = array_keys($centerData);
            foreach ($correctArray as $correct) {
                if (!in_array($correct, $centerDataKays)) {
                    $centerByType[$center][$correct] = 0;
                }
            }
        }

        return $centerByType;
    }

    //Функция добавляет в массив последнюю строку общего кол-ва для таблиц главной страницы
    public static function mainPageEndRaw($mainPageTableData)
    {
        $arvp = 0;
        $noArvp = 0;
        $counter = 0;
        $noData = 0;

        foreach ($mainPageTableData as $center => $centerData) {
            $arvp +=  $centerData['arvp'];
            $noArvp +=  $centerData['no_arvp'];
            $noData +=  $centerData['no_data'];
            $counter += $centerData['counter'];
        }

        return ['arvp' => $arvp, 'no_arvp' => $noArvp, 'no_data' => $noData, 'counter' => $counter];
    }

    //Функция набирает счетчик максимального количества параметров среди всех пациентов
    public static function maxPatientParameters($excelArray)
    {
        $curseStageCounter = 0;
        $viralCounter = 0;
        $cd4Counter = 0;
        $therapyCounter = 0;
        $seqCounter = 0;

        $checkCounterData = ['curseStage', 'therapy', 'viralLoad', 'cdTest', 'sequence'];

        foreach ($excelArray as $dataArray) {
            foreach ($checkCounterData as $param) {
                if ($dataArray['multiple'][$param]) {
                    $paramsArray = $dataArray['multiple'][$param];
                    switch ($param) {
                        case 'curseStage':
                            if (count($paramsArray) > $curseStageCounter) {
                                $curseStageCounter = count($paramsArray);
                            }
                            break;
                        case 'viralLoad':
                            if (count($paramsArray) > $viralCounter) {
                                $viralCounter = count($paramsArray);
                            }
                            break;
                        case 'cdTest':
                            if (count($paramsArray) > $cd4Counter) {
                                $cd4Counter = count($paramsArray);
                            }
                            break;
                        case 'sequence':
                            if (count($paramsArray) > $seqCounter) {
                                $seqCounter = count($paramsArray);
                            }
                            break;
                        case 'therapy':
                            if (count($paramsArray) > $therapyCounter) {
                                $therapyCounter = count($paramsArray);
                            }
                            break;
                        default: null;
                    }
                }
            }
        }

        return [
            'curseStage' => $curseStageCounter,
            'viralLoad' => $viralCounter,
            'cdTest' => $cd4Counter,
            'sequence' => $seqCounter,
            'therapy' => $therapyCounter
        ];
    }

}


