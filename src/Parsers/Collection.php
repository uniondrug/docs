<?php

namespace Uniondrug\Docs\Parsers;

use App\Errors\Code;
use Phalcon\Di;
use Uniondrug\Docs\Parsers\Abstracts\Base;
use Uniondrug\Framework\Container;

/**
 * 解析控制器
 * @package Uniondrug\Docs\Parsers
 */
class Collection extends Base
{
    /**
     * 是否发布文档
     * @var bool
     */
    public $publishTo = 'docs/api';
    public $publishPostmanTo = 'docs';
    /**
     * 项目名称
     * @var string
     */
    public $appName = '';
    /**
     * 项目中文名称
     * @var string
     */
    public $name = '';
    /**
     * 端口号
     * @var string
     */
    public $serverPort = 80;
    /**
     * SDK类名
     * 如: mbs2
     * @var string
     */
    public $sdk = '';
    /**
     * SDK路径
     * 如: module
     * @var string
     */
    public $sdkPath = '';
    /**
     * SDK服务名
     * 如: mbs2.module
     * @var string
     */
    public $sdkService = '';
    /**
     * 目标应用文档连接前缀
     * @var string
     */
    public $sdkLink = '';
    public $prefix = '';
    /**
     * 描述
     * @var string
     */
    public $description = '';
    /**
     * 域名
     * @var string
     */
    public $host = '';
    /**
     * 是否鉴权
     * @var bool
     */
    public $auth = false;
    /**
     * @var Controller[]
     */
    public $controllers = [];
    public $classMap = [];
    /**
     * @var string
     */
    public $basePath;
    /**
     * @var string
     */
    public $exportPath;
    public $codeMap = null;
    private $controllerPath = 'app/Controllers';
    public $sdkx;

    /**
     * Controller constructor.
     * @param string $path 项目路径
     */
    public function __construct(string $path, string $exportPath)
    {
        parent::__construct();
        $this->basePath = $path;
        $this->exportPath = $exportPath;
        // 1. load config
        $json = $this->initPostmanJson();
        $this->name = $json->name;
        $this->description = $json->description;
        $this->host = $json->host;
        $this->auth = strtoupper($json->auth) === 'YES';
        $this->sdk = $json->sdk;
        $this->sdkPath = $json->sdkPath;
        $this->sdkService = $json->sdkService;
        $this->sdkLink = $json->sdkLink;
        $this->sdkx = new Sdkx($this);
        // 2. console
        $this->console->info("{$json->name}, {$json->description}");
        $this->console->info("需要鉴权: {$json->auth}");
        $this->console->info("域名前缀: {$json->host}");
        $this->console->info("扫描目录: %s", $this->controllerPath);
        if ($this->sdk === '') {
            $this->console->warning("SDK名称未在postman.json中定义sdk字段值, SDK导出将被禁用.");
            if ($this->sdkLink === '') {
                $this->console->warning("SDK入参文档前缀未定义sdkLink字段值, 文档连接错误.");
            }
        }
        // 3. 遍历目录
        $this->scanner($path . '/' . $this->controllerPath);
    }

    /**
     * 解析控制器
     */
    public function parser()
    {
        foreach ($this->classMap as $class) {
            $class = str_replace("/", "\\", $class);
            try {
                $controller = new Controller($this, $class);
                $controller->parser();
                $this->controllers[$class] = $controller;
            } catch (\Exception $e) {
                $this->console->error($e->getMessage());
            }
        }
    }

    public function getCodeMap()
    {
        if ($this->codeMap === null) {
            $this->codeMap = Code::exportMarkdown();
        }
        return $this->codeMap;
    }

    /**
     * 发布Markdown文档
     * 在Collectionk中发布README.md索引文档, 同时
     * 触发Controller的文档发布
     */
    public function toMarkdown()
    {
        // 1. title
        $text = '# ' . $this->name;
        // 2. description
        if ($this->description !== '') {
            $text .= $this->eol . $this->description;
        }
        // 3. information
        $text .= $this->eol;
        $text .= '* **鉴权** : `' . (strtoupper($this->auth) === 'YES' ? '开启' : '关闭') . '`' . $this->crlf;
        $text .= '* **域名** : `' . $this->schema . '://' . $this->host . '.' . $this->domain . '`' . $this->crlf;
        $text .= '* **导出** : `' . date('Y-m-d H:i') . '`';
        // 4. index
        $text .= $this->eol;
        $text .= '### 接口目录' . $this->eol;
        foreach ($this->controllers as $controller) {
            if (count($controller->methods) === 0) {
                continue;
            }
            $name = trim($controller->annotation->name);
            $desc = preg_replace("/\n/", "", trim($controller->annotation->description));
            $url = str_replace('\\', '/', substr($controller->reflect->getName(), 16));
            $text .= '* [' . $name . '](./' . $url . '/README.md) : ' . $desc . $this->crlf;
            $apis = $controller->getIndex(false);
            if ($apis !== '') {
                $text .= $apis . $this->crlf;
            }
        }
        // 5. code map
        $text .= $this->eol;
        $text .= '### 编码对照表';
        $text .= $this->eol;
        $text .= $this->getCodeMap();
        // 6. save README.md
        $this->saveMarkdown($this->exportPath . '/' . $this->publishTo, 'README.md', $text);
        // 7. trigger controllers
        foreach ($this->controllers as $controller) {
            $controller->toMarkdown();
        }
        // 8. SDK
        if ($this->sdk !== '') {
            $this->sdkx->export();
        }
    }

    /**
     * 转为POSTMAN
     * 将导出的结果输出到postman.json文件中
     */
    public function toPostman()
    {
        $data = [
            'info' => [
                'name' => $this->name,
                'description' => $this->description,
                "schema" => "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"
            ],
            'item' => [],
            'event' => $this->toPostmanEvent()
        ];
        foreach ($this->controllers as $controller) {
            $data['item'][] = $controller->toPostman();
        }
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 将导出的结果输出到torna.json文件中
     */
    public function toTorna()
    {
        $data = [
            'debugEnvs' => [],
            'apis' => [],
            "commonErrorCodes" => [],
            "author" => "",
            "isReplace" => 1,
            "domain" => $this->host,
            "gitUrl" => "",
            "gitHttpUrl" => "",
            "language" => "PHP",
            "servicePort" => $this->serverPort,
            "applicationName" => $this->appName,
            "moduleName" => $this->name,
            "softwareVersion" => ""
        ];
        foreach ($this->controllers as $controller) {
            $data['apis'][] = $controller->toTorna();
        }
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * JSON配置文件
     * @return \stdClass
     */
    private function initPostmanJson()
    {
        /**
         * 1. 初始化POSTMAN配置
         * @var Container $di
         */
        $di = Di::getDefault();
        $data = new \stdClass();
        // 1.1 通过appName计算
        //     sdk
        //     sdkPath
        $this->appName = $di->getConfig()->path('app.appName');

        // 端口
        $serv = $di->getConfig()->path('server.host');
        if ($serv) {
            if (preg_match("/(\S+):(\d+)/", $serv, $m) > 0) {
                $this->serverPort = substr($m[2], -4);
            }
        }

        /*$appName = preg_replace("/\-/", '.', $appName);
        $appNameArr = explode('.', $appName);
        $appNameDesc = [];
        for ($i = count($appNameArr) - 1; $i >= 0; $i--) {
            $appNameDesc[] = $appNameArr[$i];
        }
        $sdkPath = array_pop($appNameArr);
        if (!in_array($sdkPath, [
            'backend',
            'module',
            'union'
        ])) {
            $this->console->warning("应用名称在配置文件[config/app.php]中的[appName]字段值不合法, 必须以module、union、backend结尾");
        }*/
        $appNameArr = explode('-', $this->appName);
        $appNameAsc = $appNameArr;
        $sdkPath = array_shift($appNameArr);
        if (!in_array($sdkPath, [
            'pm',
            'ps',
            'px'
        ])) {
            $this->console->warning("应用名称在配置文件[config/app.php]中的[appName]字段值不合法, 必须以pm、ps、px 开头");
        }
        /*$sdkClass = preg_replace_callback("/[\.|\-](\w)/", function($a){
            return strtoupper($a[1]);
        }, implode('.', $appNameArr));*/
        $sdkClass = preg_replace_callback("/[\.|\-](\w)/", function ($a) {
            return strtoupper($a[1]);
        }, implode('.', $appNameAsc));
        // 1.2 赋初始值
        $data->auth = "NO";
        $data->name = $this->appName;
        $data->description = $this->appName;
        $data->host = $this->appName;
        $data->sdk = $sdkClass;
        $data->sdkPath = $this->sdkPath($sdkPath);
        $data->sdkService = $this->appName;
        //$data->sdkLink = "https://uniondrug.coding.net/p/".implode(".", $appNameDesc)."/git/blob/development";;
        $data->sdkLink = "https://uniondrug.coding.net/p/" . implode("-", $appNameAsc) . "/git/blob/development";
        // 2. 配置文件优选级
        $path = "{$this->basePath}/postman.json";
        if (file_exists($path)) {
            $json = file_get_contents($path);
            $conf = json_decode($json);
            if (is_object($conf)) {
                isset($conf->auth) && $conf->auth !== "" && $data->auth = $conf->auth;
                isset($conf->name) && $conf->name !== "" && $data->name = $conf->name;
                isset($conf->host) && $conf->host !== "" && $data->host = $conf->host;
                isset($conf->description) && $conf->description !== "" && $data->description = $conf->description;
                isset($conf->sdkLink) && $conf->sdkLink !== "" && $data->sdkLink = $conf->sdkLink;
            }
        }
        return $data;
    }

    /**
     * sdkPath 映射，兼容之前的路径和命名空间
     * @param $sdkPath
     * @return string
     */
    private function sdkPath($sdkPath)
    {
        switch ($sdkPath) {
            case 'ps':
                $sdkPath = 'module';
                break;
            case 'pm':
                $sdkPath = 'backend';
                break;
            case 'px':
                $sdkPath = 'module';
                break;
        }
        return $sdkPath;
    }

    /**
     * 扫描Controller目录
     * @param string $path
     */
    private function scanner($path)
    {
        $length = strlen($path);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path), \RecursiveIteratorIterator::SELF_FIRST);
        /**
         * @var \SplFileInfo $info
         */
        foreach ($iterator as $info) {
            // 1. 忽略目录
            if ($info->isDir()) {
                continue;
            }
            // 2. 忽然非Controller文件
            $name = $info->getFilename();
            if (preg_match("/^[_a-zA-Z0-9]+Controller\.php$/", $name) === 0) {
                continue;
            }
            // 3. 读取类名
            $class = '\\App\\Controllers\\' . substr($info->getPathname(), $length + 1, -4);
            $this->classMap[] = $class;
        }
    }

    public function toPostmanEvent()
    {
        // 默认端口
        $_serverPort = $_defaultPort = 80;
        $serv = \config()->path('server.host');
        if ($serv) {
            if (preg_match("/(\S+):(\d+)/", $serv, $m) > 0) {
                $_serverPort = substr($m[2], -4);
            }
        }
        $exec = [];
        $exec[] = 'var env = pm.environment.get(\'domain\');';
        $exec[] = 'var runType = pm.environment.get(\'swoole\');';
        $exec[] = 'var port = ' . $_defaultPort . ';';
        $exec[] = 'if (env != \'dev.uniondrug.info\' && env != \'turboradio.cn\' && env != \'uniondrug.net\' && env != \'uniondrug.cn\') {';
        $exec[] = '    if (runType == undefined || runType == \'0\' || runType == \'false\') {';
        $exec[] = '        port = ' . $_defaultPort . ';';
        $exec[] = '    } else {';
        $exec[] = '        port = ' . $_serverPort . ';';
        $exec[] = '    }';
        $exec[] = '}';
        $exec[] = 'pm.environment.set("port", port);';
        $exec[] = 'console.log(env + \':\' + port);';
        return [
            [
                'listen' => 'prerequest',
                'script' => [
                    'id' => md5($this->name . '::' . $this->name),
                    'type' => 'text/javascript',
                    'exec' => $exec
                ]
            ]
        ];
    }
}
