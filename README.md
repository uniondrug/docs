# uniondrug/docs

### 命令

> 命令 1：**_php console postman_**

    原uniondrug/postman(3.x)全部功能，可导出`Markdown`文档、`Postman`接口工具、`SDK`

> 命令 2：**_php console torna_**

    上传文档到Torna，可在Torna查看并管理，地址：http://torna.uniondrug.cn/

*options*

![](http://uniondrug.oss-cn-hangzhou.aliyuncs.com/backend.assistant.manage/rc83ju4rsu2ef6mei7rdc4mma1.png)

```
新增的 [--save=true] 可保留Torna.json文件（用于调试）

```

### 如何使用

    以‘命令2’为例

> 第 1 步: 在应用 **composer.json** 中引入 **uniondrug/docs** 并执行 **composer update**

```json
"require-dev" : {
	"uniondrug/docs" : "^1.0"
},
```

> 第 2 步: 自定义命令：可使用 **php console make:command torna** 创建，也可直接在应用程序 **App\Commands** 下创建**TornaCommand.php**

```php
<?php
namespace App\Commands;

use Uniondrug\Docs\Commands\Torna;

class TornaCommand extends Torna
{
    public function handle()
    {
        parent::handle();
    }
}
```

> 第 3 步: 项目目录添加 **docs.json** 配置文件，在原 **postman.json** 基础上增加tornaToken配置。由于上传文档到 Torna 需要认证，若要上传文档到 Torna ，所以此步骤为 <必须> (优先级为: **docs.json** > **postman.json** > **config/app.php**)

```json
{
    "name" : "xxx模块", //应用名称,建议使用中文
    "description" : "xxx", //应用描述
    "host" : "", //域名
    "auth" : "NO", //是否鉴权
    "tornaToken": "Torna-Access-Token" //此token由Torna管理员提供
}
```

### 说明

1. **uniondrug/docs** 完整兼容 **uniondrug/postman** 的 **3.x** 版本，建议替换使用（若要替换，参考说明 2）
2. 若要废弃原 **postman** 命令，应用程序 **App\Commands** 下的 **PostmanCommand::class** 需修改继承为**\Uniondrug\Docs\Commands\Postman**

```php
<?php
namespace App\Commands;

use Uniondrug\Postman\Commands\Postman; //原来的

class PostmanCommand extends \Uniondrug\Docs\Commands\Postman //现在的
{
    public function handle()
    {
        parent::handle();
    }
}


```
