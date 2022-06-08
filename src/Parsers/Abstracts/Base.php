<?php

namespace Uniondrug\Docs\Parsers\Abstracts;

use Uniondrug\Docs\Parsers\Annotation;

/**
 * 解析基类
 * @package Uniondrug\Docs\Parsers\Abstracts
 */
abstract class Base
{
    /**
     * @var Annotation
     */
    public $annotation;
    /**
     * 换行符
     * @var string
     */
    public $crlf = "\n";
    public $eol = "\n\n";
    public $schema = '{{protocol}}';
    public $domain = '{{domain}}';
    public $port = '{{port}}';
    public $token = '{{token}}';
    public $console;

    public function __construct()
    {
        $this->console = new Console();
    }

    /**
     * 保存Markdown文档
     */
    public function saveMarkdown($path, $name, $contents)
    {
        $file = $path . '/' . $name;
        try {
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
            $fp = fopen($file, 'wb+');
            fwrite($fp, $contents);
            fclose($fp);
            $this->console->debug("导出到%s文件", $file);
        } catch (\Exception $e) {
            $this->console->error($e->getMessage());
            exit;
        }
    }
}
