<?php

namespace ScoutElastic\Console;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

class IndexConfiguratorMakeCommand extends GeneratorCommand
{
    /**
     * {@inheritDoc}
     * @var string
     */
    protected $description = 'Create a new Elasticsearch index configurator';
    /**
     * {@inheritDoc}
     * @var string
     */
    protected $name = 'make:index-configurator';

    /**
     * {@inheritDoc}
     * @var string
     */
    protected $type = 'Configurator';

    /**
     * Build the class with the given name.
     *
     * @param string $name
     *
     * @throws FileNotFoundException
     */
    protected function buildClass($name): string
    {
        $stub = $this->files->get($this->getStub());

        return $this->replaceNamespace($stub, $name)
            ->replaceIndexName($stub, $name)
            ->replaceClass($stub, $name);
    }

    /**
     * {@inheritDoc}
     */
    protected function getStub(): string
    {
        return __DIR__ . '/stubs/index_configurator.stub';
    }

    protected function replaceIndexName(&$stub, $name): self
    {
        $indexName = Config::get('scout.prefix') . Str::snake(str_ireplace(['index', 'configurator'], '', class_basename($name)));

        $stub = str_replace('{{name}}', $indexName, $stub);

        return $this;
    }
}
