# uniondrug/docs

### 命令

> 命令 1：**_php console postman_**

    原uniondrug/postman(3.x)全部功能，可导出`Markdown`文档、`Postman`接口工具、`SDK`

> 命令 2：**_php console torna_**

    上传文档到Torna，可在Torna查看并管理，地址：http://torna.turboradio.cn/

### 如何使用

    以‘命令2’为例

> 1.在应用 **composer.json** 引入 **uniondrug/docs** 并执行 **composer update**

```
"require-dev" : {
	"uniondrug/docs" : "^1.0"
},
```

> 2.自定义命令：可使用 **php console make:command torna** 创建，也可直接在应用程序 **App\Commands** 下创建**TornaCommand.php**

```
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

> 3.项目目录添加 **docs.json** 配置文件，由于上传文档到 Torna 需要认证，所以此步骤为 <font style="color: red;"><必须></font> (优先级**docs.json** > **postman.json** > **config/app.php**)

```
{
    "name" : "xxx模块", //应用名称,建议使用中文 [可选]
    "description" : "xxx", //应用描述 [可选]
    "host" : "", //域名 [可选]
    "auth" : "NO", //是否鉴权 [可选]
    "tornaToken": "Torna-Access-Token" //此token由Torna管理员提供[上传Torna必传]
}
```

### 说明

- 1.**uniondrug/docs** 完整兼容 **uniondrug/postman** 的 **3.x** 版本，建议替换使用（若要替换，参考说明 2）

- 2.若要废弃原 **postman** 命令，应用程序 **App\Commands** 下的 **PostmanCommand::class** 需修改继承为**\Uniondrug\Docs\Commands\Postman**

```
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
