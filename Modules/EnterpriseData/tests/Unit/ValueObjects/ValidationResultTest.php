<?php

namespace Modules\EnterpriseData\Tests\Unit\ValueObjects;

use Modules\EnterpriseData\app\ValueObjects\ValidationResult;
use Tests\TestCase;

class ValidationResultTest extends TestCase
{
    public function test_creates_successful_result(): void
    {
        $result = ValidationResult::success();

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasErrors());
        $this->assertEmpty($result->getErrors());
    }

    public function test_creates_successful_result_with_warnings(): void
    {
        $warnings = ['Warning message'];
        $result = ValidationResult::success($warnings);

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $this->assertEquals($warnings, $result->getWarnings());
    }

    public function test_creates_failure_result(): void
    {
        $errors = ['Error message 1', 'Error message 2'];
        $result = ValidationResult::failure($errors);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());
        $this->assertEquals($errors, $result->getErrors());
        $this->assertEquals(2, $result->getErrorCount());
    }

    public function test_creates_result_with_single_error(): void
    {
        $error = 'Single error message';
        $result = ValidationResult::withSingleError($error);

        $this->assertFalse($result->isValid());
        $this->assertEquals($error, $result->getFirstError());
        $this->assertEquals(1, $result->getErrorCount());
    }

    public function test_merges_results_correctly(): void
    {
        $result1 = ValidationResult::withSingleError('Error 1');
        $result2 = ValidationResult::withSingleWarning('Warning 1');

        $merged = $result1->merge($result2);

        $this->assertFalse($merged->isValid()); // Один из результатов невалидный
        $this->assertEquals(['Error 1'], $merged->getErrors());
        $this->assertEquals(['Warning 1'], $merged->getWarnings());
    }

    public function test_adds_error_to_result(): void
    {
        $result = ValidationResult::success();
        $newResult = $result->addError('New error');

        // Оригинальный результат не изменился
        $this->assertTrue($result->isValid());

        // Новый результат содержит ошибку
        $this->assertFalse($newResult->isValid());
        $this->assertEquals(['New error'], $newResult->getErrors());
    }

    public function test_adds_warning_to_result(): void
    {
        $result = ValidationResult::success();
        $newResult = $result->addWarning('New warning');

        $this->assertTrue($newResult->isValid());
        $this->assertEquals(['New warning'], $newResult->getWarnings());
    }

    public function test_adds_context_to_result(): void
    {
        $result = ValidationResult::success();
        $newResult = $result->addContext('key', 'value');

        $this->assertEquals('value', $newResult->getContextValue('key'));
        $this->assertNull($newResult->getContextValue('nonexistent'));
        $this->assertEquals('default', $newResult->getContextValue('nonexistent', 'default'));
    }

    public function test_filters_errors_and_warnings(): void
    {
        $result = ValidationResult::failure(
            ['Database error', 'Validation error', 'Network error'],
            ['Database warning', 'Performance warning']
        );

        $dbErrors = $result->getErrorsContaining('Database');
        $dbWarnings = $result->getWarningsContaining('Database');

        $this->assertEquals(['Database error'], $dbErrors);
        $this->assertEquals(['Database warning'], $dbWarnings);
    }

    public function test_generates_summary(): void
    {
        $validResult = ValidationResult::success();
        $this->assertEquals('Valid', $validResult->getSummary());

        $validWithWarnings = ValidationResult::success(['Warning']);
        $this->assertEquals('Valid with 1 warning(s)', $validWithWarnings->getSummary());

        $invalidResult = ValidationResult::failure(['Error 1', 'Error 2'], ['Warning']);
        $this->assertEquals('Invalid: 2 error(s), 1 warning(s)', $invalidResult->getSummary());
    }

    public function test_creates_result_from_conditions(): void
    {
        $conditions = [
            true => 'Should not appear',
            false => 'Should appear as error',
            2 > 1 => 'Should not appear',
        ];

        $result = ValidationResult::fromConditions($conditions);

        $this->assertFalse($result->isValid());
        $this->assertContains('Should appear as error', $result->getErrors());
        $this->assertEquals(1, $result->getErrorCount());
    }

    public function test_functional_methods(): void
    {
        $successResult = ValidationResult::success();
        $failureResult = ValidationResult::failure(['Error']);

        $successCallbackCalled = false;
        $failureCallbackCalled = false;

        $successResult->onSuccess(function () use (&$successCallbackCalled) {
            $successCallbackCalled = true;
        });

        $failureResult->onFailure(function () use (&$failureCallbackCalled) {
            $failureCallbackCalled = true;
        });

        $this->assertTrue($successCallbackCalled);
        $this->assertTrue($failureCallbackCalled);
    }

    public function test_to_array_conversion(): void
    {
        $result = ValidationResult::failure(['Error'], ['Warning'], ['key' => 'value']);
        $array = $result->toArray();

        $this->assertArrayHasKey('valid', $array);
        $this->assertArrayHasKey('errors', $array);
        $this->assertArrayHasKey('warnings', $array);
        $this->assertArrayHasKey('context', $array);
        $this->assertArrayHasKey('summary', $array);

        $this->assertFalse($array['valid']);
        $this->assertEquals(['Error'], $array['errors']);
    }

    public function test_json_serialization(): void
    {
        $result = ValidationResult::failure(['Error'], ['Warning']);
        $json = $result->toJson();

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertFalse($decoded['valid']);
        $this->assertEquals(['Error'], $decoded['errors']);
    }
}
