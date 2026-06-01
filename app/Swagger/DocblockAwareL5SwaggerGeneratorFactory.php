<?php

namespace App\Swagger;

use L5Swagger\ConfigFactory;
use L5Swagger\Exceptions\L5SwaggerException;
use L5Swagger\GeneratorFactory;
use L5Swagger\SecurityDefinitions;

class DocblockAwareL5SwaggerGeneratorFactory extends GeneratorFactory
{
    public function __construct(private readonly ConfigFactory $configFactory)
    {
        parent::__construct($configFactory);
    }

    /**
     * @throws L5SwaggerException
     */
    public function make(string $documentation): DocblockAwareL5SwaggerGenerator
    {
        $config = $this->configFactory->documentationConfig($documentation);

        $paths = $config['paths'];
        $scanOptions = $config['scanOptions'] ?? [];
        $constants = $config['constants'] ?? [];
        $yamlCopyRequired = $config['generate_yaml_copy'] ?? false;

        $secSchemesConfig = $config['securityDefinitions']['securitySchemes'] ?? [];
        $secConfig = $config['securityDefinitions']['security'] ?? [];

        $security = new SecurityDefinitions($secSchemesConfig, $secConfig);

        return new DocblockAwareL5SwaggerGenerator(
            $paths,
            $constants,
            $yamlCopyRequired,
            $security,
            $scanOptions
        );
    }
}
