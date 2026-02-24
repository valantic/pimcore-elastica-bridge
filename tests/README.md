# Testing Guide

This document provides information on how to run and write tests for the Pimcore Elastica Bridge bundle.

## Table of Contents

- [Running Tests](#running-tests)
- [Test Structure](#test-structure)
- [Writing Tests](#writing-tests)
- [Code Coverage](#code-coverage)
- [Continuous Integration](#continuous-integration)

## Running Tests

### Prerequisites

1. Install dependencies:
   ```bash
   composer install
   ```

2. (Optional) Start Elasticsearch for integration tests:
   ```bash
   docker run -d -p 9200:9200 -e "discovery.type=single-node" -e "xpack.security.enabled=false" docker.elastic.co/elasticsearch/elasticsearch:8.16.1
   ```

### Run All Tests

```bash
composer test
```

or directly with PHPUnit:

```bash
./vendor/bin/phpunit
```

### Run Specific Test Suites

Run only unit tests:
```bash
./vendor/bin/phpunit --testsuite Unit
```

Run only integration tests:
```bash
./vendor/bin/phpunit --testsuite Integration
```

Run only functional tests:
```bash
./vendor/bin/phpunit --testsuite Functional
```

### Run Individual Test Files

```bash
./vendor/bin/phpunit tests/Unit/Document/AbstractDocumentTest.php
```

### Run with Code Coverage

```bash
composer test-coverage
```

This generates an HTML coverage report in the `coverage/` directory.

## Test Structure

Tests are organized into three main categories:

### Unit Tests (`tests/Unit/`)

Unit tests focus on testing individual classes and methods in isolation, using mocks and stubs for dependencies.

**Examples:**
- `Document/AbstractDocumentTest.php` - Tests document base functionality
- `Index/AbstractIndexTest.php` - Tests index base functionality
- `Enum/DocumentTypeTest.php` - Tests enum behavior
- `Service/PropagateChangesTest.php` - Tests service layer logic

### Integration Tests (`tests/Integration/`)

Integration tests verify that multiple components work together correctly, often with real or partially mocked dependencies.

**Examples:**
- `Command/StatusCommandTest.php` - Tests CLI commands
- `Messenger/Handler/RefreshElementHandlerTest.php` - Tests message handlers

### Functional Tests (`tests/Functional/`)

Functional tests (to be added) verify end-to-end workflows, including real Elasticsearch operations.

### Test Helpers (`tests/Helpers/`)

Reusable test utilities and factories:
- `ElasticsearchTestTrait.php` - Provides Elasticsearch test setup/teardown
- `PimcoreElementFactory.php` - Creates mock Pimcore elements
- `DocumentFactory.php` - Creates test document instances
- `IndexFactory.php` - Creates test index configurations

## Writing Tests

### Basic Test Structure

```php
<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\YourNamespace;

use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\YourClass;

class YourClassTest extends TestCase
{
    public function testSomething(): void
    {
        // Arrange
        $instance = new YourClass();

        // Act
        $result = $instance->doSomething();

        // Assert
        $this->assertTrue($result);
    }
}
```

### Using Mockery

For mocking dependencies:

```php
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class YourTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testWithMock(): void
    {
        $mock = Mockery::mock(SomeInterface::class);
        $mock->shouldReceive('method')
            ->once()
            ->with('argument')
            ->andReturn('result');

        // Use $mock in your test
    }
}
```

### Using Test Factories

```php
use Valantic\ElasticaBridgeBundle\Tests\Helpers\PimcoreElementFactory;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\DocumentFactory;

public function testWithFactories(): void
{
    $element = PimcoreElementFactory::createDataObject(123, published: true);
    $document = DocumentFactory::createTestDocument(DocumentType::DATA_OBJECT);

    // Use mocked element and document in your test
}
```

### Testing with Elasticsearch

For tests that need Elasticsearch:

```php
use Valantic\ElasticaBridgeBundle\Tests\Helpers\ElasticsearchTestTrait;

class YourIntegrationTest extends TestCase
{
    use ElasticsearchTestTrait;

    protected function tearDown(): void
    {
        $this->tearDownElasticsearch();
        parent::tearDown();
    }

    public function testWithElasticsearch(): void
    {
        $index = $this->createTestIndex('test_index', [
            'mappings' => [...],
        ]);

        // Perform operations on the index
        // Index will be automatically cleaned up
    }
}
```

### Data Providers

For testing multiple scenarios:

```php
use PHPUnit\Framework\Attributes\DataProvider;

#[DataProvider('exampleProvider')]
public function testWithProvider(string $input, string $expected): void
{
    $result = YourClass::process($input);
    $this->assertSame($expected, $result);
}

public static function exampleProvider(): array
{
    return [
        'case 1' => ['input1', 'output1'],
        'case 2' => ['input2', 'output2'],
    ];
}
```

## Code Coverage

### Viewing Coverage Reports

After running `composer test-coverage`, open `coverage/html/index.html` in your browser.

### Coverage Attributes

Use PHPUnit's coverage attributes to document what your test covers:

```php
class YourClassTest extends TestCase
{
    // Tests will be counted toward YourClass coverage
}
```

### Coverage Thresholds

The project aims for:
- **Minimum 80% code coverage** overall
- Focus on business logic and critical paths
- Exclude trivial getters/setters if appropriate

## Continuous Integration

### GitHub Actions

Tests run automatically on:
- Every push to any branch
- Every pull request

The CI pipeline runs tests against:
- PHP 8.2, 8.3, 8.4
- Pimcore 11.x, 12.x
- Both `prefer-lowest` and `prefer-stable` dependency strategies

### Local CI Simulation

To test against different PHP versions locally, use Docker:

```bash
docker run --rm -v $(pwd):/app -w /app php:8.3-cli composer install
docker run --rm -v $(pwd):/app -w /app php:8.3-cli ./vendor/bin/phpunit
```

## Best Practices

1. **Arrange-Act-Assert**: Structure tests with clear setup, execution, and verification phases
2. **One assertion per test**: Focus each test on a single behavior (when practical)
3. **Test names should describe behavior**: Use descriptive names like `testShouldIndexReturnsTrueForPublishedElements`
4. **Mock external dependencies**: Use mocks for databases, external services, etc.
5. **Clean up after tests**: Use `tearDown()` to clean up resources
6. **Test edge cases**: Include tests for error conditions, empty inputs, boundary values
7. **Keep tests fast**: Unit tests should run in milliseconds; save slower tests for integration suite

## Troubleshooting

### Elasticsearch connection issues

If integration tests fail with connection errors:
1. Ensure Elasticsearch is running: `curl http://localhost:9200`
2. Check the `ELASTICSEARCH_HOST` environment variable
3. Verify no port conflicts on 9200

### Memory issues

If tests fail with memory errors:
1. Increase PHP memory limit: `php -d memory_limit=1G vendor/bin/phpunit`
2. Check for memory leaks in your tests

### Mockery errors

If you see "Mockery\Exception\InvalidCountException":
1. Ensure you're using `MockeryPHPUnitIntegration` trait
2. Verify mock expectations match actual calls
3. Check that mocks are properly configured before use

## Contributing

When adding new features:
1. Write tests first (TDD approach recommended)
2. Ensure all tests pass: `composer test`
3. Verify code style: `composer php-cs-fixer-check`
4. Run static analysis: `composer phpstan`
5. Check coverage hasn't decreased: `composer test-coverage`

## Additional Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Mockery Documentation](http://docs.mockery.io/)
- [Symfony Testing Best Practices](https://symfony.com/doc/current/testing.html)
