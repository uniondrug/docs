<?php
/**
 * Created by PhpStorm.
 * User: kang.xiaoqiang
 * Date: 2022/6/15
 * Time: 14:21
 */

namespace Uniondrug\Docs\Commands;

use Uniondrug\Console\Command;
use Uniondrug\Docs\Parsers\Annotation;
use Uniondrug\Docs\Parsers\Collection;

/**
 * 同步Mss
 * Class Mss
 * @package Uniondrug\Docs\Commands
 */
class Mss extends Command
{
    protected $signature = 'mss
                            {--projectId=0 : 项目ID}
                            {--upload=false : 上传到Mss}';


    protected $controllerPath = 'app/Controllers';


    public $projectId = 0; //mss中你的项目ID

    public $accessType = 1; //1内网 2外网

    public $apiLevel = 'L2'; //1交易流程 2非交易类核心业务流程 3非核心业务流程 4统计分析类业务

//    public $userName = 'Auto'; //姓名

//    public $userId = 0; //mss用户ID

    public $mssToken = ''; //mss用户token

    protected $sdkMap = [];

    protected $serviceMap = [];

    protected $detailUrl = 'http://pm-dev-manage.uniondrug.cn/project/detail';

    protected $uploadUrl = 'http://pm-dev-manage.uniondrug.cn/api/import';

    /**
     * @return mixed|void
     * @throws \Exception
     */
    public function handle()
    {
        $this->init();
        $path = getcwd();
        $sdks = $this->scanner($path);
        if ($this->input->getOption('upload') === 'true') {
            $collection = new Collection($path, '');
            $collection->parser();
            $apis = $collection->toMssData($this);
            $this->upload($apis, $sdks);
        }
    }

    /**
     * 上传到mss
     * @param $apis
     * @param $sdks
     */
    protected function upload($apis, $sdks)
    {
        $this->info('结束扫描目录...');
        sleep(1);
        $this->info('开始同步Mss...');
        sleep(1);
        //
        if (empty($apis['apis'])) {
            $this->error("ERROR: 没有扫描到接口");
            exit;
        }
        // 合并
        foreach ($apis['apis'] as &$api) {
            foreach ($sdks as $sdk) {
                if ($api['apiUrl'] == $sdk['path']) {
                    $api['sdks'] = $sdk['sdks'];
                }
            }
        }
        // 同步
        $this->post($this->uploadUrl, $apis);
        $this->info('结束同步Mss...');
    }

    /**
     * 扫描目录
     * @param $path
     * @throws \Exception
     */
    protected function scanner($path)
    {
        $this->info('开始扫描目录...');
        sleep(1);
        $path = $path . '/' . $this->controllerPath;
        $length = strlen($path);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $info) {
            if ($info->isDir()) {
                continue;
            }
            $name = $info->getFilename();
            if (preg_match("/^[_a-zA-Z0-9]+Controller\.php$/", $name) === 0) {
                continue;
            }
            $class = '\\App\\Controllers\\' . substr($info->getPathname(), $length + 1, -4);
            $controllerMap[] = $class;
        }
        return $this->parserController($controllerMap);
    }

    /**
     * 解析控制器
     * @param $controllerMap
     * @throws \Exception
     */
    protected function parserController($controllerMap)
    {
        $sdks = [];
        foreach ($controllerMap as $class) {
            $class = str_replace("/", "\\", $class);
            if (!class_exists($class)) {
                continue;
            }
            $reflect = new \ReflectionClass($class);
            $classFile = $reflect->getFileName();

            $annotation = new Annotation($reflect);
            $annotation->prefix();
            // Actions
            foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $_reflect) {
                if ($_reflect->class !== $reflect->name) {
                    continue;
                }
                if (!preg_match("/^[_a-zA-Z0-9]+Action$/", $_reflect->name)) {
                    continue;
                }
                if (!$logicClass = $this->getLogicFile($_reflect, $classFile)) {
                    continue;
                }

                $actionAnnotation = new Annotation($_reflect);
                $actionAnnotation->info();
                $actionAnnotation->requeset();
                $sdks[] = [
                    'name' => trim($actionAnnotation->name),
                    'path' => $annotation->prefix . $actionAnnotation->path,
                    'sdks' => $this->parserLogic($logicClass)
                ];
            }
        }
        return $sdks;
    }

    /**
     * 解析Logic
     * @param $logicClass
     * @return array
     */
    protected function parserLogic($logicClass)
    {
        if (!class_exists($logicClass)) {
            return [];
        }
        $reflect = new \ReflectionClass($logicClass);
        $logicTxt = file_get_contents($reflect->getFileName());

        // 写法1: $ret = $this->serviceSdk->module->xxx->xxx();
        // 写法2: $ret = $this->xxxService->xxx();
        // 写法3:
        $arr1 = $arr2 = $map1 = $map2 = [];
        // 写1
        if (preg_match_all('/\$this->serviceSdk->module->([\w]+)->([\w]+)\(/', $logicTxt, $matches1)) {
            for ($i = 0; $i < count($matches1[1]); $i++) {
                for ($i = 0; $i < count($matches1[2]); $i++) {
                    $map1[$matches1[1][$i] . $matches1[2][$i]] = [
                        'name' => $matches1[1][$i],
                        'action' => $matches1[2][$i],
                    ];
                }
            }
            $arr1 = $this->parserSdk(array_values($map1));
        }
        // 写2
        if (preg_match_all('/\$this->([\w]+Service)->([\w]+)\(/', $logicTxt, $matches2)) {
            for ($i = 0; $i < count($matches2[1]); $i++) {
                for ($i = 0; $i < count($matches2[2]); $i++) {
                    $map2[$matches2[1][$i] . $matches2[2][$i]] = [
                        'name' => $matches2[1][$i],
                        'action' => $matches2[2][$i],
                    ];
                }
            }
            $arr2 = $this->parserService(array_values($map2));
        }

        return array_values(array_merge($arr1, $arr2));
    }

    /**
     * 解析SDK
     * @param $map
     * @return array
     */
    protected function parserSdk($map)
    {
        $sdks = [];
        foreach ($map as $value) {
            $action = $value['action'];
            $sdkName = $value['name'];
            if (!$sdkClass = $this->sdkMap[$sdkName] ?? '') {
                continue;
            }
            $reflect = new \ReflectionClass($sdkClass);
            foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($action != $method->name) {
                    continue;
                }
                if (!$actionTxt = $this->readFileByLine($reflect->getFileName(), $method->getStartLine(), $method->getEndLine())) {
                    continue;
                }
                $annotation = new Annotation($method);
                $annotation->info();
                // 例: return $this->restful("POST", "/orderStatistic/distribution", $body, $query, $extra);
                if (preg_match('/return\s*\$this->restful\(\"(POST|GET)\",\s*\"([\/\w]+)\",/', $actionTxt, $matches)) {
                    $sdks[$sdkName . $matches[2]] = [
//                        'sdkName' => $sdkName,
//                        'sdkClass' => $sdkClass,
                        'description' => trim($annotation->name),
                        'path' => $matches[2],
                        'method' => $matches[1],
                        'thirdFlag' => 0,
                        'domain' => $reflect->getDefaultProperties()['serviceName'] . '.uniondrug.cn'
                    ];
                }
            }
        }
        return $sdks;
    }

    /**
     * 解析Service
     * @param $map
     * @return array
     */
    protected function parserService($map)
    {
        $sdks = [];
        foreach ($map as $value) {
            $action = $value['action'];
            $serviceName = $value['name'];
            if (!$serviceClass = $this->serviceMap[$serviceName] ?? '') {
                continue;
            }
            if (!class_exists($serviceClass)) {
                continue;
            }
            $reflect = new \ReflectionClass($serviceClass);
            foreach ($reflect->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($action != $method->name) {
                    continue;
                }
                if (!$actionTxt = $this->readFileByLine($reflect->getFileName(), $method->getStartLine(), $method->getEndLine())) {
                    continue;
                }
                // 写1
                if (preg_match_all('/\$this->serviceSdk->module->([\w]+)->([\w]+)\(/', $actionTxt, $matches)) {
                    for ($i = 0; $i < count($matches[1]); $i++) {
                        for ($i = 0; $i < count($matches[2]); $i++) {
                            $map1[$matches[1][$i] . $matches[2][$i]] = [
                                'name' => $matches[1][$i],
                                'action' => $matches[2][$i],
                            ];
                        }
                    }
                    $sdks = $this->parserSdk(array_values($map1));
                }
            }
        }
        return $sdks;
    }

    // 返回Logic命名空间
    private function getLogicFile(\ReflectionMethod $reflect, $controllerFile)
    {
        if (!file_exists($reflect->getFileName())) {
            return '';
        }
        $actionText = $this->readFileByLine($reflect->getFileName(), $reflect->getStartLine(), $reflect->getEndLine());
        if (!preg_match('/\s*([\w]+)::factory/', $actionText, $matches)) {
            return '';
        }
        $logicName = $matches[1];
//        if (!preg_match_all('/use\s+([\w\\\w]+)\S/', file_get_contents($controllerFile), $matches)) {
        if (!preg_match_all('/use\s+([^;]+)\S/', file_get_contents($controllerFile), $matches)) {
            return '';
        }

        foreach ($matches[1] as $logicClass) {
            $tmp = explode("\\", $logicClass);
            if (end($tmp) == $logicName) {
                return $logicClass;
            }
        }
        return '';
    }

    // 按行读取
    private function readFileByLine($filename, $startLine, $endLine)
    {
        $fileArr = file($filename);
        $textArr = array_splice($fileArr, $startLine - 1, $endLine - $startLine + 1);
        return implode('', $textArr);
    }

    // 初始化
    protected function init()
    {
        if (!$this->projectId = $this->input->getOption('projectId')) {
            $this->error('ERROR: projectId 缺失');
            exit;
        }

        if (!$this->mssToken) {
            $this->error('ERROR: mssToken 缺失');
            exit;
        }
        // 1. 验证
        $detailData = $this->post($this->detailUrl, ['id' => $this->projectId]);
        if (empty($detailData)) {
            $this->error("ERROR: 项目不存在");
            exit;
        }

        // 项目一致校验
        $appName = app()->getConfig()->path('app.appName');
        if ($detailData['projectCode'] != $appName) {
            $this->error("ERROR: projectId与当前项目不一致");
            exit;
        }

        // sdk映射
        $moduleTraitReflect = new \ReflectionClass('Uniondrug\ServiceSdk\Traits\ModuleTrait');
        if (!preg_match_all('/@property\s*([\w\\\\]+)\s*\$([\w]+)/', $moduleTraitReflect->getDocComment(), $matches)) {
            $this->error('ERROR: 检查一下 Uniondrug\ServiceSdk\Traits\ModuleTrait');
            exit;
        }
        $this->sdkMap = array_combine($matches[2], $matches[1]);
        foreach ($this->sdkMap as &$v) {
            if (!preg_match('/\\\\/', $v)) {
                $v = '\Uniondrug\ServiceSdk\Exports\Modules\\' . $v;
                if (!class_exists($v)) {
                    $this->error("ERROR: Class不存在 {$v}");
                    exit;
                }
            }
        }

        // service映射
        $serviceTraitReflect = new \ReflectionClass('App\Services\Abstracts\ServiceTrait');
        if (!preg_match_all('/@property\s*([\w\\\\]+)\s*\$([\w]+)/', $serviceTraitReflect->getDocComment(), $matches)) {
            $this->error('ERROR: 检查ServiceTrait是否规范');
            exit;
        }
        $this->serviceMap = array_combine($matches[2], $matches[1]);
        if (!preg_match_all('/use\s+([^;]+)\S/', file_get_contents($serviceTraitReflect->getFileName()), $matches)) {
            $this->error('ERROR: 检查ServiceTrait是否规范');
            exit;
        }
        $serviceNameToServiceClass = [];
        foreach ($matches[1] as $serviceClass) {
            $tmp = explode("\\", $serviceClass);
            $serviceNameToServiceClass[end($tmp)] = $serviceClass;
        }
        foreach ($this->serviceMap as $serviceName => &$_serviceName) {
            if (!empty($serviceNameToServiceClass[$_serviceName])) {
                $_serviceName = $serviceNameToServiceClass[$_serviceName];
            } else {
                $this->error("ERROR: 未找对对应的Class, {$_serviceName}");
                exit;
            }
        }
    }


    // http
    private function post($url, $data)
    {
        $res = $this->httpClient->post($url, [
            'json' => $data,
            'headers' => [
                'Content-type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->mssToken,
            ]
        ]);
        $res = json_decode($res->getBody()->__toString(), 1);
        if ($res['errno'] != 0) {
            $this->error("请求mss接口Error: {$res['error']}");
            exit;
        }
        return $res['data'];
    }

    // 打印
    private function dd($data)
    {
        echo "<pre/>";
        print_r($data);
        exit;
    }

}