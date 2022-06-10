<?php

namespace Uniondrug\Docs\Parsers;

use Uniondrug\Docs\Parsers\Abstracts\Base;

/**
 * 解析控制器
 * @package Uniondrug\Docs\Parsers
 */
class Controller extends Base
{
    /**
     * @var Collection
     */
    public $collection;
    /**
     * @var \ReflectionClass
     */
    public $reflect;
    /**
     * @var Method[]
     */
    public $methods = [];
    public $filename;

    /**
     * 序列号
     */
    public static $sort = 1;

    /**
     * Controller constructor.
     * @param Collection $collection
     * @param string $class 控制器类名
     */
    public function __construct(Collection $collection, string $class)
    {
        parent::__construct();
        $this->collection = $collection;
        $this->reflect = new \ReflectionClass($class);
        $this->filename = substr($this->reflect->getFileName(), strlen($collection->basePath) + 1);
        $this->console->info("发现%s控制器", $this->reflect->name);
    }

    /**
     * 执行解析
     */
    public function parser()
    {
        // 1. 注解
        $this->annotation = new Annotation($this->reflect);
        $this->annotation->info();
        $this->annotation->prefix();
        // 2.1 title
        if ($this->annotation->name === '') {
            $this->annotation->name = $this->reflect->getShortName();
        }
        // 2.2 description
        if ($this->annotation->description !== '') {
            $this->annotation->description .= $this->eol;
        }
        // 3. 方法
        foreach ($this->reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $reflect) {
            // 2.1 not belong
            if ($reflect->class !== $this->reflect->name) {
                continue;
            }
            // 2.2 not action
            if (!preg_match("/^[_a-zA-Z0-9]+Action$/", $reflect->name)) {
                continue;
            }
            // 2.3 execute
            try {
                $method = new Method($this->collection, $this, $reflect);
                $method->parser();
                $this->methods[] = $method;
            } catch (\Exception $e) {
                $this->console->error($e->getMessage());
            }
        }
    }

    /**
     * 读取索引
     * @return string
     */
    public function getIndex($curr = false)
    {
        $text = '';
        $comma = '';
        $space = $curr ? '' : '    ';
        $url = str_replace('\\', '/', substr($this->reflect->getName(), 16));
        $prefix = './' . ($curr ? '' : $url . '/');
        foreach ($this->methods as $method) {
            $name = trim($method->annotation->name);
            $desc = trim(preg_replace("/\n/", " ", trim($method->annotation->description)));
            $text .= $comma . $space . '* [' . $name . '](' . $prefix . $method->reflect->getShortName() . '.md)';
            if ($method->annotation->ver !== '') {
                $text .= " `{$method->annotation->ver}` ";
            }
            if ($method->annotation->isSdk) {
                $text .= " `SDK` ";
            }
            if ($desc !== '') {
                $text .= ' : ' . $desc;
            }
            $comma = $this->crlf;
        }
        return $text;
    }

    /**
     * 导出README.md文档
     * 存为Markdown索引文档同时触发接口
     */
    public function toMarkdown()
    {
        $name = str_replace('\\', '/', substr($this->reflect->getName(), 16));
        $path = $this->collection->exportPath . '/' . $this->collection->publishTo . '/' . $name;
        $count = count($this->methods);
        if ($count === 0) {
            $this->console->warning("控制器{$this->reflect->getName()}无可导出动作, 忽略导出");
            return;
        }
        // 1. title
        $text = '# ' . $this->annotation->name;
        // 2. description
        $desc = $this->annotation->description;
        if ($desc !== '') {
            $text .= $this->eol . '> ' . $desc;
        }
        // 3. information
        $text .= $this->eol;
        $text .= "* **接口** : `" . $count . "` 个" . $this->crlf;
        $text .= "* **前缀** : `{$this->annotation->prefix}`" . $this->crlf;
        $text .= "* **类名** : `{$this->reflect->name}`" . $this->crlf;
        $text .= "* **文件** : `{$this->filename}`";
        // 4. index
        $text .= $this->eol;
        $text .= '### 接口列表';
        $text .= $this->eol;
        $text .= $this->getIndex(true);
        // 5. code map
        $text .= $this->eol;
        $text .= '### 编码对照表';
        $text .= $this->eol;
        $text .= $this->collection->getCodeMap();
        // 6. save README.md
        $this->saveMarkdown($path, 'README.md', $text);
        // 7. 触发API
        foreach ($this->methods as $method) {
            $method->toMarkdown();
        }
    }

    /**
     * 转为POSTMAN文件
     * @return array
     */
    public function toPostman()
    {
        $description = '';
        $description .= "* **接口** : `" . count($this->methods) . "` 个" . $this->crlf;
        $description .= "* **前缀** : `{$this->annotation->prefix}`" . $this->crlf;
        $description .= "* **类名** : `{$this->reflect->name}`" . $this->crlf;
        $description .= "* **文件** : `{$this->filename}`";
        $data = [
            'name' => $this->annotation->name,
            'description' => $this->annotation->description . $description,
            'item' => []
        ];
        foreach ($this->methods as $method) {
            $data['item'][] = $method->toPostman();
        }
        return $data;
    }

    /**
     * 构建Torna中apis的folder(Controller)
     * @return array
     */
    public function toTorna()
    {
        $data = [
            'name' => trim($this->annotation->name),
            'isFolder' => 1,
            'author' => '',
            'orderIndex' => self::$sort,
            'items' => [],
            'extras' => new \stdClass()
        ];
        foreach ($this->methods as $method) {
            $data['items'][] = $method->toTorna();
        }
        self::$sort++;
        $method::$sort = 1;
        return $data;
    }
}