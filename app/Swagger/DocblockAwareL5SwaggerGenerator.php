<?php

namespace App\Swagger;

use Illuminate\Support\Arr;
use OpenApi\Analysers\AttributeAnnotationFactory;
use OpenApi\Analysers\DocBlockAnnotationFactory;
use OpenApi\Analysers\DocBlockParser;
use OpenApi\Analysers\ReflectionAnalyser;
use OpenApi\Generator as OpenApiGenerator;

class DocblockAwareL5SwaggerGenerator extends \L5Swagger\Generator
{
    protected function setAnalyser(OpenApiGenerator $generator): void
    {
        $analyser = Arr::get($this->scanOptions, self::SCAN_OPTION_ANALYSER);

        if (! empty($analyser)) {
            $generator->setAnalyser($analyser);

            return;
        }

        $factories = [
            new AttributeAnnotationFactory,
        ];

        if (DocBlockParser::isEnabled()) {
            $factories[] = new DocBlockAnnotationFactory;
        }

        $generator->setAnalyser(new ReflectionAnalyser($factories));
    }
}
