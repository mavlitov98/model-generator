<?php

declare(strict_types=1);

namespace ModelGenerator;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Functional\every;
use function Functional\first;
use function Functional\map;
use function Functional\some;

final class ModelGeneratorCommand extends Command
{
    public static function getDefaultName(): string
    {
        return 'cli:json-to-php-class';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if (!file_exists(__DIR__ . '/Input/meta.json')) {
                throw new RuntimeException('Please create meta.json file with settings in `ModelGenerator/Input` and try again.');
            }

            if (!file_exists(__DIR__ . '/Input/payload.json')) {
                throw new RuntimeException('Please create payload.json file with data in `ModelGenerator/Input` and try again.');
            }

            /** @var array{name: string, namespace: string} $meta */
            $meta = json_decode(file_get_contents(__DIR__ . '/Input/meta.json'), true);
            $payload = json_decode(file_get_contents(__DIR__ . '/Input/payload.json'), true);

            if (!is_array($payload) || some($payload, fn($_, $key) => !is_string($key))) {
                $output->writeln('Unsupported json!');
                return 1;
            }

            self::newClass($meta, $payload);
        } catch (Throwable $e) {
            $output->writeln("Exception: {$e->getMessage()}");

            return 1;
        }

        return 0;
    }

    /**
     * @param array{name: string, namespace: string} $meta
     */
    private static function newClass(array $meta, array $payload, ?string $subClassName = null): void
    {
        $forToArray = [];
        $addToArrayCall = [];
        $addMapCall = [];
        $classParts = [];

        foreach ($payload as $field => $value) {
            $phpProperty = self::phpProperty($field);
            $forToArray[$field] = $phpProperty;

            switch (true) {
                case is_null($value):
                    $classParts[] = "    public ?string \${$phpProperty} = null;\n";
                    break;
                case is_bool($value):
                    $classParts[] = "    public bool \${$phpProperty} = false;\n";
                    break;
                case is_string($value):
                    $classParts[] = "    public string \${$phpProperty} = '';\n";
                    break;
                case is_int($value):
                    $classParts[] = "    public int \${$phpProperty} = 0;\n";
                    break;
                case is_float($value):
                    $classParts[] = "    public float \${$phpProperty} = 0.00;\n";
                    break;
                case is_array($value):

                    // {"f1": 1}
                    if (every($value, fn($v, $k) => is_string($k)) && !empty($value)) {
                        self::newClass($meta, $value, $phpProperty);

                        $className = self::toCamelCase(sprintf('%s%s', $meta['name'], ucfirst($phpProperty)));
                        $classParts[] = "    public {$className} \${$phpProperty};\n";

                        $addToArrayCall[$phpProperty] = $className;
                    }

                    // [{"f1": 1}, {"f1": 1}, {"f1": 1}]
                    elseif (every($value, fn($v) => is_array($v)) && array_key_exists(0, $value)) {
                        self::newClass($meta, first($value), $phpProperty);

                        $className = self::toCamelCase(sprintf('%s%s', $meta['name'], ucfirst($phpProperty)));
                        $classParts[] = "\n    /** @var list<{$className}> \${$phpProperty} */\n";
                        $classParts[] = "    public array \${$phpProperty} = [];\n";

                        $addMapCall[$phpProperty] = $className;
                    }

                    // [1, 2, 3] list<int> or []
                    else {
                        $type = empty($value) ? 'mixed' : 'int';
                        $classParts[] = "\n    /** @var list<{$type}> \${$phpProperty} */\n";
                        $classParts[] = "    public array \${$phpProperty} = [];\n";
                    }
            };
        }

        $classTemplate = implode('', [
            self::openClass($meta, $subClassName, !empty($addMapCall)),
            ...$classParts,
        ]);

        self::closeClass($meta, self::addToArrayMethod($forToArray, $classTemplate, $addToArrayCall, $addMapCall), $subClassName);
    }

    /**
     * @param array{name: string, namespace: string} $meta
     */
    private static function openClass(array $meta, ?string $subClassName, bool $addMapImport): string
    {
        $className = null === $subClassName
            ? $meta['name']
            : self::toCamelCase(sprintf('%s%s', $meta['name'], ucfirst($subClassName)));

        $withMapImport = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$meta['namespace']};

        use function Functional\map;

        final class {$className}
        {\n
        PHP;

        $withoutMapImport = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$meta['namespace']};

        final class {$className}
        {\n
        PHP;

        return $addMapImport
            ? $withMapImport
            : $withoutMapImport;
    }

    /**
     * @param array{name: string, namespace: string} $meta
     */
    private static function closeClass(array $meta, string $classTemplate, ?string $subClassName = null): void
    {
        $classTemplate .= "}\n";
        $className = null === $subClassName
            ? $meta['name']
            : self::toCamelCase(sprintf('%s%s', $meta['name'], ucfirst($subClassName)));

        file_put_contents(__DIR__."/Output/{$className}.php", $classTemplate);
    }

    /**
     * @param list<string, string> $forToArray
     * @param list<string, string> $addToArrayCall
     * @param list<string, string> $addMapToArrayCall
     */
    private static function addToArrayMethod(array $forToArray, string $classTemplate, array $addToArrayCall, array $addMapToArrayCall): string
    {
        $values = array_values(
            map(
                $forToArray,
                function (string $camelCase, string $original) use ($addToArrayCall, $addMapToArrayCall) {
                    if (array_key_exists($camelCase, $addToArrayCall)) {
                        return "            '{$original}' => \$this->{$camelCase}->toArray(),";
                    }

                    if (array_key_exists($camelCase, $addMapToArrayCall)) {
                        return "            '{$original}' => map(\$this->{$camelCase}, fn({$addMapToArrayCall[$camelCase]} \$i) => \$i->toArray()),";
                    }

                    return "            '{$original}' => \$this->{$camelCase},";
                },
            ),
        );

        $imploded = implode("\n", $values);
        $classTemplate .= <<<PHP

            public function toArray(): array
            {
                return [
        {$imploded}
                ];
            }\n
        PHP;

        return $classTemplate;
    }

    private static function toCamelCase(string $string): string
    {
        $camelCase = '';

        $string = str_replace('-', ' ', $string);
        $string = str_replace('_', ' ', $string);
        $exploded = explode(' ', $string);

        if (1 === count($exploded)) {
            return first($exploded);
        }

        $camelCase .= strtolower(first($exploded));
        unset($exploded[0]);

        foreach ($exploded as $word) {
            $camelCase .= ucwords(strtolower($word));
        }

        return $camelCase;
    }

    private static function phpProperty(string $field): string
    {
        $camelCased = self::toCamelCase($field);

        if (preg_match('~^[A-Z][A-Z].+$~', $camelCased)) {
            return $camelCased;
        }

        $firstAlpha = substr($camelCased, 0, 1);

        return ctype_upper($firstAlpha)
            ? strtolower($firstAlpha).substr($camelCased, 1)
            : $camelCased;
    }
}
