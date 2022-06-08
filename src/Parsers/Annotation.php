<?php

namespace Uniondrug\Docs\Parsers;

use Uniondrug\Docs\Parsers\Abstracts\Base;

/**
 * 解析注释
 * @package Uniondrug\Docs\Parsers
 */
class Annotation extends Base
{
    /**
     * 标题
     * @var string
     */
    public $name = '';
    public $aliasName = null;
    /**
     * 描述
     * @var string
     */
    public $description = '';
    /**
     * 入参结构体
     * @var string
     */
    public $input = '';
    /**
     * 出参结构体
     * @var string
     */
    public $output = '';
    /**
     * 请求方式
     * @var string
     */
    public $method = '';
    public $isExecuted = false;
    public $isPostMethod = false;
    /**
     * 请求路径
     * @var string
     */
    public $path = '';
    /**
     * 路径前缀
     * @var string
     */
    public $prefix = '';
    /**
     * 类型名称
     * @var string
     */
    public $type = 'string';
    public $typeText = '';
    /**
     * @var Validator
     */
    public $validator = null;
    public $mock = '';
    public $ver = '';
    /**
     * 是否为数组类型
     * @var bool
     */
    public $isArrayType = false;
    public $isStructType = false;
    public $isIgnored = false;
    public $isSdk = false;
    public $sdkName = '';
    /**
     * @var bool|string
     */
    private $comment = false;
    private $reflect;
    private static $regexpAlias = "/@alias\s+([_a-z][_a-z0-9]*)/i";
    private static $regexpExec = "/@(exec)[^a-z]*/i";
    private static $regexpInfo = "/([^@]+)/i";
    private static $regexpInfoClears = [
        "/\/[\*]+/" => " *",
        "/\n\s+[\*]+/" => "\n*",
        "/\n\*/" => "\n"
    ];
    private static $regexpInput = "/@Input[ ]+(\S+)/i";
    private static $regexpOutput = "/@Output[ ]+(\S+)/i";
    private static $regexpMock = "/@Mock[ ]+([^\n]+)\n/i";
    private static $regexpPrefix = "/@RoutePrefix[ ]*\(([^\)]*)\)/i";
    private static $regexpRequest = "/@(Route|Get|Post|Put|Patch|Delete|Options|head)[ ]*\(([^\)]*)\)/i";
    private static $regexpSdk = "/@(Sdk)[ ]+([^\n]+)\n/i";
    private static $regexpIgnore = "/@(Ignore)[^a-z]*/i";
    private static $regexpType = "/@var[ ]+([_a-z0-9\\\]+)[ ]*([\[|\]| ]*)([^\n]*)\n/i";
    private static $regexpValidator = "/@validator[ ]*\(([^\)]*)\)/i";
    private static $regexpVersion = "/@version[ ]+([^\n]+)\n/i";

    /**
     * Annotation constructor.
     * @param \ReflectionClass|\ReflectionMethod|\ReflectionProperty $reflect
     */
    public function __construct($reflect)
    {
        parent::__construct();
        $this->reflect = $reflect;
        $comment = $reflect->getDocComment();
        if (is_string($comment) && $comment !== '') {
            $this->comment = "\n\n" . $comment . "\n\n";
        }
    }

    /**
     * 字段名的别名支持
     */
    public function alias()
    {
        // 1. not comment
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpAlias, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. info赋值
        $aliasName = trim($m[1]);
        if ($aliasName !== '') {
            $this->aliasName = $aliasName;
        }
    }

    public function ignored()
    {
        // 1. no comment
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpIgnore, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. export
        $this->isIgnored = true;
    }

    /**
     * 解析name/description
     */
    public function info()
    {
        // 1. not comment
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpInfo, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. info赋值
        $info = $this->formatInfo($m[1]);
        if (count($info) > 0) {
            $this->name = array_shift($info);
            $this->typeText = $this->name;
            if (count($info) > 0) {
                $this->description = implode($this->crlf, $info);
                $this->description = preg_replace("/\<[\/]?code\>/i", '```', $this->description);
            }
        }
    }

    /**
     * 解析input入参
     */
    public function input()
    {
        // 1. no comment
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpInput, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. 设置StructInterface
        $this->input = $this->formatStruct($m[1]);
    }

    /**
     * 解析output入参
     */
    public function output()
    {
        // 1. no comment
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpOutput, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. 设置StructInterface
        $this->output = $this->formatStruct($m[1]);
    }

    /**
     * 解析请求前缀prefix
     */
    public function prefix()
    {
        // 1. no comment
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpPrefix, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. 格式化
        $this->prefix = $this->formatPath($m[1]);
    }

    /**
     * 解析请求method/path
     */
    public function requeset()
    {
        // 1. no comment
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpRequest, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. 格式化
        $this->path = $this->formatPath($m[2]);
        $this->path === '' && $this->path = '/';
        $this->method = strtoupper($m[1]);
        $this->method === 'ROUTE' && $this->method = 'POST';
        $this->isPostMethod = in_array($this->method, [
            'DELETE',
            'POST',
            'PUT',
            'PATCH'
        ]);
    }

    /**
     * 测试单元
     */
    public function sdk()
    {
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpSdk, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. export
        $this->isSdk = true;
        $this->sdkName = trim($m[2]);
    }

    /**
     * 测试单元
     */
    public function test()
    {
        if ($this->comment === false) {
            return;
        }
    }

    /**
     * 类型定义
     */
    public function type()
    {
        // 1. not comment
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpType, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. parse
        $this->type = $m[1];
        $this->isArrayType = trim($m[2]) !== '';
        $this->isStructType = preg_match("/[A-Z]/", $this->type) > 0;
        // 4. reset name
        $m[3] = trim($m[3]);
        if ($m[3] !== '' && $this->typeText === '') {
            $this->typeText = $m[3];
        }
    }

    /**
     * 属性验证器
     */
    public function validator()
    {
        // 1. not comment
        if ($this->comment === false) {
            return;
        }
        if (preg_match(self::$regexpExec, $this->comment) > 0) {
            $this->isExecuted = true;
        }
        // 2. not defined
        preg_match(self::$regexpValidator, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. parse
        $this->validator = new Validator($m[1]);
    }

    /**
     * 解析Mock数据
     */
    public function mock()
    {
        // 1. not comment
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpMock, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. parse
        $this->mock = trim($m[1]);
    }

    public function version()
    {
        // 1. not comment
        if ($this->comment === false) {
            return;
        }
        // 2. not defined
        preg_match(self::$regexpVersion, $this->comment, $m);
        if (count($m) === 0) {
            return;
        }
        // 3. parse
        $this->ver = trim($m[1]);
    }

    /**
     * @param string $s
     * @return array
     */
    private function formatInfo($s)
    {
        foreach (self::$regexpInfoClears as $regexp => $replace) {
            $s = preg_replace($regexp, $replace, $s);
        }
        $a = [];
        foreach (explode("\n", $s) as $x) {
            if (trim($x) === '') {
                continue;
            }
            $a[] = $x;
        }
        return $a;
    }

    /**
     * 格式化路径
     * @param string $s
     * @return string
     */
    private function formatPath($s)
    {
        $s = preg_replace([
            "/'|\"/",
            "/[\/]+$/"
        ], [
            "",
            ""
        ], $s);
        $s = trim($s);
        if ($s !== '') {
            if ($s[0] !== '/') {
                $s = "/{$s}";
            }
        }
        return $s;
    }

    /**
     * @param string $s
     * @return string
     */
    private function formatStruct($s)
    {
        return $s;
    }
}
