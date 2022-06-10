<?php

namespace Uniondrug\Docs\Parsers;

use Uniondrug\Structs\Struct;

/**
 * 参数解析
 * @package Uniondrug\Docs\Parsers
 */
class Parameters
{
    /**
     * @var bool
     */
    public $isArray = false;
    /**
     * @var Property[]
     */
    public $properties = [];
    /**
     * @var Parameters[]
     */
    public $children = [];
    /**
     * @var Method
     */
    public $method;
    public $reflect;
    private static $inputColumns = [
        'required' => '必须',
        'type' => '类型',
        'name' => '字段',
        'value' => '默认',
        'filterType' => '入参格式',
        'filterOption' => '入参要求',
        'desc' => '描述'
    ];
    private static $outputColumns = [
        'type' => '类型',
        'name' => '字段',
        'desc' => '描述'
    ];
    private static $depth = [];

    /**
     * @param string $sn Struct Class Name
     * @param string $ns Namespace
     * @throws \Exception
     */
    public function __construct(Method $method, $sn, $ns)
    {
        $this->method = $method;
        // 1. explain
        $re = "/(\S+)\s*([\[|\]|\s]*)$/";
        preg_match($re, $sn, $m);
        if (count($m) === 0) {
            throw new \Exception("invalid @Input/@Output value");
        }
        // 2. is array
        $this->isArray = trim($m[2]) !== '';
        // 3. class name
        $cn = trim($m[1]);
        if ($cn[0] !== '\\') {
            $cn = '\\' . $ns . '\\' . $cn;
        }
        // 4. reflect
        $reflect = new \ReflectionClass($cn);
        $this->reflect = $reflect;
        foreach ($reflect->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED) as $p) {
            if (!is_a($p->class, Struct::class, true)) {
                continue;
            }
            // 4.1 new Property
            $struct = new $cn([], false);
            $property = new Property($method, $struct, $p);
            if ($property->annotation->isStructType) {
                if (!isset(self::$depth[$cn])) {
                    self::$depth[$cn] = 0;
                }
                self::$depth[$cn]++;
                if (self::$depth[$cn] >= 30) {
                    break;
                }
                $this->children[$p->name] = new Parameters($method, $property->annotation->type, $reflect->getNamespaceName());
            }
            // 4.2 children
            $this->properties[$p->name] = $property;
        }
    }

    /**
     * @param boolean $mock 是否填充MOCK数据
     * @return array
     */
    public function toArray($mock = true)
    {
        $data = [];
        foreach ($this->properties as $property) {
            // 1. 忽略计算属性
            if ($property->annotation->isExecuted) {
                continue;
            }
            if ($property->annotation->isStructType) {
                $p = $this->children[$property->name];
                if ($property->annotation->isArrayType) {
                    $data[$property->name] = [$p->toArray($mock)];
                } else {
                    $data[$property->name] = $p->toArray($mock);
                }
            } else {
                $value = $property->annotation->mock;
                if ($value === '') {
                    $value = $property->defaultValue();
                }
                $data[$property->name] = $property->annotation->isArrayType ? [$value] : $value;
            }
        }
        return $this->isArray ? [$data] : $data;
    }

    /**
     * @return string
     */
    public function toMarkdown($input = false)
    {
        // 1. base level
        $text = '> ' . $this->reflect->name . $this->method->eol;
        $text .= $this->thead($input) . $this->method->crlf;
        foreach ($this->properties as $property) {
            if ($property->annotation->isExecuted) {
                continue;
            }
            $text .= $this->tbody($input, $property) . $this->method->crlf;
        }
        // 2. nest level
        foreach ($this->children as $child) {
            $text .= $this->method->eol;
            $text .= $child->toMarkdown($input);
        }
        // 3. result
        return $text;
    }

    /**
     * TornaAction入参解析
     * @param bool $input
     * @return array
     */
    public function toTorna($input = false)
    {
        try {
            $data = $children = [];
            foreach ($this->properties as $p => $property) {
//                if ($property->annotation->isExecuted) {
//                    continue;
//                }
                $type = trim($property->annotation->type ?? '');
                $property->annotation->isStructType && $type = 'object';
                $property->annotation->isArrayType && $type = 'list';
                $_data = [
                    "name" => trim($property->name ?? ''),
                    "type" => $type,
                    "required" => $property->annotation->validator->required ?? false,
                    "maxLength" => $property->annotation->validator->options ?? '',
                    "example" => trim($property->annotation->mock ?? ''),
                    "description" => trim($property->annotation->name ?? ''),
                    "children" => $children
                ];
                // 子级递归
                if (!empty($this->children[$p])) {
                    $_data['children'] = $this->children[$p]->toTorna($input);
                }
                $data[] = $_data;
            }
        } catch (\Exception $e) {
        } finally {
            return $data;
        }
    }

    private function thead($input = false)
    {
        $columns = $input ? self::$inputColumns : self::$outputColumns;
        $separators = [];
        // 1. th
        $text = '|';
        foreach ($columns as $column) {
            $text .= ' ' . $column . ' |';
            $separators[] = ':--';
        }
        $text .= $this->method->crlf;
        // 2. separator
        $text .= '| ' . implode(' | ', $separators) . ' |';
        // 3. table header
        return $text;
    }

    /**
     * @param boolean $input
     * @param Property $property
     * @return string
     */
    private function tbody($input, $property)
    {
        $text = '|';
        $columns = $input ? self::$inputColumns : self::$outputColumns;
        foreach ($columns as $tag => $_) {
            $text .= ' ' . $this->tbodyTags($property, $tag) . ' |';
        }
        return $text;
    }

    /**
     * 标签过滤
     * @param Property $property
     * @param string $tag
     * @return string
     */
    private function tbodyTags($property, $tag)
    {
        $m = 'with' . ucfirst($tag);
        if (method_exists($this, $m)) {
            return $this->{$m}($property);
        }
        return '';
    }

    /**
     * @param Property $property
     * @return string
     */
    protected function withRequired($property)
    {
        $required = $property->annotation->validator !== null && $property->annotation->validator->required;
        return $required ? '是' : '';
    }

    /**
     * @param Property $property
     * @return string
     */
    protected function withName($property)
    {
        $name = $property->name;
        if ($property->annotation->aliasName !== null && $property->annotation->aliasName !== '') {
            $name .= '<br />';
            $name .= '别名: ' . $property->annotation->aliasName;
        }
        return $name;
    }

    /**
     * @param Property $property
     * @return string
     */
    protected function withType($property)
    {
        $type = $property->annotation->type;
        if ($property->annotation->isArrayType) {
            $type .= '[]';
        }
        if ($property->annotation->isStructType) {
            return '`' . $type . '`';
        }
        return $type;
    }

    /**
     * @param Property $property
     * @return string
     */
    protected function withFilterType($property)
    {
        if ($property->annotation->validator !== null && $property->annotation->validator->type !== '') {
            return $property->annotation->validator->type;
        }
        return $this->withType($property);
    }

    /**
     * @param Property $property
     * @return string
     */
    protected function withFilterOption($property)
    {
        if ($property->annotation->validator !== null && $property->annotation->validator->options !== '') {
            return $property->annotation->validator->options;
        }
        return '';
    }

    /**
     * @param Property $property
     * @return string
     */
    protected function withDesc($property)
    {
        $desc = $property->annotation->typeText;
        if ($property->annotation->description !== '') {
            $desc .= '<br />' . preg_replace("/\n/", "<br />", $property->annotation->description);
        }
        return $desc;
    }

    /**
     * @param Property $property
     * @return mixed
     */
    protected function withValue($property)
    {
        return $property->value;
    }
}