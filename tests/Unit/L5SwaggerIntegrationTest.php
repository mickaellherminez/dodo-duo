<?php

declare(strict_types=1);

use App\Swagger\DocblockAwareL5SwaggerGenerator;
use App\Swagger\DocblockAwareL5SwaggerGeneratorFactory;
use L5Swagger\GeneratorFactory as L5SwaggerGeneratorFactory;

test('resolves custom l5-swagger generator factory binding', function () {
    $factory = app(L5SwaggerGeneratorFactory::class);

    expect($factory)->toBeInstanceOf(DocblockAwareL5SwaggerGeneratorFactory::class);
});

test('custom l5-swagger factory builds docblock-aware generator', function () {
    $factory = app(L5SwaggerGeneratorFactory::class);

    $generator = $factory->make((string) config('l5-swagger.default'));

    expect($generator)->toBeInstanceOf(DocblockAwareL5SwaggerGenerator::class);
});
