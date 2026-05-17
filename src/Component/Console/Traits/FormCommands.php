<?php

declare(strict_types=1);

namespace Strux\Component\Console\Traits;

use Exception;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Strux\Component\Config\DirectoryInterface;
use Strux\Component\Model\Attributes\RelationAttribute;
use Strux\Support\ContainerBridge;

trait FormCommands
{
    private function createForm(string $name, array $options = []): void
    {
        try {
            $namespace = $options['namespace'] ?? 'App\\Http\\Form';
            $force = $options['force'] ?? false;
            $infer = $options['infer'] ?? null;
            $domain = $options['domain'] ?? 'Web';
            $excludeRaw = $options['exclude'] ?? '';
            $rules = $options['rules'] ?? null;
            $noSubmit = $options['no-submit'] ?? false;

            $className = basename(str_replace('.php', '', $name));

            $outputPath = $options['output'] ?? null;
            if ($outputPath === null) {
                $nsPath = str_replace('\\', '/', $namespace);
                if (str_starts_with($nsPath, 'App/')) {
                    $nsPath = substr($nsPath, 4);
                }
                $appDir = $this->getAppDir();
                $outputPath = rtrim($appDir, '/\\') . '/' . $nsPath . '/' . $className . '.php';
            }

            $this->ensureDirectoryExists($outputPath);

            if (file_exists($outputPath) && !$force) {
                echo "\033[31mConflict: $outputPath already exists. Use --force to overwrite.\033[0m\n";
                return;
            }

            $excludes = array_filter(array_map('trim', explode(',', (string) $excludeRaw)));

            $fields = [];
            $imports = [];

            if ($infer) {
                $modelClass = $this->resolveModelClass($infer, $domain);
                if ($modelClass === null) {
                    echo "\033[31mError: Model class '$infer' not found in domain '$domain'.\033[0m\n";
                    return;
                }

                $reflection = new ReflectionClass($modelClass);
                $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);

                foreach ($properties as $prop) {
                    if ($prop->isStatic()) {
                        continue;
                    }

                    if (!empty($prop->getAttributes(RelationAttribute::class, \ReflectionAttribute::IS_INSTANCEOF))) {
                        continue;
                    }

                    $propName = $prop->getName();
                    if (in_array($propName, $excludes)) {
                        continue;
                    }

                    $type = $prop->getType();
                    if (!$type instanceof ReflectionNamedType) {
                        continue;
                    }

                    $typeName = $type->getName();
                    $fieldClass = $this->inferFieldAttribute($propName, $typeName);

                    if ($fieldClass === null) {
                        continue;
                    }

                    $fieldRules = [];
                    if ($rules) {
                        $fieldRules = array_map('trim', explode(',', (string) $rules));
                    }

                    $label = $this->generateLabel($propName);

                    $fields[] = [
                        'name' => $propName,
                        'class' => $fieldClass,
                        'label' => $label,
                        'rules' => $fieldRules,
                    ];

                    $shortName = $this->getShortClassName($fieldClass);
                    if (!in_array($shortName, $imports)) {
                        $imports[] = $shortName;
                    }
                }

                echo "\033[32m✓\033[0m Inferred " . count($fields) . " fields from \033[36m$modelClass\033[0m\n";
            }

            if (!$noSubmit && !empty($fields)) {
                $fields[] = [
                    'name' => 'submit',
                    'class' => 'Strux\\Component\\Form\\Attributes\\SubmitField',
                    'label' => 'Submit',
                    'rules' => [],
                ];
                $shortName = 'SubmitField';
                if (!in_array($shortName, $imports)) {
                    $imports[] = $shortName;
                }
            }

            $importLines = '';
            if (!empty($imports)) {
                sort($imports);
                $importLines = implode("\n", array_map(fn($i) => "use Strux\\Component\\Form\\Attributes\\$i;", $imports));
            }

            $fieldsCode = '';
            if (empty($fields)) {
                $fieldsCode = <<<PHP
    // Define your fields here using attributes:
    // #[StringField(label: 'Name', rules: ['required'])]
    // protected string \$name;
PHP;
            } else {
                $fieldBlocks = [];
                foreach ($fields as $field) {
                    $attrClass = $field['class'];
                    $shortName = $this->getShortClassName($attrClass);
                    $label = $field['label'];
                    $rulesStr = '';
                    if (!empty($field['rules'])) {
                        $rulesInner = implode(', ', array_map(fn($r) => "'$r'", $field['rules']));
                        $rulesStr = ", rules: [$rulesInner]";
                    }
                    $fieldBlocks[] = <<<PHP
    #[{$shortName}(label: '$label'$rulesStr)]
    protected string \${$field['name']};
PHP;
                }
                $fieldsCode = implode("\n\n", $fieldBlocks);
            }

            $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Strux\\Component\\Form\\Form;
{$importLines}

class {$className} extends Form
{
{$fieldsCode}
}
PHP;

            file_put_contents($outputPath, $content);

            $relativePath = str_replace($this->getRootPath() . '/', '', $outputPath);
            echo "\033[32m✓\033[0m Form created: \033[36m$relativePath\033[0m\n";
            if ($infer) {
                echo "  → " . count($fields) . " fields inferred from \033[36m$infer\033[0m\n";
            }
            echo "  → Use: new {$className}(\$request) or \$this->forms->create({$className}::class)\n";

        } catch (Exception $e) {
            echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
        }
    }

    private function inferFieldAttribute(string $name, string $phpType): ?string
    {
        $lower = strtolower($name);

        if ($phpType === 'string') {
            if (str_contains($lower, 'email')) {
                return 'Strux\\Component\\Form\\Attributes\\EmailField';
            }
            if (str_contains($lower, 'password')) {
                return 'Strux\\Component\\Form\\Attributes\\PasswordField';
            }
            if (str_contains($lower, 'description') || str_contains($lower, 'content') || str_contains($lower, 'body')) {
                return 'Strux\\Component\\Form\\Attributes\\TextAreaField';
            }
            if (str_contains($lower, 'url') || str_contains($lower, 'website')) {
                return 'Strux\\Component\\Form\\Attributes\\URLField';
            }
            if (str_contains($lower, 'phone') || str_contains($lower, 'tel')) {
                return 'Strux\\Component\\Form\\Attributes\\TelField';
            }
            if (str_contains($lower, 'search') || str_contains($lower, 'query')) {
                return 'Strux\\Component\\Form\\Attributes\\SearchField';
            }
            return 'Strux\\Component\\Form\\Attributes\\StringField';
        }

        if ($phpType === 'int') {
            return 'Strux\\Component\\Form\\Attributes\\IntegerField';
        }

        if ($phpType === 'float') {
            return 'Strux\\Component\\Form\\Attributes\\DecimalField';
        }

        if ($phpType === 'bool') {
            return 'Strux\\Component\\Form\\Attributes\\BooleanField';
        }

        if ($phpType === 'DateTime') {
            return 'Strux\\Component\\Form\\Attributes\\DateTimeLocalField';
        }

        if ($phpType === 'array') {
            return 'Strux\\Component\\Form\\Attributes\\ListField';
        }

        return null;
    }

    private function resolveModelClass(string $name, string $domain): ?string
    {
        if (class_exists($name)) {
            return $name;
        }

        $candidates = [
            "App\\Domain\\{$domain}\\Entity\\{$name}",
            "App\\Domain\\Web\\Entity\\{$name}",
            "App\\Domain\\Identity\\Entity\\{$name}",
            "App\\Domain\\General\\Entity\\{$name}",
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function generateLabel(string $name): string
    {
        $label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
        $label = str_replace('_', ' ', $label);
        return ucwords($label);
    }

    private function getShortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }

    private function getAppDir(): string
    {
        try {
            $directories = $this->container->get(DirectoryInterface::class) ?? ContainerBridge::resolve(DirectoryInterface::class);
            return rtrim($directories->get('app'), '/\\');
        } catch (\Throwable $e) {
            return defined('ROOT_PATH') ? ROOT_PATH . '/src' : getcwd() . '/src';
        }
    }

    private function getRootPath(): string
    {
        return defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 5);
    }
}
