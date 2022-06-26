<?php

namespace Uniondrug\Docs\Parsers;

use Uniondrug\Docs\Parsers\Abstracts\Base;

/**
 * 解析方法
 * @package Uniondrug\Docs\Parsers
 */
class Method extends Base
{
    /**
     * @var Collection
     */
    public $collection;
    /**
     * @var Controller
     */
    public $controller;
    /**
     * @var \ReflectionMethod
     */
    public $reflect;
    /**
     * @var Parameters
     */
    private $inputParameter = null;
    /**
     * @var Parameters
     */
    private $outputParameter = null;
    /**
     * 序列号
     */
    public static $sort = 1;

    /**
     * Method constructor.
     * @param Collection $collection
     * @param Controller $controller
     * @param \ReflectionMethod $reflect
     */
    public function __construct(Collection $collection, Controller $controller, \ReflectionMethod $reflect)
    {
        parent::__construct();
        $this->collection = $collection;
        $this->controller = $controller;
        $this->reflect = $reflect;
        $this->console->debug("发现%s动作", $this->reflect->name);
    }

    /**
     * 执行解析
     * @throws \Exception
     */
    public function parser()
    {
        // 1. 注解
        $this->annotation = new Annotation($this->reflect);
        $this->annotation->info();
        $this->annotation->requeset();
        $this->annotation->version();
        $this->annotation->sdk();
        $this->annotation->ignored();
        $this->annotation->input();
        $this->annotation->output();
        $this->annotation->test();
        if ($this->annotation->isIgnored) {
            throw new \Exception("动作{$this->reflect->class}::{$this->reflect->name}由@ignore约定, 忽略导出");
        }
        if ($this->annotation->isSdk) {
            $this->console->debug("动作%s::%s导出到SDK", $this->reflect->class, $this->reflect->name);
        }
        // 1. 补齐
        if ($this->annotation->name === '') {
            $this->annotation->name = $this->reflect->name . '()';
        }
        // 2. 入参
        if ($this->annotation->input !== '') {
            try {
                $this->inputParameter = new Parameters($this, $this->annotation->input, $this->controller->reflect->getNamespaceName());
            } catch (\Exception $e) {
                $this->console->error($e->getMessage());
            }
        }
        // 3. 出参
        if ($this->annotation->output !== '') {
            try {
                $this->outputParameter = new Parameters($this, $this->annotation->output, $this->controller->reflect->getNamespaceName());
            } catch (\Exception $e) {
                $this->console->error($e->getMessage());
            }
        }
    }

    /**
     * 导出Markdown文档
     */
    public function toMarkdown()
    {
        $text = '# ' . $this->annotation->name . $this->eol;
        $text .= $this->headerText();
        $text .= $this->eol . $this->sdkText();
        $text .= $this->eol . $this->inputText();
        $text .= $this->eol . $this->inputCode();
        $text .= $this->eol . $this->outputText();
        $text .= $this->eol . $this->outputCode();
        $text .= $this->eol;
        $text .= '### 编码对照表';
        $text .= $this->eol;
        $text .= $this->collection->getCodeMap();
        $name = str_replace('\\', '/', substr($this->controller->reflect->getName(), 16));
        $path = $this->collection->exportPath . '/' . $this->collection->publishTo . '/' . $name;
        $this->saveMarkdown($path, $this->reflect->getShortName() . '.md', $text);
        /**
         * 添加到SDK列表
         */
        if ($this->annotation->isSdk) {
            $this->controller->collection->sdkx->add($this->annotation->sdkName, $this->annotation->method, $this->controller->annotation->prefix . $this->annotation->path, $this->annotation->name, $this->annotation->description, $name . '/' . $this->reflect->getShortName() . '.md');
        }
    }

    /**
     * @return array
     */
    public function toPostman()
    {
        $data = [
            'name' => $this->annotation->name,
            'event' => $this->toPostmanEvent(),
            'request' => $this->toPostmanRequest(),
            'response' => $this->toPostmanResponse()
        ];
        return $data;
    }

    /**
     * Torna中的Action
     * @return array
     */
    public function toTorna()
    {
        $data = [
            "name" => trim($this->annotation->name),
            "description" => trim($this->annotation->aliasName),
            "httpMethod" => trim($this->annotation->method),
            "url" => trim($this->controller->annotation->prefix) . trim($this->annotation->path),
            "contentType" => "application/json;charset=utf-8",
            "isFolder" => 0,
            "isShow" => 1,
            "author" => '',
            "orderIndex" => self::$sort,
            "headerParams" => [],
            "requestParams" => $this->toTornaRequest(),
            "responseParams" => $this->toTornaResponse(),
            "errorCodeParams" => (array)$this->collection->getTornaCodeMap(),
            "isRequestArray" => 0,
            "isResponseArray" => 0,
            "extras" => new \stdClass(),
//            "extras" => [
//                "hasTpsDegrade" => "false",
//                "apiAccess" => "INS",
//                "apiStyle" => "Normal",
//                "hasTps" => "false",
//                "apiLevel" => "L2"
//            ],
        ];
        self::$sort++;
        return $data;
    }

    /**
     * Mss中的Action
     * @return array
     */
    public function toMss($controllerName)
    {
        return [
            "apiName" => trim($this->annotation->name) . "【{$controllerName}】",
            "method" => trim($this->annotation->method),
            "apiUrl" => trim($this->controller->annotation->prefix) . trim($this->annotation->path),
        ];
    }

    public function toPostmanEvent()
    {
        $exec = [];
        $exec[] = 'pm.test("' . $this->annotation->name . '", function(){';
        $exec[] = '    var json = pm.response.json();';
        $exec[] = '    pm.response.to.be.ok;';
        $exec[] = '    pm.expect("0").to.equal(json.errno);';
        $exec[] = '});';
        return [
            [
                'listen' => 'test',
                'script' => [
                    'id' => md5($this->controller->reflect->name . '::' . $this->reflect->name),
                    'type' => 'text/javascript',
                    'exec' => $exec
                ]
            ]
        ];
    }

    public function toPostmanRequest()
    {
        $data = ['method' => $this->annotation->method];
        // auth
        if ($this->collection->auth) {
            $data['auth'] = [
                'type' => 'bearer',
                'bearer' => [
                    [
                        'key' => 'token',
                        'value' => $this->token,
                        'type' => 'string'
                    ]
                ]
            ];
        }
        // url
        $data['url'] = [
            'raw' => $this->schema . '://' . $this->collection->host . '.' . $this->domain . ':' . $this->port . $this->controller->annotation->prefix . $this->annotation->path,
            'protocol' => $this->schema,
            'host' => explode('.', $this->collection->host . '.' . $this->domain),
            'port' => $this->port,
            'path' => explode('/', substr($this->controller->annotation->prefix . $this->annotation->path, 1))
        ];
        // post body
        if ($this->annotation->isPostMethod) {
            $body = '{}';
            if ($this->inputParameter !== null) {
                $body = json_encode($this->inputParameter->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
            $data['body'] = [
                'mode' => 'raw',
                'raw' => $body
            ];
        }
        // desc
        $data['description'] = $this->headerText() . $this->eol . $this->inputText() . $this->eol . $this->outputText() . $this->eol . $this->outputCode();
        $data['description'] .= $this->eol;
        $data['description'] .= '### 编码';
        $data['description'] .= $this->eol;
        $data['description'] .= $this->collection->getCodeMap();
        return $data;
    }

    /**
     * TornaAction入参结构体解析
     * @return array
     */
    public function toTornaRequest()
    {
        $data = [];
        // 未定义入参结构体
        if ($this->annotation->input === '') {
            return $data;
        }
        // 结构体错误
        if ($this->inputParameter === null) {
            $this->console->error('> 入参结构体 `' . $this->annotation->input . '` 不能正确解析');
            return $data;
        }
        // 解析
        return $this->inputParameter->toTorna(true);
    }

    /**
     * TornaAction出参结构体解析
     * @return array
     */
    public function toTornaResponse()
    {
        $data = [];
        // 未定义入参结构体
        if ($this->annotation->output === '') {
            return $data;
        }
        // 结构体错误
        if ($this->outputParameter === null) {
            $this->console->error('> 出参结构体 `' . $this->annotation->output . '` 不能正确解析');
            return $data;
        }
        // 解析
        return $this->toTornaResponseObject($this->outputParameter->toTorna(true));
    }

    private function toTornaResponseObject($body)
    {
        return [
            ["name" => "errno", "type" => "int", "example" => 0],
            ["name" => "error", "type" => "string", "example" => ""],
            ["name" => "dataType", "type" => "string", "example" => "OBJECT"],
            ["name" => "data", "type" => "object", "example" => 0, "children" => $body]
        ];
    }


    public function toPostmanResponse()
    {
        return [];
    }

    /**
     * 文档头信息
     * @return string
     */
    private function headerText()
    {
        // 1. title
        $text = ''; //'# '.$this->annotation->name;
        // 2. description
        if ($this->annotation->description !== '') {
            //$text .= $this->eol.'> '.$this->annotation->description;
            $text .= '> ' . $this->annotation->description;
        }
        // 3. tags
        $text .= $this->eol;
        $text .= '* **接口** : `' . $this->annotation->method . ' ' . $this->controller->annotation->prefix . $this->annotation->path . '`' . $this->crlf;
        if ($this->annotation->input !== '') {
            $text .= '* **入参** : `' . preg_replace("/^[^A-Z]/", "", $this->annotation->input) . '`' . $this->crlf;
        }
        if ($this->annotation->output !== '') {
            $text .= '* **出参** : `' . preg_replace("/^[^A-Z]/", "", $this->annotation->output) . '`' . $this->crlf;
        }
        $text .= '* **文件** : `' . $this->controller->filename . '`' . $this->crlf;
        $text .= '* **执行** : `' . $this->controller->reflect->name . '::' . $this->reflect->name . '()' . '`' . $this->crlf;
        $text .= '* **导出** : `' . date('Y-m-d H:i') . '`';
        // 4. 返回
        return $text;
    }

    private function sdkText()
    {
        // 1. 不导出SDK
        if (!$this->annotation->isSdk) {
            return '';
        }
        // 2. SDK用法
        $text = '### SDK' . $this->eol;
        // 2.1
        if ($this->collection->sdk !== '') {
            $text .= '**`一类用法`**' . $this->eol;
            $text .= '```' . $this->crlf;
            $text .= '// [推荐]推荐使用本用法' . $this->crlf;
            $text .= '//      不足之处, 需发布导出的[' . ucfirst($this->collection->sdk) . 'Sdk.php]文件到SDK项目下' . $this->crlf;
            $text .= '//      并创建release版本, 调用方执行composer update完成更新' . $this->crlf;
            $text .= '// SDK项目地址 https://github.com/uniondrug/service-sdk' . $this->crlf;
            $text .= '$body = [];' . $this->crlf;
            $text .= '$query = null;//Query数据' . $this->crlf;
            $text .= '$extra = null;//请求头信息' . $this->crlf;
            $text .= '$response = $this->serviceSdk->' . lcfirst($this->collection->sdkPath) . '->' . $this->collection->sdk . '->' . lcfirst($this->annotation->sdkName) . '($body, $query, $extra);' . $this->crlf;
            $text .= '```';
            $text .= $this->eol;
        }
        // 2.2
        $host = "{$this->controller->collection->host}://" . preg_replace("/^\/+/", '', $this->controller->annotation->prefix . $this->annotation->path);
        $text .= '_`二类用法`_' . $this->eol;
        $text .= '```' . $this->crlf;
        $text .= '// [慎用]不推荐' . $this->crlf;
        $text .= '//      该用法需要你知道域名前缀及路径路径, 同时不便于后期维护' . $this->crlf;
        $text .= '//      一经修改将有大量项目及文件(调用方)同步修改' . $this->crlf;
        $text .= '$body = [];' . $this->crlf;
        $text .= '$query = null;//Query数据' . $this->crlf;
        $text .= '$extra = null;//请求头信息' . $this->crlf;
        $text .= '$response = $this->serviceSdk->' . strtolower($this->annotation->method);
        $text .= '("' . $host . '", $body, $query, $extra);' . $this->crlf;
        $text .= '```' . $this->eol;
        // 2.3
        $text .= '*结果处理*' . $this->eol;
        $text .= '```' . $this->crlf;
        $text .= '// 任意一种用法拿到结果时' . $this->crlf;
        $text .= 'if ($response->hasError()){' . $this->crlf;
        $text .= '    // 执行错误逻辑;' . $this->crlf;
        $text .= '}' . $this->crlf;
        // 5. p3
        $text .= '// 执行正确逻辑' . $this->crlf;
        $text .= '// ...' . $this->crlf;
        $text .= '```' . $this->crlf;
        return $text;
    }

    /**
     * 入参Text文档
     */
    private function inputText()
    {
        $text = '';
        // 1. not defined
        if ($this->annotation->input === '') {
            return $text;
        }
        // 2. 格式化
        $text .= '### 入参' . $this->eol;
        // 3. 结构体错误
        if ($this->inputParameter === null) {
            $text .= '> 结构体 `' . $this->annotation->input . '` 不能正确解析';
            return $text;
        }
        // 4. 导出文档
        $text .= $this->inputParameter->toMarkdown(true);
        // 5. 返回结果
        return $text;
    }

    /**
     * 入参Code片段
     * @return string
     */
    private function inputCode()
    {
        $text = '';
        // 1. not defined
        if ($this->inputParameter === null) {
            return $text;
        }
        // 2. json string
        $text .= '*示例*' . $this->eol;
        $text .= '```' . $this->crlf;
        $text .= json_encode($this->inputParameter->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . $this->crlf;
        $text .= '```';
        return $text;
    }

    /**
     * 出参Text文档
     * @return string
     */
    private function outputText()
    {
        $text = '';
        // 1. not defined
        if ($this->annotation->output === '') {
            return $text;
        }
        // 2. 格式化
        $text .= '### 出参' . $this->eol;
        // 3. 结构体错误
        if ($this->outputParameter === null) {
            $text .= '> 结构体 `' . $this->annotation->output . '` 不能正确解析';
            return $text;
        }
        // 4. 导出文档
        $text .= $this->outputParameter->toMarkdown(false);
        // 5. 返回结果
        return $text;
    }

    /**
     * 出参Code片段
     * @return string
     */
    private function outputCode()
    {
        $text = '';
        // 1. not defined
        if ($this->outputParameter === null) {
            return $text;
        }
        // 2. json string
        $text .= '*示例*' . $this->eol;
        $text .= '```' . $this->crlf;
        $text .= json_encode($this->outputParameter->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . $this->crlf;
        $text .= '```';
        return $text;
    }
}