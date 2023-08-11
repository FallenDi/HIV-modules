/**
 * Created by ngalkin on 30.06.2020.
 */
const REPORT_ID = reportIdGet();
const RUSSIA_ID = 134;

const GRAPH_LOADER = '<div class="inside-div-loader"><div class="loader-complex loader-chart"><div class="loader"></div></div></div>';

// функция получает айди проекта из адресной строки
function reportIdGet() {
    var windowUrl = window.location.pathname;
    var urlArray = windowUrl.split('/');

    return urlArray[urlArray.length-1];
}

// tooltip
$(function () {
    $('body').tooltip({
        selector: '[data-toggle="tooltip"]'
    })
});

setTimeout(function(){
    $('#submit-groupdata').trigger('click');
}, 900000);

// функция преобразует векторные графики в растровые
function convertGraphs() {
    $(document).ready(function() {
        if (document.hidden) {
            setTimeout(function() {
                convertGraphs();
            }, 5000);
        } else {
            var charts = document.querySelectorAll('div.chart[id]');
            var chartCount = charts.length;
            var node, chartId;

            if (chartCount > 0) {
                for (var i = 0; i < chartCount; i++) {
                    chartId = i + 1;
                    node = document.getElementById('chart' + chartId);
                    var rect = node.getBoundingClientRect();
                    var size = {width: rect.width, height: rect.height};

                    dom2image(node, chartId, size);
                }
            }
        }
    });
}
convertGraphs();

// функция растеризует график и сохраняет на диск
function dom2image(node, i, size) {
    if (node) {
        domtoimage.toPng(node, size)
            .then(function(dataUrl) {
                $.ajax({
                    type: "POST",
                    url: '/report/graph-to-image',
                    async: true,
                    complete: function() {

                    },
                    data: {
                        blob: dataUrl,
                        chart_id: i,
                        report_id: REPORT_ID
                    },
                    success: function(data) {

                    },
                    error: function() {
                        console.error("Ajax error!");
                    }
                });
            })
            .catch(function(error) {
                console.error('Не удалось растеризовать график.', error);
            });
    }
}

// обрабатываем клик по кнопке pdf-отчёта
$(document).on('click', '.report-button, .patient-pdf-button', function(e) {
    e.preventDefault();
    var $this = $(this);
    $this.button('loading');
    pdfRender($this);
});

// сохраняем pdf-отчёт
function pdfRender($this) {
    var controllerName = '';
    if ($this.hasClass('report-button')) {
        controllerName = 'report';
    } else if ($this.hasClass('patient-pdf-button')) {
        controllerName = 'patient';
    }
    setTimeout(function() {
        $.ajax({
            type: "GET",
            url: '/' + controllerName + '/render-pdf',
            async: true,
            data: {
                id: REPORT_ID // при запросе на странице пациента этот параметр забирает айди пациента
            },
        complete: function() {
            $this.button('reset');
        },
        success: function(data) {
            document.location = '/' + controllerName + '/download-pdf?url=' + data;
        },
        error: function() {
            console.error("Ajax error!");
        }
    });
    }, 200);
}

// функция просчитывает координаты линии линейного тренда (регрессии)
function leastSquaresEquation(x_data, y_data) {

    var ReduceAddition = function(prev, cur) { return prev + cur; };

    var x_mean = x_data.reduce(ReduceAddition) * 1.0 / x_data.length;
    var y_mean = y_data.reduce(ReduceAddition) * 1.0 / y_data.length;

    var SquareXX = x_data.map(function(d) { return Math.pow(d - x_mean, 2); })
        .reduce(ReduceAddition);

    var ssYY = y_data.map(function(d) { return Math.pow(d - y_mean, 2); })
        .reduce(ReduceAddition);

    var MeanDiffXY = x_data.map(function(d, i) { return (d - x_mean) * (y_data[i] - y_mean); })
        .reduce(ReduceAddition);

    var slope = MeanDiffXY / SquareXX;
    var intercept = y_mean - (x_mean * slope);

    return function(x){
        return x*slope+intercept
    }
}

// функция просчитывает координаты x для лейблов групп графика
function xCoordMath(d) {
    var center_value = d.values.length / 2;
    var center_key;
    var x_value, array;
    if (center_value % 2 === 0 || center_value === 1) {
        center_key = Math.floor(center_value);
        array = d.values.slice(center_key-1, center_key+1);
        x_value = (x(array[0].x) + x(array[1].x)) / 2;
    } else {
        center_key = Math.floor(center_value);
        x_value = x(d.values.slice(center_key, center_key+1)[0].x);
    }

    return x_value + x.bandwidth() / 2;
}

// функция возвращает сумму значений по оси Y
function getSumY(data) {
    var yArr = [];
    $.each(data, function(key, values) {
        yArr.push(d3.sum([values.y1, values.y2, values.y3, values.y4]));
    });

    return d3.max(yArr);
}

// функция округляет число до десятых
function round10(val) {
    return Math.round(val / 10);
}

// функция добавляет или удаляет лоадер со страницы
function preLoader(act, type)
{
    var loadingDiv = $('#loading');
    switch (act) {
        case 'add':
            if (loadingDiv.length === 0) {
                return $('body').append('<div id="loading"><div class="loader-complex ' + type + '"><div class="loader"></div></div></div>');
            }
            break;

        case 'remove':
            return loadingDiv.remove();
            break;
    }
}

// функция после выбора города подставляет автоматически регион и округ
function citySelectChange(e, citiesArray)
{
    var city_id = e.currentTarget.value;
    if (city_id) {
        $('#living_region_id').val(citiesArray[city_id]).trigger('change');
    }
}

// функция после выбора региона подставляет автоматически округ
function regionSelectChange(e, regionsArray)
{
    var currentTarget = e.currentTarget;
    var region_id = currentTarget.value;
    var jquery_id = currentTarget.id;
    var attribute = jquery_id.split('_')[0];

    if (region_id) {
        switch (attribute) {
            case 'living':
                $('#living_district_id').val(regionsArray[region_id]).trigger('change');
                break;

            case 'infection':
                $('#infection_district_id').val(regionsArray[region_id]).trigger('change');
                $('#infection_country_id').val(RUSSIA_ID).trigger('change');
                break;
        }
    }
}

// функция после выбора города подставляет автоматически регион и округ
// вызывается в форме добавления пациента
function countrySelectChange(e)
{
    var district_id = e.currentTarget.value;
    if (district_id != RUSSIA_ID) {
        $('#infection_region_id').val('').trigger('change').prop('disabled', true);
        //$('#infection_district_id').val('').trigger('change').prop('disabled', true);
    } else {
        $('#infection_region_id').prop('disabled', false);
        //$('#infection_district_id').prop('disabled', false);
    }
}

function livingCountrySelectChange(e)
{
    var district_id = e.currentTarget.value;
    if (district_id != RUSSIA_ID) {
        $('#living_region_id').val('').trigger('change').prop('disabled', true);
        $('#living_district_id').val('').trigger('change').prop('disabled', true);
        $('#living_city_id').val('').trigger('change').prop('disabled', true);
    } else {
        $('#living_region_id').prop('disabled', false);
        $('#living_district_id').prop('disabled', false);
        $('#living_city_id').prop('disabled', false);
    }
}

// фиксы динамической формы
function initSelect2DropStyle(a,b,c){
    initS2Loading(a,b,c);
}
function initSelect2Loading(a,b){
    initS2Loading(a,b);
}

// функция блокирует поля ввода в форме вирусной нагрузки, если уже установлены галочки
$(document).ready(function() {
    setTimeout(function() {
        $('.prepend-checkbox i').each(function() {
            var prependBlock = $(this).parents('.input-group').first();
            prependBlock.find('input:last').prop('disabled', true);
        });
    }, 20);
});

// функция добавляет возможность заменять все символы в строке
String.prototype.replaceAll = function(str1, str2, ignore) {
    return this.replace(new RegExp(str1.replace(/([\/\,\!\\\^\$\{\}\[\]\(\)\.\*\+\?\|\<\>\-\&])/g,"\\$&"),(ignore?"gi":"g")),(typeof(str2) === "string") ? str2.replace(/\$/g,"$$$$") : str2);
};