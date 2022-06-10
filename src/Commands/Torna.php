<?php

namespace Uniondrug\Docs\Commands;

use Uniondrug\Console\Command;
use Uniondrug\Docs\Parsers\Collection;

/**
 * 上传文档到Torna
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
        $this->toTorna($collection);
//        $this->asTorna($collection);
    }

    /**
     * 上传文档到Torna
     * @param Collection $collection
     */
    private function toTorna(Collection $collection)
    {
        $collection->toTorna();
    }

    /**
     * 保存torna.json
     * @param Collection $collection
     */
    private function asTorna(Collection $collection)
    {
        $contents = $collection->toTorna(true);
        $collection->saveMarkdown($collection->exportPath . '/' . $collection->publishPostmanTo, 'torna.json', $contents);
    }

}
