<?php

namespace Uniondrug\Docs\Parsers;

/**
 * 解析属性
 * @package Uniondrug\Docs\Parsers
 */
class Validator
{
    public $type = '';
    public $options = '';
    public $required = false;
    private static $regexpRequired = "/required[=]*([a-z]*)/i";
    private static $regexpTypes = "/type=(([_a-z0-9]+)|\{[^\}]+\})/i";
    private static $regexpOptions = "/options=(\{[^\}]+\})/i";

    public function __construct($text)
    {
        $this->_required($text);
        $this->_type($text);
        $this->_options($text);
    }

    private function _required(& $text)
    {
        preg_match(self::$regexpRequired, $text, $m);
        if (count($m) > 0) {
            $this->required = strtolower(trim($m[1])) !== 'false';
        }
    }

    private function _type(& $text)
    {
        preg_match(self::$regexpTypes, $text, $m);
        if (count($m) > 0) {
            $this->type = $m[1];
        }
    }

    private function _options(& $text)
    {
        preg_match(self::$regexpOptions, $text, $m);
        if (count($m) > 0) {
            $this->options = $m[1];
        }
    }
}
