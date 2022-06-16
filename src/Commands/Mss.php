<?php

namespace Uniondrug\Docs\Commands;

use Uniondrug\Console\Command;
use Uniondrug\Docs\Parsers\Annotation;

/**
 * Class Mss
 * @package Uniondrug\Docs\Commands
 */
class Mss extends Command
{
    protected $signature = 'mss
                            {--projectId=0 : 项目ID}
                            {--upload=false : 上传到Mss}';

    protected $projectId = 0; //mss中你的项目ID

    protected $sdkMap = [];

    protected $serviceMap = [];

    protected $detailUrl = 'http://pm-dev-manage.uniondrug.cn/project/detail';

    protected $pagingUrl = 'http://pm-dev-manage.uniondrug.cn/api/page';

    protected $createUrl = 'http://pm-dev-manage.uniondrug.cn/projectCallApi/create';

    /**
     * @return mixed|void
     * @throws \Exception
     */
    public function handle()
    {
        $this->init();
        $mss = $this->scanner();
        if ($this->input->getOption('upload') === 'true') {
            file_put_contents('1_mss.json', json_encode($mss, 256));
            $this->toMss($mss);
        }
    }

    /**
     * 上传到mss
     * @param $mss
     * @throws Error
     */
    protected function toMss($mss)
    {
        $this->info('开始同步Mss...');
        // 1. 验证
        $detailData = $this->post($this->detailUrl, ['id' => $this->projectId]);
        if (empty($detailData)) {
            $this->error("ERROR: 项目不存在");
            exit;
        }
        $gitUrl = exec('git ls-remote --get-url origin');
        $gitHttpUrl = str_replace(':36022', '', str_replace('ssh://git@', 'https://', $gitUrl));
        if ($detailData['projectCodeUrl'] != substr($gitHttpUrl, 0, -4)) {
            $this->info($detailData['projectCodeUrl']);
            $this->info(substr($gitHttpUrl, 0, -4));
            $this->error("ERROR: projectId与当前项目不一致");
            exit;
        }
        // 2. 拉取Mss接口列表
        $mssActions = $this->post($this->pagingUrl, [
            "page" => 1,
            "limit" => 999, //查一下
            "projectId" => $this->projectId
        ]);
        if (empty($mssActions['body'])) {
            $this->error("ERROR: 没有可操作的数据");
            exit;
        }
        if ($mssActions['paging']['totalItems'] > 500) {
            $this->error("ERROR: 接口太多了");
            exit;
        }

        // 3. 上传Mss
        $params = [];
        foreach ($mssActions['body'] as $mssAction) {
            foreach ($mss as $controller) {
                if (empty($controller['actions'])) {
                    continue;
                }
                foreach ($controller['actions'] as $action) {
                    if (empty($action['sdks'])) {
                        continue;
                    }
                    if ($mssAction['apiUrl'] != $controller['prefix'] . $action['uri']) {
                        continue;
                    }
                    // 组织数据
                    foreach ($action['sdks'] as $sdk) {
                        $params[] = [
                            "id" => "",
                            "projectId" => $this->projectId,
                            "projectApiId" => $mssAction['id'],
                            "domain" => $sdk['sdkDomain'],
                            "url" => $sdk['sdkPath'],
                            "thirdFlag" => 0,
                            "apiDesc" => "",
                            "workerName" => "Auto",
                            "memberId" => ""
                        ];
                    }
                }
            }
        }
        if ($params) {
            foreach ($params as $param) {
                $this->post($this->createUrl, $param);
            }
        }
        $this->info('结束同步Mss...');
    }

    /**
     * 扫描目录
     * @param $path
     * @throws \Exception
     */
    protected function scanner()
    {
        $path = getcwd();
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
            $class = ucfirst(substr($info->getPathname(), $length + 1, -4));
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
        $mss = [];
        foreach ($controllerMap as $class) {
            $class = str_replace("/", "\\", $class);
            $reflect = new \ReflectionClass($class);
            $classFile = $reflect->getFileName();

            $annotation = new Annotation($reflect);
            $annotation->prefix();
            $annotation->info();
            // Actions
            $methods = [];
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
                $methods[] = [
                    'name' => trim($actionAnnotation->name),
                    'uri' => $actionAnnotation->path,
                    'logicClass' => $logicClass,
                    'sdks' => $this->parserLogic($logicClass)
                ];
            }
            $mss[] = [
                'id' => '{id}',
                'projectId' => '{projectId}',
                'name' => trim($annotation->name),
                'prefix' => $annotation->prefix,
                'actions' => $methods
            ];
        }
        return $mss;
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
                // 例: return $this->restful("POST", "/orderStatistic/distribution", $body, $query, $extra);
                if (preg_match('/return\s*\$this->restful\(\"(POST|GET)\",\s*\"([\/\w]+)\",/', $actionTxt, $matches)) {
                    $sdks[$sdkName . $matches[2]] = [
                        'sdkName' => $sdkName,
                        'sdkClass' => $sdkClass,
                        'sdkMethod' => $matches[1],
                        'sdkPath' => $matches[2],
                        'sdkDomain' => $reflect->getDefaultProperties()['serviceName'] . '.uniondrug.cn'
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
        if (!preg_match('/=\s*([\w]+)::factory/', $actionText, $matches)) {
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

    // 打印
    private function dd($data)
    {
        echo "<pre/>";
        print_r($data);
        exit;
    }

    // http
    private function post($url, $data)
    {
        $res = $this->httpClient->post($url, [
            'json' => $data
        ]);
        $res = json_decode($res->getBody()->__toString(), 1);
        if ($res['errno'] != 0) {
            $this->error("请求Error:{$res['error']}");
            exit;
        }
        return $res['data'];
    }

    // 初始化
    protected function init()
    {
        if (!$this->projectId = $this->input->getOption('projectId')) {
            $this->error('ERROR: 未指定项目projectId');
            exit;
        }
        $moduleTraitReflect = new \ReflectionClass('Uniondrug\ServiceSdk\Traits\ModuleTrait');
        if (!preg_match_all('/@property\s*([\w\\\w]+)\s*\$([\w]+)/', $moduleTraitReflect->getDocComment(), $matches)) {
            $this->error('ERROR: 先把sdk更新下来 composer update');
        }
        $this->sdkMap = array_combine($matches[2], $matches[1]); //sdk映射
        $serviceTraitReflect = new \ReflectionClass('App\Services\Abstracts\ServiceTrait');
        if (!preg_match_all('/@property\s*([\w\\\w]+)\s*\$([\w]+)/', $serviceTraitReflect->getDocComment(), $matches)) {
            $this->error('ERROR: 检查ServiceTrait是否规范');
            exit;
        }
        $this->serviceMap = array_combine($matches[2], $matches[1]); //sdk映射
        if (!preg_match_all('/use\s+([^;]+)\S/', file_get_contents($serviceTraitReflect->getFileName()), $matches)) {
            $this->error('ERROR: 检查ServiceTrait是否规范');
            exit;
        }
        foreach ($matches[1] as $serviceClass) {
            $tmp = explode("\\", $serviceClass);
            $tmpArr[end($tmp)] = $serviceClass;
        }
        foreach ($this->serviceMap as $serviceName => &$_serviceName) {
            if (!empty($tmpArr[$_serviceName])) {
                $_serviceName = $tmpArr[$_serviceName];
            }
        }
    }

}