<?php

namespace Uniondrug\Docs\Commands;

use Uniondrug\Console\Command;
use Uniondrug\Docs\Parsers\Collection;

/**
 * 导出POSTMAN格式的API文档
 * @package Uniondrug\Docs\Commands
 */
class Postman extends Command
{
    protected $signature = 'postman
                            {--mode=api : 发布markdown文档}
                            {--path=docs/api : markdown文档存储位置}';

    public $exportPath = '';
    public $publishTo = 'docs/api';
    public $publishPostmanTo = 'docs';

    /**
     * @inheritdoc
     */
    public function handle()
    {
        $path = getcwd();
        $this->exportPath = $this->exportPath ?: $path;
        $collection = new Collection($path, $this->exportPath);
        $collection->publishTo = $this->publishTo;
        $collection->publishPostmanTo = $this->publishPostmanTo;
        $collection->parser();
        $this->asMarkdown($collection);
        $this->asPostman($collection);
    }

    private function asMarkdown(Collection $collection)
    {
        $collection->toMarkdown();
    }

    private function asPostman(Collection $collection)
    {
        $contents = $collection->toPostman();
        $collection->saveMarkdown($collection->exportPath . '/' . $collection->publishPostmanTo, 'postman.json', $contents);
    }
}
