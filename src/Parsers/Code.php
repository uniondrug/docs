<?php

namespace Uniondrug\Docs\Parsers;

/**
 * Class Code
 * @package Uniondrug\Docs\Parsers
 */
class Code extends \App\Errors\Code
{

    /**
     * 导出Torna格式编码文档
     * @return array
     */
    public static function exportTorna()
    {
        $codeMap = [];
        $instance = new static();
        $reflect = new \ReflectionClass($instance);
        foreach ($reflect->getConstants() as $name => $code) {
            $codeMap[] = [
                'code' => static::$codePlus + $code,
                'msg' => static::getMessage($code),
                'solution' => '' // 解决方案
            ];
        }
        return $codeMap;
    }
}