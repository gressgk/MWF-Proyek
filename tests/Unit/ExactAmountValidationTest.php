<?php

use App\Services\MidtransPaymentService;

function makeAmountValidator(): MidtransPaymentService
{
    return (new ReflectionClass(MidtransPaymentService::class))->newInstanceWithoutConstructor();
}

test('e5.1.8c unit: accepts strictly positive whole-IDR integer and canonical decimal string formats', function (mixed $providerAmount, mixed $dbNominal) {
    $service = makeAmountValidator();

    expect($service->isExactAmountMatch($providerAmount, $dbNominal))->toBeTrue();
})->with([
    'int and int' => [10000, 10000],
    'string int and int' => ['10000', 10000],
    'single zero decimal and int' => ['10000.0', 10000],
    'two zero decimals and int' => ['10000.00', 10000],
    'two zero decimals and decimal string' => ['10000.00', '10000.00'],
    'int and decimal string' => [10000, '10000.00'],
    'string int and single zero decimal' => ['10000', '10000.0'],
    'minimum positive whole idr' => ['1.00', 1],
    'minimum positive int' => [1, '1'],
    'max decimal 15,2 capacity string vs string' => ['9999999999999.00', '9999999999999'],
    'max decimal 15,2 capacity int vs decimal string' => [9999999999999, '9999999999999.00'],
    'near decimal capacity 12 digits' => ['999999999999.0', '999999999999.00'],
]);

test('e5.1.8c unit: rejects non-matching valid whole-IDR amounts', function (mixed $providerAmount, mixed $dbNominal) {
    $service = makeAmountValidator();

    expect($service->isExactAmountMatch($providerAmount, $dbNominal))->toBeFalse();
})->with([
    'different amounts' => ['10000.00', '20000.00'],
    'off by one' => ['10001', 10000],
    'extra zero digit' => ['100000.00', 10000],
    'max vs max minus one' => ['9999999999999', '9999999999998'],
]);

function invalidAmountInputs(): array
{
    return [
        'null' => [null],
        'bool true' => [true],
        'bool false' => [false],
        'integral float' => [10000.0],
        'fractional float' => [10000.5],
        'scientific float' => [1e4],
        'float INF' => [INF],
        'float NAN' => [NAN],
        'empty array' => [[]],
        'non-empty array' => [['10000']],
        'object' => [new stdClass()],
        'empty string' => [''],
        'whitespace only' => ['   '],
        'leading whitespace' => [' 10000'],
        'trailing whitespace' => ['10000 '],
        'surrounding whitespace' => [' 10000.00 '],
        'tab whitespace' => ["\t10000"],
        'scientific notation lower' => ['1e4'],
        'scientific notation upper' => ['1E4'],
        'scientific decimal' => ['1.0e4'],
        'leading plus sign' => ['+10000'],
        'leading minus sign' => ['-10000'],
        'negative int' => [-10000],
        'zero int' => [0],
        'zero string' => ['0'],
        'zero single decimal' => ['0.0'],
        'zero two decimals' => ['0.00'],
        'negative zero' => ['-0'],
        'leading zeros' => ['00010000'],
        'single leading zero' => ['010000'],
        'thousands comma' => ['10,000'],
        'thousands underscore' => ['10_000'],
        'thousands dot' => ['10.000'],
        'trailing decimal dot' => ['10000.'],
        'fractional .5' => ['10000.5'],
        'fractional .50' => ['10000.50'],
        'fractional .01' => ['10000.01'],
        'fractional .99' => ['10000.99'],
        'three zero decimals' => ['10000.000'],
        'unicode arabic-indic digits' => ['١٠٠٠٠'],
        'unicode fullwidth digits' => ['１００００'],
        'embedded null byte' => ["10000\0"],
        'trailing newline' => ["10000\n"],
        'embedded crlf' => ["10000\r\n"],
        'exceeds decimal 15,2 capacity (14 digits)' => ['10000000000000'],
        'exceeds decimal 15,2 capacity int' => [10000000000000],
        'PHP_INT_MAX int' => [PHP_INT_MAX],
        'PHP_INT_MAX string' => [(string) PHP_INT_MAX],
        'oversized integer string' => ['99999999999999999999999999'],
    ];
}

test('e5.1.8c unit: rejects every invalid provider amount representation without throwing TypeError', function (mixed $invalidProvider) {
    $service = makeAmountValidator();

    expect($service->normalizeStrictWholeIdrAmount($invalidProvider))->toBeNull();
    expect($service->isExactAmountMatch($invalidProvider, 10000))->toBeFalse();
    expect($service->isExactAmountMatch($invalidProvider, '10000.00'))->toBeFalse();
})->with(invalidAmountInputs());

test('e5.1.8c unit: rejects every invalid local database nominal representation without throwing TypeError', function (mixed $invalidLocal) {
    $service = makeAmountValidator();

    expect($service->isExactAmountMatch('10000.00', $invalidLocal))->toBeFalse();
    expect($service->isExactAmountMatch(10000, $invalidLocal))->toBeFalse();
})->with(invalidAmountInputs());

test('e5.1.8c unit: rejects when both provider and local amounts are invalid or identical invalid values', function () {
    $service = makeAmountValidator();

    expect($service->isExactAmountMatch('10000.99', '10000.99'))->toBeFalse();
    expect($service->isExactAmountMatch('1e4', '1e4'))->toBeFalse();
    expect($service->isExactAmountMatch(null, null))->toBeFalse();
    expect($service->isExactAmountMatch(true, true))->toBeFalse();
    expect($service->isExactAmountMatch(0, 0))->toBeFalse();
    expect($service->isExactAmountMatch(['10000'], ['10000']))->toBeFalse();
    expect($service->isExactAmountMatch('99999999999999999999', '99999999999999999999'))->toBeFalse();
});

test('e5.1.8c unit: floating-point precision edge cases are rejected rather than rounded', function () {
    $service = makeAmountValidator();

    expect($service->isExactAmountMatch(10000.000000000001, 10000))->toBeFalse();
    expect($service->isExactAmountMatch(9999.999999999999, 10000))->toBeFalse();
    expect($service->isExactAmountMatch('10000.000000000001', 10000))->toBeFalse();
    expect($service->parseStrictWholeIdrInt(10000.0))->toBeNull();
    expect($service->parseStrictWholeIdrInt('10000.00'))->toBe(10000);
    expect($service->parseStrictWholeIdrInt('9999999999999.00'))->toBe(9999999999999);
});
