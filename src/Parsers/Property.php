<?php
/**
 * @author wsfuyibing <websearch@163.com>
 * @date   2018-05-09
 */
namespace Uniondrug\Postman\Parsers;

use Uniondrug\Postman\Parsers\Abstracts\Base;
use Uniondrug\Structs\StructInterface;

/**
 * 解析属性
 * @package Uniondrug\Postman\Parsers
 */
class Property extends Base
{
    public $name;
    public $value;

    /**
     * @param Method              $method
     * @param \ReflectionProperty $p
     */
    public function __construct(Method $method, StructInterface $struct, \ReflectionProperty $p)
    {
        parent::__construct();
        $this->name = $p->name;
        // 1. annotation
        $this->annotation = new Annotation($p);
        $this->annotation->alias();
        $this->annotation->info();
        $this->annotation->type();
        $this->annotation->validator();
        $this->annotation->mock();
        // 2. value
        if (!$this->annotation->isStructType) {
            if ($this->annotation->isArrayType) {
                $this->value = '[]';
            } else {
                try {
                    $this->value = $struct->{$p->name};
                } catch(\Throwable $e) {
                    $this->value = '?';
                }
            }
        }
    }

    /**
     * 属性默认值
     * @return int
     */
    public function defaultValue()
    {
        $t = $this->annotation->validator !== null ? $this->annotation->validator->type : $this->annotation->type;
        $v = '';
        switch ($t) {
            case 'int' :
            case 'integer' :
                $v = '0';
                break;
            case 'double' :
            case 'float' :
                $v = '0.0';
                break;
            case 'date' :
                $v = '0000-00-00';
                break;
            case 'datetime' :
                $v = '0000-00-00 00:00:00';
                break;
            case 'time' :
                $v = '00:00';
                break;
        }
        return $v;
    }
}
