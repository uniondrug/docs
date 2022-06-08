<?php
/**
 * @author wsfuyibing <websearch@163.com>
 * @date   2018-05-09
 */
namespace Uniondrug\Postman\Parsers;

use Uniondrug\Postman\Parsers\Abstracts\Base;

/**
 * SDK列表
 * @package Uniondrug\Postman\Parsers
 */
class Sdkx extends Base
{
    public $collection;
    public $names = [];

    public function __construct(Collection $collection)
    {
        parent::__construct();
        $this->collection = $collection;
    }

    /**
     * @param string $name
     * @param string $method
     * @param string $path
     * @param string $title
     * @param string $description
     */
    public function add(string $name, string $method, string $path, string $title, string $description, string $linkTo)
    {
        $description = trim($description);
        $text = "";
        if ($description !== '') {
            foreach (explode("\n", $description) as $desc) {
                $desc = trim($desc);
                if ($desc !== '') {
                    $text .= "\n".'     * '.$desc;
                }
            }
        }
        $key = strtolower($name);
        $this->names[$key] = [
            'FUNCTION' => $name,
            'METHOD' => $method,
            'PATH' => $path,
            'TITLE' => trim($title),
            'DESCRIPTION' => $text,
            'LINKTO' => $this->collection->sdkLink.'/'.$this->collection->publishTo.'/'.$linkTo
        ];
    }

    /**
     * 导入文件
     */
    public function export()
    {
        $class = ucfirst($this->collection->sdk).'Sdk';
        $template = $this->renderClass($class);
        $this->saveMarkdown($this->collection->exportPath.'/'.$this->collection->publishPostmanTo, $class.'.php', $template);
    }

    /**
     * 生成SDK文件
     * @return string
     */
    private function renderClass(string $class)
    {
        $template = <<<'TEMP'
<?php
/**
 * 重要说明
 * 1. 本文件由Postman命令脚本自动生成, 请不要修改, 若需修改
 *    请通过`php console postman`命令重新生成.
 * 2. 本脚本在生成时, 依赖所在项目的Controller有 `@Sdk method`定义,
 *    同时, 项目根目录下的`postman.json`需有`sdk`、`sdkLink`定义
 * 3. 发布SDK，请将本文件放到`uniondrug/service-sdk`项目
 *    的`src/Exports/{{NAMESPACE}}`目录下，并发重新发布release版本.
 * @author {{AUTHOR}}
 * @date   {{DATE}}
 * @time   {{TIME}}
 */
namespace Uniondrug\ServiceSdk\Exports\{{NAMESPACE}};

use Uniondrug\ServiceSdk\Exports\Abstracts\SdkBase;
use Uniondrug\ServiceSdk\Bases\ResponseInterface;

/**
 * {{CLASS}}
 * @package Uniondrug\ServiceSdk\Modules
 */
class {{CLASS}} extends SdkBase
{
    /**
     * 服务名称
     * 自来`postman.json`文件定义的`sdkService`值
     * @var string
     */
    protected $serviceName = '{{SERVICE}}';

{{METHODS}}
}

TEMP;
        $values = [
            'AUTHOR' => 'PostmanCommand',
            'DATE' => date('Y-m-d'),
            'TIME' => date('r'),
            'NAMESPACE' => ucfirst($this->collection->sdkPath)."s",
            'CLASS' => $class,
            'SERVICE' => $this->collection->sdkService,
            'METHODS' => $this->renderMethods()
        ];
        foreach ($values as $key => $value) {
            $rexp = '/\{\{'.$key.'\}\}/';
            $template = preg_replace($rexp, $value, $template);
        }
        return $template;
    }

    /**
     * 生成方法
     * @param array
     * @return string
     */
    private function renderMethod(array $datas)
    {
        $template = <<<'TEMP'
    /**
     * {{TITLE}}{{DESCRIPTION}}
     * @link {{LINKTO}}
     * @param array|object $body 入参类型
     * @param null $query  Query数据
     * @param null $extra  请求头信息
     * @return ResponseInterface
     */
    public function {{FUNCTION}}($body, $query = null, $extra = null)
    {
        return $this->restful("{{METHOD}}", "{{PATH}}", $body, $query, $extra);
    }
TEMP;
        foreach ($datas as $key => $value) {
            $rexp = '/\{\{'.$key.'\}\}/';
            $template = preg_replace($rexp, $value, $template);
        }
        return $template;
    }

    /**
     * @return string
     */
    private function renderMethods()
    {
        ksort($this->names);
        reset($this->names);
        $methods = $comma = "";
        foreach ($this->names as $datas) {
            $methods .= $comma.$this->renderMethod($datas);
            $comma = "\n\n";
        }
        return $methods;
    }
}
