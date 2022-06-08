<?php

namespace Uniondrug\Docs\Commands;

use Uniondrug\Console\Command;
use Uniondrug\Docs\Parsers\Collection;

/**
 * 上传文档到torna
 * Class Torna
 * @package Uniondrug\Docs\Commands
 */
class Torna extends Command
{
    protected $signature = 'torna';

    public $exportPath = '';

    /**
     * @inheritdoc
     */
    public function handle()
    {
        $path = getcwd();
        $this->exportPath = $this->exportPath ?: $path;
        $collection = new Collection($path, $this->exportPath);
        $collection->parser();
        $this->asTorna($collection);
        $this->toTorna($collection);
    }

    private function asTorna(Collection $collection)
    {
        $contents = $collection->toTorna();
        $collection->saveMarkdown($collection->exportPath . '/' . $collection->publishPostmanTo, 'torna.json', $contents);
    }

    private function toTorna(Collection $collection)
    {
        $collection->console->info("结束");
    }
}
