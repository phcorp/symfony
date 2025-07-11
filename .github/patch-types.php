<?php

use PHPUnit\Framework\TestCase;

$mode = $argv[1] ?? 'patch';
if ('lint' !== $mode && false === getenv('SYMFONY_PATCH_TYPE_DECLARATIONS')) {
    echo "Please define the SYMFONY_PATCH_TYPE_DECLARATIONS env var when running this script.\n";
    exit(1);
}

require __DIR__.'/../.phpunit/phpunit/vendor/autoload.php';

$loader = require __DIR__.'/../vendor/autoload.php';

Symfony\Component\ErrorHandler\DebugClassLoader::enable();

$missingReturnTypes = [];
foreach ($loader->getClassMap() as $class => $file) {
    $file = realpath($file);

    switch (true) {
        case false !== strpos($file, '/src/Symfony/Component/Cache/Traits/Redis'):
        case false !== strpos($file, '/src/Symfony/Component/Cache/Traits/Relay'):
            if (!str_ends_with($file, 'Proxy.php')) {
                break;
            }
            continue 2;
        case false !== strpos($file, '/vendor/'):
        case false !== strpos($file, '/src/Symfony/Bridge/Doctrine/Middleware/Debug/'):
        case false !== strpos($file, '/src/Symfony/Bridge/Doctrine/Tests/Fixtures/LegacyQueryMock.php'):
        case false !== strpos($file, '/src/Symfony/Bridge/PhpUnit/'):
        case false !== strpos($file, '/src/Symfony/Bundle/FrameworkBundle/Tests/Fixtures/Validation/Article.php'):
        case false !== strpos($file, '/src/Symfony/Component/Config/Tests/Fixtures/BadFileName.php'):
        case false !== strpos($file, '/src/Symfony/Component/Config/Tests/Fixtures/BadParent.php'):
        case false !== strpos($file, '/src/Symfony/Component/Config/Tests/Fixtures/ParseError.php'):
        case false !== strpos($file, '/src/Symfony/Component/DependencyInjection/Dumper/PhpDumper.php'):
        case false !== strpos($file, '/src/Symfony/Component/DependencyInjection/Tests/Compiler/OptionalServiceClass.php'):
        case false !== strpos($file, '/src/Symfony/Component/DependencyInjection/Tests/Fixtures/includes/autowiring_classes.php'):
        case false !== strpos($file, '/src/Symfony/Component/DependencyInjection/Tests/Fixtures/includes/compositetype_classes.php'):
        case false !== strpos($file, '/src/Symfony/Component/DependencyInjection/Tests/Fixtures/includes/intersectiontype_classes.php'):
        case false !== strpos($file, '/src/Symfony/Component/DependencyInjection/Tests/Fixtures/includes/MultipleArgumentsOptionalScalarNotReallyOptional.php'):
        case false !== strpos($file, '/src/Symfony/Component/DependencyInjection/Tests/Fixtures/ParentNotExists.php'):
        case false !== strpos($file, '/src/Symfony/Component/DependencyInjection/Tests/Fixtures/Preload/'):
        case false !== strpos($file, '/src/Symfony/Component/DependencyInjection/Tests/Fixtures/Prototype/BadClasses/MissingParent.php'):
        case false !== strpos($file, '/src/Symfony/Component/DependencyInjection/Tests/Fixtures/php/'):
        case false !== strpos($file, '/src/Symfony/Component/ErrorHandler/Tests/Fixtures/'):
        case false !== strpos($file, '/src/Symfony/Component/HttpClient/Internal/') && str_contains($file, 'V5'):
        case false !== strpos($file, '/src/Symfony/Component/PropertyAccess/Tests/Fixtures/AsymmetricVisibility.php'):
        case false !== strpos($file, '/src/Symfony/Component/PropertyInfo/Tests/Fixtures/'):
        case false !== strpos($file, '/src/Symfony/Component/Runtime/Internal/ComposerPlugin.php'):
        case false !== strpos($file, '/src/Symfony/Component/Security/Http/Tests/Fixtures/IsGrantedAttributeMethodsWithClosureController.php'):
        case false !== strpos($file, '/src/Symfony/Component/Security/Http/Tests/Fixtures/IsGrantedAttributeWithClosureController.php'):
        case false !== strpos($file, '/src/Symfony/Component/Serializer/Tests/Fixtures/'):
        case false !== strpos($file, '/src/Symfony/Component/Serializer/Tests/Normalizer/Features/ObjectOuter.php'):
        case false !== strpos($file, '/src/Symfony/Component/Validator/Tests/Constraints/Fixtures/WhenTestWithClosure.php'):
        case false !== strpos($file, '/src/Symfony/Component/Validator/Tests/Fixtures/NestedAttribute/Entity.php'):
        case false !== strpos($file, '/src/Symfony/Component/VarDumper/Tests/Fixtures/NotLoadableClass.php'):
        case false !== strpos($file, '/src/Symfony/Component/VarDumper/Tests/Fixtures/VirtualProperty.php'):
        case false !== strpos($file, '/src/Symfony/Component/VarExporter/Internal'):
        case false !== strpos($file, '/src/Symfony/Component/VarExporter/Tests/Fixtures/'):
        case false !== strpos($file, '/src/Symfony/Contracts/'):
            continue 2;
    }

    class_exists($class);

    if ('lint' !== $mode || is_subclass_of($class, TestCase::class)) {
        continue;
    }

    $refl = new \ReflectionClass($class);
    foreach ($refl->getMethods() as $method) {
        if (
            $method->getReturnType()
            || (str_contains($method->getDocComment(), '@return') && str_contains($method->getDocComment(), 'resource'))
            || '__construct' === $method->getName()
            || '__destruct' === $method->getName()
            || '__clone' === $method->getName()
            || $method->getDeclaringClass()->getName() !== $class
            || str_contains($method->getDeclaringClass()->getName(), '\\Tests\\')
            || str_contains($method->getDeclaringClass()->getName(), '\\Test\\') && str_starts_with($method->getName(), 'test')
        ) {
            continue;
        }

        $missingReturnTypes[] = $class.'::'.$method->getName();
    }
}

if ($missingReturnTypes) {
    echo \count($missingReturnTypes)." missing return types on interfaces\n\n";
    echo implode("\n", $missingReturnTypes);
    echo "\n";
    exit(1);
}

if ('patch' === $mode) {
    Symfony\Component\ErrorHandler\DebugClassLoader::checkClasses();
}
