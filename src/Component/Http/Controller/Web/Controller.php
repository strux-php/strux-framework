<?php

declare(strict_types=1);

namespace Strux\Component\Http\Controller\Web;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Strux\Auth\AuthManager;
use Strux\Component\Form\FormFactory;
use Strux\Component\Http\Controller\BaseController;
use Strux\Component\Http\Request;
use Strux\Component\Session\SessionInterface;
use Strux\Component\View\ViewInterface;
use Strux\Support\Helpers\FlashInterface;

abstract class Controller extends BaseController
{
    protected array $models = [];
    protected ?string $defaultModelName = null;

    public function __construct(
        ?ContainerInterface $container = null,
        ?Request $request = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?PDO $db = null,
        ?SessionInterface $session = null,
        ?LoggerInterface $logger = null,
        ?ViewInterface $view = null,
        ?EventDispatcherInterface $event = null,
        ?FlashInterface $flash = null,
        ?AuthManager $authManager = null,
        ?FormFactory $forms = null,
    ) {
        parent::__construct(
            $container,
            $request,
            $responseFactory,
            $db,
            $session,
            $logger,
            $view,
            $event,
            $flash,
            $authManager,
            $forms
        );
        $this->determineDefaultModelName();
    }

    /**
     * Determines the default model name based on the controller's name.
     */
    protected function determineDefaultModelName(): void
    {
        $controllerName = (new ReflectionClass($this))->getShortName();
        $this->defaultModelName = str_replace('Controller', '', $controllerName);
    }

    // The model() method for getting a specific data-holding instance is no longer needed
    // if all interaction starts with a static query() or a magic property.
    // If you still need it for some reason, it can remain. For now, we'll rely on __get.


    /**
     * Magic getter for lazy-loading models or starting a query on the default model.
     * Accessing `$this->model` will return `ModelName::query()`.
     * Accessing `$this->SomeModel` will attempt to load `App\Models\SomeModel`.
     */
    public function __get(string $name)
    {
        /* if ($name === 'forms') {
            return $this->forms;
        } */

        // Handle accessing the default model via `$this->model`
        if ($name === 'model') {
            if ($this->defaultModelName === null) {
                throw new RuntimeException("Default model name could not be determined for " . static::class);
            }
            $modelClass = 'App\\Models\\' . ucfirst($this->defaultModelName);
            if (!class_exists($modelClass)) {
                throw new RuntimeException("Default model class '$modelClass' not found for controller " . static::class);
            }
            // Return a NEW, clean query builder instance for the default model.
            return $modelClass;
        }

        // Handle accessing other models like `$this->User` which will be cached
        if (isset($this->models[$name])) {
            return $this->models[$name];
        }

        // Attempt to load and cache the requested model instance
        $modelClass = 'App\\Models\\' . ucfirst($name);
        if (class_exists($modelClass)) {
            // Instantiate and cache it as a data-holding object.
            $modelInstance = new $modelClass();
            $this->models[$name] = $modelInstance;
            return $modelInstance;
        }

        throw new RuntimeException("Undefined property or model: $name in " . static::class);
    }
}
