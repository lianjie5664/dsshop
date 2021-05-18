<?php

namespace App\common;

use App\Code;
use Illuminate\Http\Request;

class Plugin
{
    private $pluginPath;
    private $path;
    private $migrations;
    private $migrationsPath;
    private $casting = [
        'tinyInteger' => 'int',
        'smallInteger' => 'int',
        'mediumInteger' => 'int',
        'integer' => 'int',
        'bigInteger' => 'int',
        'timestamp' => 'string',
        'char' => 'string',
        'string' => 'string',
        'text' => 'string',
        'mediumText' => 'string',
        'longText' => 'string',
        'json' => 'string',
    ];

    function __construct()
    {
        $file_path = explode("/", base_path());
        unset($file_path[count($file_path) - 1]);
        $this->path = implode("/", $file_path);
        $this->pluginPath = $this->path . '/plugin';
        $this->migrationsPath = $this->path . '/api/database/migrations';
        $this->migrations = scandir($this->migrationsPath);
    }

    /**
     * 获取本地插件列表
     */
    public function getLocalPlugin()
    {
        $data = scandir($this->pluginPath);
        $list = [];
        $json_dsshop = json_decode(file_get_contents($this->pluginPath . '/dsshop.json'), true);
        foreach ($data as $value) {
            if ($value != '.' && $value != '..' && $value != 'dsshop.json' && $value != 'template') {
                $dsshop = json_decode(file_get_contents($this->pluginPath . '/' . $value . '/dsshop.json'), true);
                foreach ($json_dsshop as $js) {
                    if ($js['name'] == $dsshop['abbreviation']) {
                        $dsshop['locality_versions'] = $js['versions'];
                        $dsshop['is_delete'] = $js['is_delete'];
                    }
                }
                $list[] = $dsshop;
            }
        }
        return $list;
    }

    /**
     * 创建插件
     * @param Request $request
     * @return string
     * @throws \Exception
     */
    public function create($request)
    {

        if (file_exists($this->pluginPath . '/' . $request->abbreviation . '/dsshop.json')) {
            throw new \Exception('创建的插件已经存在', Code::CODE_PARAMETER_WRONG);
        }
        $this->generatePlugInDirectory();
        $this->createPlugInJson($request);
        foreach ($request->db as $db) {
            $this->createDBMigration($db);
            $this->createController($db, 'admin');
            $this->createController($db, 'client');
            $this->createModels($db);
            $this->createRequests($db);
        }
        return '创建成功';
    }

    /**
     * 编辑插件
     * @param Request $request
     * @return string
     * @throws \Exception
     */
    public function edit($request)
    {
        $this->generatePlugInDirectory();
        $this->editPlugInJson($request);
        if ($request->reset) {
            foreach ($request->db as $db) {
                $this->createDBMigration($db);
                $this->createController($db, 'admin');
                $this->createController($db, 'client');
                $this->createModels($db);
                $this->createRequests($db);
            }
        }
        return '更新成功';
    }

    /**
     * 删除插件
     * @param $name
     * @return string
     */
    public function destroy($name)
    {
        $path = json_decode(file_get_contents($this->pluginPath . '/' . $name . '/dsshop.json'));
        foreach ($path->db as $db) {
            $names = ucfirst(rtrim($db->name, 's'));
            $this->fileDestroy($this->migrationsPath . '/' . $this->getLocalMigrations('create_' . $db->name . '_table'));
            $this->fileDestroy($this->path . '/api/app/Http/Controllers/v' . config('dsshop.versions') . '/Plugin/Admin/' . $names . 'Controller.php');
            $this->fileDestroy($this->path . '/api/app/Http/Controllers/v' . config('dsshop.versions') . '/Plugin/Client/' . $names . 'Controller.php');
            $this->fileDestroy($this->path . '/api/app/Models/v' . config('dsshop.versions') . '/' . $names . '.php');
            $this->fileDestroy($this->path . '/api/app/Http/Requests/v' . config('dsshop.versions') . '/Submit' . $names . 'Request.php');
        }
        $this->fileDestroy($this->pluginPath . '/' . $name . '/dsshop.json');
        $this->catalogueDestroy($this->pluginPath . '/' . $name);
        return '删除成功';
    }

    /**
     * 删除文件
     * @param $path
     */
    protected function fileDestroy($path)
    {
        if (!is_dir($path) && file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * 删除目录
     * @param $path
     */
    protected function catalogueDestroy($path)
    {
        if (is_dir($path)) {
            rmdir($path);
        }
    }

    /**
     * 插件详情
     * @param $name
     * @return string
     */
    public function details($name)
    {
        $path = $this->pluginPath . '/' . $name . '/dsshop.json';
        return json_decode(file_get_contents($path));
    }

    /**
     * 安装和更新插件
     * @param $name //插件简称
     * @return string
     */
    public function autoPlugin($name)
    {
        $routes = $this->pluginPath . '/' . $name . '/routes.json';
        $dsshop = $this->pluginPath . '/' . $name . '/dsshop.json';
        if (!file_exists($routes)) {
            return resReturn(0, '插件缺少routes.json文件', Code::CODE_WRONG);
        }
        if (!file_exists($dsshop)) {
            return resReturn(0, '插件缺少dsshop.json文件', Code::CODE_WRONG);
        }
        $dsshop = json_decode(file_get_contents($dsshop), true);
        // 文件自动部署
        $this->fileDeployment($this->pluginPath . '/' . $name . '/admin/api', $this->path . '/admin/src/api');
        $this->fileDeployment($this->pluginPath . '/' . $name . '/admin/views/' . ucwords($name), $this->path . '/admin/src/views/ToolManagement/' . ucwords($name));
        $this->fileDeployment($this->pluginPath . '/' . $name . '/api/config', $this->path . '/api/config');
        $this->fileDeployment($this->pluginPath . '/' . $name . '/api/console', $this->path . '/api/app/Console/Commands');
        $this->fileDeployment($this->pluginPath . '/' . $name . '/api/models', $this->path . '/api/app/Models/v' . config('dsshop.versions'));
        $this->fileDeployment($this->pluginPath . '/' . $name . '/api/plugin', $this->path . '/api/app/Http/Controllers/v' . config('dsshop.versions') . '/Plugin');
        $this->fileDeployment($this->pluginPath . '/' . $name . '/api/requests', $this->path . '/api/app/Http/Requests/v' . config('dsshop.versions'));
        $this->fileDeployment($this->pluginPath . '/' . $name . '/api/observers', $this->path . '/api/app/Observers');
        $this->fileDeployment($this->pluginPath . '/' . $name . '/database', $this->path . '/api/database/migrations');
        $this->fileDeployment($this->pluginPath . '/' . $name . '/uniApp/api', $this->path . '/client/Dsshop/api');
        $this->fileDeployment($this->pluginPath . '/' . $name . '/uniApp/components', $this->path . '/client/Dsshop/components');
        $this->fileDeployment($this->pluginPath . '/' . $name . '/uniApp/pages', $this->path . '/client/Dsshop/pages');
        // 路由自动部署
        $routes = json_decode(file_get_contents($routes), true);
        // api
        if (array_key_exists('admin', $routes) || array_key_exists('app', $routes) || array_key_exists('notValidatedApp', $routes)) {
            $targetPath = $this->path . '/api/routes/plugin.php';
            $file_get_contents = file_get_contents($targetPath);
            //去除已存在的插件代码
            $file_get_contents = preg_replace('/\/\/' . $dsshop['name'] . '_s(.*?)\/\/' . $dsshop['name'] . '_e/is', '', $file_get_contents);
            $file_get_contents = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file_get_contents);
            // 添加新的插件代码
            if (array_key_exists('admin', $routes)) {
                $file_get_contents = str_replace("前台插件列表", $dsshop['name'] . "_s
        " . $routes['admin'] . "
        //" . $dsshop['name'] . "_e
        //前台插件列表", $file_get_contents);
            }

            if (array_key_exists('notValidatedApp', $routes)) {
                $file_get_contents = str_replace("APP无需验证插件列表", $dsshop['name'] . "_s
        " . $routes['notValidatedApp'] . "
        //" . $dsshop['name'] . "_e
        //APP无需验证插件列表", $file_get_contents);
            }

            if (array_key_exists('app', $routes)) {
                $file_get_contents = str_replace("APP验证插件列表", $dsshop['name'] . "_s
        " . $routes['app'] . "
        //" . $dsshop['name'] . "_e
        //APP验证插件列表", $file_get_contents);
            }
            file_put_contents($targetPath, $file_get_contents);
            unset($targetPath);
            unset($file_get_contents);
            unset($metadata);
        }
        // permission
        if (array_key_exists('permission', $routes)) {
            $targetPath = $this->path . '/admin/src/store/modules/permission.js';
            $file_get_contents = file_get_contents($targetPath);
            //去除已存在的插件代码
            $file_get_contents = preg_replace('/\/\/ ' . $dsshop['name'] . '_s(.*?)\/\/ ' . $dsshop['name'] . '_e/is', '', $file_get_contents);
            $file_get_contents = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file_get_contents);
            // 添加新的插件代码
            $metadata = str_replace("插件列表", $dsshop['name'] . "_s
  " . $routes['permission'] . "
  // " . $dsshop['name'] . "_e
  // 插件列表", $file_get_contents);
            file_put_contents($targetPath, $metadata);
            unset($targetPath);
            unset($file_get_contents);
            unset($metadata);
        }
        // uni-app
        if (array_key_exists('uniApp', $routes)) {
            $targetPath = $this->path . '/client/Dsshop/pages.json';
            $file_get_contents = file_get_contents($targetPath);
            //去除已存在的插件代码
            $file_get_contents = preg_replace('/\/\/ ' . $dsshop['name'] . '_s(.*?)\/\/ ' . $dsshop['name'] . '_e/is', '', $file_get_contents);
            $file_get_contents = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file_get_contents);
            // 添加新的插件代码
            $metadata = str_replace("插件列表", $dsshop['name'] . "_s
		" . $routes['uniApp'] . "
		// " . $dsshop['name'] . "_e
		// 插件列表", $file_get_contents);
            file_put_contents($targetPath, $metadata);
            unset($targetPath);
            unset($file_get_contents);
            unset($metadata);
        }
        // observers
        if (array_key_exists('observers', $routes)) {
            $targetPath = $this->path . '/api/app/Providers/AppServiceProvider.php';
            $file_get_contents = file_get_contents($targetPath);
            //去除已存在的插件代码
            $file_get_contents = preg_replace('/\/\/ ' . $dsshop['name'] . '_s(.*?)\/\/ ' . $dsshop['name'] . '_e/is', '', $file_get_contents);
            $file_get_contents = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file_get_contents);
            // 添加新的插件代码
            $file_get_contents = str_replace("插件", $dsshop['name'] . "_s
        " . $routes['observers'] . "
        // " . $dsshop['name'] . "_e
        // 插件", $file_get_contents);
            file_put_contents($targetPath, $file_get_contents);
            unset($targetPath);
            unset($file_get_contents);
        }
        // 微信公众号模板消息
        if (array_key_exists('wechatChannel', $routes)) {
            $targetPath = $this->path . '/api/app/Channels/WechatChannel.php';
            $file_get_contents = file_get_contents($targetPath);
            //去除已存在的插件代码
            $file_get_contents = preg_replace('/\/\/ ' . $dsshop['name'] . '_s(.*?)\/\/ ' . $dsshop['name'] . '_e/is', '', $file_get_contents);
            $file_get_contents = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file_get_contents);
            // 添加新的插件代码
            $file_get_contents = str_replace("插件", $dsshop['name'] . "_s
    " . $routes['wechatChannel'] . "
    // " . $dsshop['name'] . "_e
    // 插件", $file_get_contents);
            file_put_contents($targetPath, $file_get_contents);
            unset($targetPath);
            unset($file_get_contents);
        }
        //写入本地插件列表
        $json_dsshop = json_decode(file_get_contents($this->pluginPath . '/dsshop.json'), true);
        if (collect($json_dsshop)->firstWhere('name', $name)) {
            foreach ($json_dsshop as $id => $js) {
                if ($js['name'] == $dsshop['abbreviation']) {
                    $json_dsshop[$id]['versions'] = $dsshop['versions'];
                    $json_dsshop[$id]['is_delete'] = 0;
                    $json_dsshop[$id]['time'] = date('Y-m-d H:i:s');
                }
            }
        } else {
            $json_dsshop[] = array(
                'name' => $name,
                'versions' => $dsshop['versions'],
                'is_delete' => 0,
                'time' => date('Y-m-d H:i:s')
            );
        }
        file_put_contents($this->pluginPath . '/dsshop.json', json_encode($json_dsshop));
        return resReturn(1, '成功');
    }

    /**
     * 拷贝目录下文件到指定目录下，没有目录则创建
     * @param string $original //原始目录
     * @param string $target //目标目录
     */
    protected function fileDeployment($original, $target)
    {
        if (file_exists($original)) {
            if (!file_exists($target)) {
                mkdir($target, 0777, true);
            }
            $data = scandir($original);
            foreach ($data as $value) {
                if ($value != '.' && $value != '..') {
                    if (is_dir($original . '/' . $value)) { //如果是目录
                        $this->fileDeployment($original . '/' . $value, $target . '/' . $value);
                    } else {
                        copy($original . '/' . $value, $target . '/' . $value);
                    }
                }
            }
        }
    }

    /**
     * 卸载插件
     * @param string $name //组件名称
     * @return string
     */
    public function autoUninstall($name)
    {
        $routes = $this->pluginPath . '/' . $name . '/routes.json';
        $dsshop = $this->pluginPath . '/' . $name . '/dsshop.json';
        if (!file_exists($routes)) {
            return resReturn(0, '插件缺少routes.json文件', Code::CODE_WRONG);
        }
        if (!file_exists($dsshop)) {
            return resReturn(0, '插件缺少dsshop.json文件', Code::CODE_WRONG);
        }
        $dsshop = json_decode(file_get_contents($dsshop), true);
        $json_dsshop = json_decode(file_get_contents($this->pluginPath . '/dsshop.json'), true);
        //去除uni-app路由
        $targetPath = $this->path . '/client/Dsshop/pages.json';
        $file_get_contents = file_get_contents($targetPath);
        $file_get_contents = preg_replace('/\/\/ ' . $dsshop['name'] . '_s(.*?)\/\/ ' . $dsshop['name'] . '_e/is', '', $file_get_contents);
        $file_get_contents = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file_get_contents);
        file_put_contents($targetPath, $file_get_contents);
        unset($targetPath);
        unset($file_get_contents);
        //去除API路由
        $targetPath = $this->path . '/api/routes/plugin.php';
        $file_get_contents = file_get_contents($targetPath);
        //去除已存在的插件代码
        $file_get_contents = preg_replace('/\/\/' . $dsshop['name'] . '_s(.*?)\/\/' . $dsshop['name'] . '_e/is', '', $file_get_contents);
        $file_get_contents = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file_get_contents);
        file_put_contents($targetPath, $file_get_contents);
        unset($targetPath);
        unset($file_get_contents);
        //去除observers注册代码
        $targetPath = $this->path . '/api/app/Providers/AppServiceProvider.php';
        $file_get_contents = file_get_contents($targetPath);
        //去除已存在的插件代码
        $file_get_contents = preg_replace('/\/\/ ' . $dsshop['name'] . '_s(.*?)\/\/ ' . $dsshop['name'] . '_e/is', '', $file_get_contents);
        $file_get_contents = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file_get_contents);
        file_put_contents($targetPath, $file_get_contents);
        unset($targetPath);
        unset($file_get_contents);
        // 去除微信公众号模板消息
        $targetPath = $this->path . '/api/app/Channels/WechatChannel.php';
        $file_get_contents = file_get_contents($targetPath);
        //去除已存在的插件代码
        $file_get_contents = preg_replace('/\/\/ ' . $dsshop['name'] . '_s(.*?)\/\/ ' . $dsshop['name'] . '_e/is', '', $file_get_contents);
        $file_get_contents = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file_get_contents);
        file_put_contents($targetPath, $file_get_contents);
        unset($targetPath);
        unset($file_get_contents);
        //去除后台路由
        $targetPath = $this->path . '/admin/src/store/modules/permission.js';
        $file_get_contents = file_get_contents($targetPath);
        //去除已存在的插件代码
        $file_get_contents = preg_replace('/\/\/ ' . $dsshop['name'] . '_s(.*?)\/\/ ' . $dsshop['name'] . '_e/is', '', $file_get_contents);
        $file_get_contents = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $file_get_contents);
        file_put_contents($targetPath, $file_get_contents);
        unset($targetPath);
        unset($file_get_contents);
        $this->fileUninstall($this->pluginPath . '/' . $name . '/admin/api', $this->path . '/admin/src/api');
        $this->fileUninstall($this->pluginPath . '/' . $name . '/admin/views/' . ucwords($name), $this->path . '/admin/src/views/ToolManagement/' . ucwords($name));
        $this->fileUninstall($this->pluginPath . '/' . $name . '/api/config', $this->path . '/api/config');
        $this->fileUninstall($this->pluginPath . '/' . $name . '/api/console', $this->path . '/api/app/Console/Commands');
        $this->fileUninstall($this->pluginPath . '/' . $name . '/api/models', $this->path . '/api/app/Models/v' . config('dsshop.versions'));
        $this->fileUninstall($this->pluginPath . '/' . $name . '/api/plugin', $this->path . '/api/app/Http/Controllers/v' . config('dsshop.versions') . '/Plugin');
        $this->fileUninstall($this->pluginPath . '/' . $name . '/api/requests', $this->path . '/api/app/Http/Requests/v' . config('dsshop.versions'));
        $this->fileUninstall($this->pluginPath . '/' . $name . '/api/observers', $this->path . '/api/app/Observers');
        $this->fileUninstall($this->pluginPath . '/' . $name . '/database', $this->path . '/api/database/migrations');
        $this->fileUninstall($this->pluginPath . '/' . $name . '/uniApp/api', $this->path . '/client/Dsshop/api');
        $this->fileUninstall($this->pluginPath . '/' . $name . '/uniApp/components', $this->path . '/client/Dsshop/components');
        $this->fileUninstall($this->pluginPath . '/' . $name . '/uniApp/pages', $this->path . '/client/Dsshop/pages');
        foreach ($json_dsshop as $id => $json) {
            if ($json['name'] == $name) {
                $json_dsshop[$id]['is_delete'] = 1;
            }
        }
        file_put_contents($this->pluginPath . '/dsshop.json', json_encode($json_dsshop));
        return resReturn(1, '成功');
    }

    /**
     * 删除目录下的插件文件
     * @param string $original //原始目录
     * @param $target
     */
    protected function fileUninstall($original, $target)
    {
        if (file_exists($original)) {
            $data = scandir($original);
            foreach ($data as $value) {
                if ($value != '.' && $value != '..') {
                    if (is_dir($original . '/' . $value)) { //如果是目录
                        $this->fileUninstall($original . '/' . $value, $target . '/' . $value);
                    } else {
                        if (file_exists($target . '/' . $value)) {
                            unlink($target . '/' . $value);
                        }
                    }
                }
            }
        }
    }

    /**
     * 创建插件配置文件
     * @param $request
     */
    protected function createPlugInJson($request)
    {
        // 创建插件目录
        $path = $this->pluginPath . '/' . $request->abbreviation;
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }
        // 创建插件数据
        if (!file_exists($path . '/dsshop.json')) {
            fopen($path . '/dsshop.json', 'w+');
        }
        $json = [
            'name' => $request->name,
            'abbreviation' => $request->abbreviation,
            'describe' => $request->describe,
            'versions' => $request->versions,
            'author' => $request->author,
            'local' => true,
            'db' => $request->db
        ];
        file_put_contents($path . '/dsshop.json', json_encode($json));
    }

    /**
     * 更新插件配置文件
     * @param $request
     */
    protected function editPlugInJson($request)
    {
        // 插件目录
        $path = $this->pluginPath . '/' . $request->abbreviation . '/dsshop.json';
        $json = [
            'name' => $request->name,
            'abbreviation' => $request->abbreviation,
            'describe' => $request->describe,
            'versions' => $request->versions,
            'author' => $request->author,
            'local' => true,
            'db' => $request->db
        ];
        file_put_contents($path, json_encode($json));
    }

    /**
     * 生成数据表对应的验证器
     * @param $db
     * @throws \Exception
     */
    protected function createRequests($db)
    {
        // 模板
        $controller = $this->pluginPath . '/template/requests.api.ds';
        // 控制器名称为去掉尾部s首字母大写
        $name = ucfirst(rtrim($db['name'], 's'));
        // 控制器文件
        $path = $this->path . '/api/app/Http/Requests/v' . config('dsshop.versions') . '/Submit' . $name . 'Request.php';
        if (!file_exists($controller)) {
            throw new \Exception('requests.api.ds文件', Code::CODE_INEXISTENCE);
        }
        // 生成控制器
        if (!file_exists($path)) {
            fopen($path, 'w+');
        }
        $content = file_get_contents($controller);
        $rule = '';
        $ruleHint = '';
        $content = preg_replace([
            '/{{ versions }}/',
            '/{{ name }}/'
        ], [
            config('dsshop.versions'),
            ucfirst($db['name'])
        ], $content);
        $content = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $content);
        file_put_contents($path, $content);
    }

    /**
     * 生成数据表对应的模型
     * @param $db
     * @throws \Exception
     */
    protected function createModels($db)
    {
        // 模板
        $controller = $this->pluginPath . '/template/models.api.ds';
        // 控制器名称为去掉尾部s首字母大写
        $name = ucfirst(rtrim($db['name'], 's'));
        // 控制器文件
        $path = $this->path . '/api/app/Models/v' . config('dsshop.versions') . '/' . $name . '.php';
        if (!file_exists($controller)) {
            throw new \Exception('models.api.ds文件', Code::CODE_INEXISTENCE);
        }
        // 生成控制器
        if (!file_exists($path)) {
            fopen($path, 'w+');
        }
        $content = file_get_contents($controller);
        $property = '';
        $SoftDeletes = $db['softDeletes'] ? 'use Illuminate\Database\Eloquent\SoftDeletes;' : '';
        $SoftDeletesUse = $db['softDeletes'] ? 'use SoftDeletes;' : '';
        foreach ($db['attribute'] as $a) {
            $property .= '
 * @property  ' . $this->casting[$a['type']] . ' ' . $a['name'] . '';
        }
        $content = preg_replace([
            '/{{ versions }}/',
            '/{{ name }}/',
            '/{{ SoftDeletes }}/',
            '/{{ SoftDeletesUse }}/',
            '/{{ property }}/'
        ], [
            config('dsshop.versions'),
            ucfirst($db['name']),
            $SoftDeletes,
            $SoftDeletesUse,
            $property
        ], $content);
        $content = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $content);
        file_put_contents($path, $content);
    }

    /**
     * 生成数据表对应的控制器
     * @param $db
     * @param $type //admin:后台 or client：客户端
     * @throws \Exception
     */
    protected function createController($db, $type = 'admin')
    {
        // 模板
        $controller = $this->pluginPath . '/template/controller.api.' . $type . '.ds';
        // 控制器名称为去掉尾部s首字母大写
        $name = ucfirst(rtrim($db['name'], 's'));
        // 控制器文件
        $path = $this->path . '/api/app/Http/Controllers/v' . config('dsshop.versions') . '/Plugin/' . ucfirst($type) . '/' . $name . 'Controller.php';
        if (!file_exists($controller)) {
            throw new \Exception('缺少controller.api.' . $type . '.ds文件', Code::CODE_INEXISTENCE);
        }
        // 生成控制器
        if (!file_exists($path)) {
            fopen($path, 'w+');
        }
        $content = file_get_contents($controller);
        $attribute = '';
        $queryParam = '';
        if ($type === 'admin') {
            foreach ($db['attribute'] as $a) {
                $attribute .= '
            $' . ucfirst($db['name']) . '->' . $a['name'] . ' = $request->' . $a['name'] . ';';
                $queryParam .= '
            * @queryParam  ' . $a['name'] . ' ' . $this->casting[$a['type']] . ' ' . $a['annotation'] . '';
            }
            $content = preg_replace([
                '/{{ versions }}/',
                '/{{ name }}/',
                '/{{ annotation }}/',
                '/{{ attribute }}/',
                '/{{ queryParam }}/',
            ], [
                config('dsshop.versions'),
                ucfirst($db['name']),
                $db['annotation'],
                $attribute,
                $queryParam,
            ], $content);
        } else {  // 客户端
            $content = preg_replace([
                '/{{ versions }}/',
                '/{{ name }}/',
                '/{{ annotation }}/'
            ], [
                config('dsshop.versions'),
                ucfirst($db['name']),
                $db['annotation']
            ], $content);
        }
        $content = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $content);
        file_put_contents($path, $content);
    }

    /**
     * 生成插件所需目录
     */
    protected function generatePlugInDirectory()
    {
        $path = $this->path . '/api/app/Http/Controllers/v' . config('dsshop.versions');
        $pathArr = [
            $path . '/Plugin',
            $path . '/Plugin/Admin',
            $path . '/Plugin/Client'
        ];
        foreach ($pathArr as $p) {
            if (!file_exists($p)) {
                mkdir($p, 0777, true);
            }
        }
    }

    /**
     * 生成数据库迁移文件
     * @param $db
     * @throws \Exception
     */
    protected function createDBMigration($db)
    {
        //读取生成的文件内容
        $getLocalMigrations = $this->getLocalMigrations('create_' . $db['name'] . '_table');
        if (!$getLocalMigrations) {
            if (!file_exists($this->pluginPath . '/template/migration.create.ds')) {
                throw new \Exception('缺少migration.create.ds文件', Code::CODE_INEXISTENCE);
            }
            $getLocalMigrations = date("Y") . '_' . date("m") . '_' . date("d") . '_' . date("His") . '_create_' . $db['name'] . '_table.php';
            fopen($this->migrationsPath . '/' . $getLocalMigrations, 'w+');
        }
        $content = file_get_contents($this->pluginPath . '/template/migration.create.ds');
        // 填充数据库迁移表内容
        $newContent = '';
        foreach ($db['attribute'] as $attribute) {
            // 如果字段存在ID，则直接添加主键
            if ($attribute['name'] == 'id') {
                $newContent .= "
            \$table->id();";
            } else {
                // 类型
                $attribute_type = $attribute['type'];
                $unsigned_type = ['tinyInteger', 'smallInteger', 'mediumInteger', 'integer', 'bigInteger'];
                // 当设置了UNSIGNED，且字段支持UNSIGNED
                if ($attribute['attribute'] == 'UNSIGNED' && in_array($attribute['type'], $unsigned_type)) {
                    $attribute_type = 'unsigned' . ucfirst($attribute_type);
                }
                $attribute_length = $attribute['length'] ? ',' . $attribute['length'] : '';
                if (isset($attribute['default'])) {
                    if ($attribute['default'] == 'null') {
                        $attribute_default = '->nullable()';
                    } else {
                        $attribute_default = '->default(' . $attribute['default'] . ')';
                    }
                }
                $attribute_nullable = $attribute['is_empty'] ? '->nullable()' : '';
                $newContent .= "
            \$table->$attribute_type('" . $attribute['name'] . "'$attribute_length)" . $attribute_default . $attribute_nullable . "->comment('" . $attribute['annotation'] . "');";
            }
        }
        if ($db['softDeletes'] == 1) {
            $newContent .= "
            \$table->softDeletes();";
        }
        if ($db['timestamps'] == 1) {
            $newContent .= "
            \$table->timestamps();";
        }
        $content = preg_replace([
            '/{{ class }}/',
            '/{{ table }}/',
            '/{{ field }}/',
            '/{{ annotation }}/',
        ], [
            "Create" . ucfirst($db['name']) . "Table",
            $db['name'],
            $newContent,
            $db['annotation'],
        ], $content);
        file_put_contents($this->migrationsPath . '/' . $getLocalMigrations, $content);
    }

    /**
     * 获取本地数据库迁移文件列表
     * @param $table
     * @return void
     */
    protected function getLocalMigrations($table)
    {
        $return = '';
        foreach ($this->migrations as $d) {
            if (strstr($d, $table)) {
                $return = $d;
                break;
            }
        }
        return $return;
    }
}
