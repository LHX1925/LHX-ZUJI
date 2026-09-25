<?php
namespace app\install\controller;

use think\Controller;
use think\Db;
use think\Request;

class Index extends Controller
{
    /** @var bool 是否已安装（以数据库中的专属标记为准，不再依赖 install.lock 文件） */
    private $installed = false;

    private function installed()
    {
        require_once PATH . 'app/install_check.php';
        return mnbt_db_installed();
    }

    public function _initialize()
    {
        // 不再强制跳转：已安装时向导页会展示「跳过重装」卡片与一键入口，
        // 是否重装由用户在欢迎页自行选择
        $this->installed = $this->installed();
    }

    // 第1步 - 欢迎（已安装时提供跳过重装 + 一键登录/首页入口）
    public function index()
    {
        // 兼容「只用 /install 一种请求形式」的服务器（多级路径 /install/index/xxx 会被 nginx 判定为静态 404）：
        // 支持 /install?step=license|step2|step3|step4|done，由当前动作内部转发到对应动作，
        // 这样整个向导只需要 /install 这一条可访问地址即可走完。
        $step = isset($_GET['step']) ? strtolower(trim((string)$_GET['step'])) : '';
        $map  = [
            'license' => 'license',
            'step2'   => 'step2',
            'step3'   => 'step3',
            'step4'   => 'step4',
            'done'    => 'done',
        ];
        if ($step !== '' && isset($map[$step])) {
            $action = $map[$step];
            return $this->$action();
        }

        return $this->fetch('/default/index', [
            'installed' => $this->installed,
        ]);
    }

    // 第2步 - 许可协议
    public function license()
    {
        return $this->fetch('/default/license');
    }

    // 第3步 - 系统环境监测
    public function step2()
    {
        // 检测环境
        $env = [];
        $env['php'] = PHP_VERSION;
        $env['php_ok'] = version_compare(PHP_VERSION, '7.2.0', '>=');
        $env['pdo'] = extension_loaded('pdo_mysql');
        $env['mysqli'] = extension_loaded('mysqli');
        $env['curl'] = extension_loaded('curl');
        $env['gd'] = extension_loaded('gd');
        $env['openssl'] = extension_loaded('openssl');
        $env['fileinfo'] = extension_loaded('fileinfo');

        // 系统信息
        $env['os'] = function_exists('php_uname') ? @php_uname('s') : 'Unknown';
        $env['server'] = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown';
        $env['upload_max'] = @ini_get('upload_max_filesize') ?: '2M';
        $env['memory_limit'] = @ini_get('memory_limit') ?: '128M';
        $env['max_execution'] = (@ini_get('max_execution_time') ?: '30') . 's';
        // 推荐配置检测（仅提示，不阻塞）
        $env['memory_ok'] = $this->parseSize($env['memory_limit']) >= 128 * 1024 * 1024;
        $env['upload_ok'] = $this->parseSize($env['upload_max']) >= 8 * 1024 * 1024;
        $env['exec_ok'] = (int)($env['max_execution']) >= 30;

        // 目录权限检测（列表结构：直接给出相对路径与说明，避免视图里只显示序号）
        // 数组中含文件（database.php），必须先判 file_exists，否则 mkdir 会因 "File exists" 产生警告
        $dirs = [
            'app/database.php'      => '数据库配置文件',
            'runtime'               => '运行时目录',
            'runtime/log'           => '日志目录',
            'public/static'         => '静态资源目录',
            'public/static/upload'  => '上传目录',
            'public/static/map'     => '地图资源目录',
        ];
        $dirPerm = [];
        foreach ($dirs as $rel => $label) {
            $abs = PATH . $rel;
            if (!file_exists($abs) && !is_dir($abs)) {
                @mkdir($abs, 0755, true);
            }
            $dirPerm[] = [
                'path' => $rel,
                'label' => $label,
                'ok' => is_writable($abs),
            ];
        }

        $dirAllOk = true;
        foreach ($dirPerm as $item) {
            if (!$item['ok']) {
                $dirAllOk = false;
                break;
            }
        }

        $allOk = $env['php_ok'] && ($env['pdo'] || $env['mysqli']) && $env['curl'] && $env['openssl']
            && $env['gd'] && $env['fileinfo'] && $dirAllOk;

        return $this->fetch('/default/step2', [
            'env' => $env,
            'dirPerm' => $dirPerm,
            'dirAllOk' => $dirAllOk,
            'allOk' => $allOk,
        ]);
    }

    /**
     * 从 POST 中安全读取标量字符串（数组/对象一律回退默认值）
     * 避免框架 input() 的字符串强转对数组值抛 "variable type error: array"
     */
    private static function postStr($key, $default = '')
    {
        if (!isset($_POST[$key])) {
            return $default;
        }
        $v = $_POST[$key];
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    /**
     * 将 PHP ini 中的容量字符串（如 8M / 128M）解析为字节
     */
    private function parseSize($size) {
        $size = trim((string)$size);
        $unit = strtolower(substr($size, -1));
        $val = (int)$size;
        switch ($unit) {
            case 'g': $val *= 1024 * 1024 * 1024; break;
            case 'm': $val *= 1024 * 1024; break;
            case 'k': $val *= 1024; break;
        }
        return $val;
    }

    // 第4步 - 数据库配置
    public function step3()
    {
        if (Request::instance()->isPost()) {
            try {
                // 安装器独立场景：直接安全读取 $_POST 标量，
                // 避免框架 input() 默认字符串强转遇到数组值时抛 "variable type error: array"
                $hostname = self::postStr('hostname', '127.0.0.1');
                $hostport = self::postStr('hostport', '3306');
                $database = self::postStr('database', '');
                $username = self::postStr('username', '');
                $password = self::postStr('password', '');
                $prefix   = self::postStr('prefix', 'dd_');
                $keepData = self::postStr('keep_data', '0') == '1';
                $skipDb   = self::postStr('skip_db', '0') == '1';

                if ($database == '' || $username == '') {
                    return json(['code' => -1, 'msg' => '数据库名和用户名不能为空!']);
                }

                // 测试数据库连接
                try {
                    $dsn = "mysql:host={$hostname};port={$hostport};charset=utf8";
                    $pdo = new \PDO($dsn, $username, $password);
                    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                    // 检查数据库是否存在，不存在则创建
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` DEFAULT CHARACTER SET utf8");
                    $pdo->exec("USE `{$database}`");
                } catch (\PDOException $e) {
                    return json(['code' => -1, 'msg' => '数据库连接失败: ' . $e->getMessage()]);
                }

                // 写入数据库配置文件
                $configContent = <<<'PHP'
<?php

return [
    // 数据库类型
    'type'            => 'mysql',
    // 服务器地址
    'hostname'        => '{hostname}',
    // 数据库名
    'database'        => '{database}',
    // 用户名
    'username'        => '{username}',
    // 密码
    'password'        => '{password}',
    // 端口
    'hostport'        => '{hostport}',
    // 连接dsn
    'dsn'             => '',
    // 数据库连接参数
    'params'          => [],
    // 数据库编码默认采用utf8
    'charset'         => 'utf8',
    // 数据库表前缀
    'prefix'          => '{prefix}',
    // 数据库调试模式
    'debug'           => false,
    // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
    'deploy'          => 0,
    // 数据库读写是否分离 主从式有效
    'rw_separate'     => false,
    // 读写分离后 主服务器数量
    'master_num'      => 1,
    // 指定从服务器序号
    'slave_no'        => '',
    // 自动读取主库数据
    'read_master'     => false,
    // 是否严格检查字段是否存在
    'fields_strict'   => true,
    // 数据集返回类型
    'resultset_type'  => 'array',
    // 自动写入时间戳字段
    'auto_timestamp'  => false,
    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',
    // 是否需要进行SQL性能分析
    'sql_explain'     => false,
];
PHP;

                $configContent = str_replace(
                    ['{hostname}', '{database}', '{username}', '{password}', '{hostport}', '{prefix}'],
                    [$hostname, $database, $username, $password, $hostport, $prefix],
                    $configContent
                );

                $dbFile = PATH . 'app/database.php';
                // 预先检测可写性，给出明确指引，避免 ThinkPHP 把 WARNING 转成异常后
                // 前端只看到「安装完成处理失败: file_put_contents ... Permission denied」这类裸错误
                if (!is_writable($dbFile)) {
                    $parent = dirname($dbFile);
                    if (!is_writable($parent)) {
                        return json([
                            'code' => -1,
                            'msg'  => '无法写入数据库配置文件：目录 ' . $parent . ' 不可写。请在服务器执行 chmod -R 755 ' . $parent . ' 后再试',
                        ]);
                    }
                    return json([
                        'code' => -1,
                        'msg'  => '无法写入数据库配置文件 app/database.php（权限不足）。请在服务器执行：chmod 666 ' . $dbFile . '（或在宝塔文件管理器右键该文件→权限→设为 666）后再点下一步',
                    ]);
                }
                // 用 @ 抑制 WARNING，配合返回值判断，避免错误处理器把失败转成异常
                $result = @file_put_contents($dbFile, $configContent);
                if ($result === false) {
                    return json([
                        'code' => -1,
                        'msg'  => '数据库配置文件写入失败（可能仍是无写权限）。请执行 chmod 666 ' . $dbFile . ' 后重试',
                    ]);
                }

                // 跳过数据库表创建（仅写入配置文件）
                if ($skipDb) {
                    return json(['code' => 1, 'msg' => '数据库配置已保存，跳过表创建!']);
                }

                // 创建数据表
                try {
                    $this->createTables($pdo, $prefix, $keepData);
                } catch (\Exception $e) {
                    return json(['code' => -1, 'msg' => '创建数据表失败: ' . $e->getMessage()]);
                }

                // 保留数据模式：不清空已有业务数据，但仍进入第 5 步由用户重新设置管理员账号
                // （不再跳过管理员设置）
                return json([
                    'code'       => 1,
                    'msg'        => $keepData ? '数据库配置成功，已保留原有数据!' : '数据库配置成功!',
                    'skip_admin' => 0,
                ]);
            } catch (\InvalidArgumentException $e) {
                // 防御：框架输入层类型异常在此兜底，记录请求字段类型便于排查
                $types = [];
                foreach ($_POST as $k => $v) { $types[] = $k . ':' . gettype($v); }
                @file_put_contents(
                    (defined('LOG_PATH') ? LOG_PATH : (PATH . 'runtime/log/')) . 'install_debug.log',
                    date('Y-m-d H:i:s') . ' step3 InvalidArgumentException=' . $e->getMessage() . ' post=' . implode(',', $types) . "\n",
                    FILE_APPEND
                );
                return json(['code' => -1, 'msg' => '请求参数异常，请刷新页面后重试']);
            }
        }

        $skip = isset($_GET['skip']) && is_scalar($_GET['skip']) ? $_GET['skip'] : '0';
        $skipDb = $skip == '1';
        return $this->fetch('/default/step3', ['skipDb' => $skipDb]);
    }

    // 第5步 - 等待安装（管理员设置 + 开始安装）
    public function step4()
    {
        if (Request::instance()->isPost()) {
            $adminUser = self::postStr('admin_user', '');
            $adminPassword = self::postStr('admin_password', '');
            $adminName = self::postStr('admin_name', '管理员');
            $adminQQ = self::postStr('admin_qq', '');
            $adminMail = self::postStr('admin_mail', '');
            $siteName = self::postStr('site_name', '我的主机站');
            $keepData = self::postStr('keep_data', '0') == '1';

            if ($adminUser == '' || $adminPassword == '') {
                return json(['code' => -1, 'msg' => '管理员账号和密码不能为空!']);
            }

            return json($this->finalizeInstall([
                'admin_user'     => $adminUser,
                'admin_password' => $adminPassword,
                'admin_name'     => $adminName,
                'admin_qq'       => $adminQQ,
                'admin_mail'     => $adminMail,
                'site_name'      => $siteName,
            ], $keepData));
        }

        // step3 勾选「保留现有数据」时会带 ?keep=1，用于第 5 步提示与提交时回传
        $keep = isset($_GET['keep']) && is_scalar($_GET['keep']) ? (string) $_GET['keep'] : '0';
        return $this->fetch('/default/step4', ['keepData' => $keep === '1']);
    }

    /**
     * 完成安装（保底逻辑 + 写入数据库已安装标记）
     * 传入管理员数据时：按表单内容创建或覆盖重置管理员账号与站点名称
     *   —— 勾选「保留现有数据」时同样适用，业务数据不清空，但管理员按此处填写的内容重设
     * 传入 null 时：仅确保核心表/角色/管理员/站点/授权存在，不覆盖已有账号
     * @param array|null $adminData 管理员数据
     * @param bool $keepData 是否为保留数据模式（仅用于提示文案，业务数据由 createTables 保证不删）
     * @return array
     */
    private function finalizeInstall($adminData = null, $keepData = false)
    {
        try {
            // 重新加载数据库配置（step3已写入database.php）
            $dbConfig = include PATH . 'app/database.php';
            \think\Config::set('database', $dbConfig);
            // 清除已有的数据库连接实例，确保使用新配置
            \think\Db::clear();

            $prefix = $dbConfig['prefix'];

            // 如果跳过了数据库安装或表不存在，先确保核心表存在
            $this->ensureCoreTables($prefix);

            // 确保管理员角色存在
            $roleExists = Db::name('admin_role')->where('id', 1)->find();
            if (!$roleExists) {
                Db::name('admin_role')->insert([
                    'id'          => 1,
                    'name'        => '超级管理员',
                    'permissions' => json_encode(['all']),
                    'description' => '拥有所有权限',
                    'created_at'  => time(),
                ]);
            }

            // 管理员账号
            $adminExists = Db::name('admin')->where('id', 1)->find();
            if (is_array($adminData) && !empty($adminData['admin_user'])) {
                // 新安装：创建或更新管理员
                if (!$adminExists) {
                    Db::name('admin')->insert([
                        'id'         => 1,
                        'name'       => $adminData['admin_name'],
                        'user'       => $adminData['admin_user'],
                        'password'   => password_hash($adminData['admin_password'], PASSWORD_DEFAULT),
                        'qq'         => $adminData['admin_qq'],
                        'mail'       => $adminData['admin_mail'],
                        'is_super'   => 1,
                        'role_id'    => 1,
                        'status'     => 1,
                        'created_at' => time(),
                    ]);
                } else {
                    Db::name('admin')->where('id', 1)->update([
                        'name' => $adminData['admin_name'],
                        'user' => $adminData['admin_user'],
                        'password' => password_hash($adminData['admin_password'], PASSWORD_DEFAULT),
                        'qq' => $adminData['admin_qq'],
                        'mail' => $adminData['admin_mail'],
                        'is_super' => 1,
                        'role_id' => 1,
                        'status' => 1,
                        'created_at' => time(),
                    ]);
                }
            } else {
                // 保留数据：仅确保管理员存在，不覆盖已有账号密码
                if (!$adminExists) {
                    Db::name('admin')->insert([
                        'id'         => 1,
                        'name'       => '管理员',
                        'user'       => 'admin',
                        'password'   => password_hash('admin123', PASSWORD_DEFAULT),
                        'is_super'   => 1,
                        'role_id'    => 1,
                        'status'     => 1,
                        'created_at' => time(),
                    ]);
                }
            }

            // 网站配置
            $siteName = is_array($adminData) && !empty($adminData['site_name']) ? $adminData['site_name'] : '';
            $webExists = Db::name('web')->where('id', 1)->find();
            if (!$webExists) {
                // 无论新装还是保留数据，缺少站点配置都补一条默认
                $defaultName = $siteName !== '' ? $siteName : '我的主机站';
                Db::name('web')->insert([
                    'id'          => 1,
                    'name'        => $defaultName,
                    'description' => $defaultName . ',提供快速、稳定、优质的虚拟主机服务！',
                    'keywords'    => $defaultName . ',虚拟主机,主机销售',
                    'favicon'     => '/favicon.ico',
                    'template'    => 'default',
                    'admintemplate' => 'default',
                    'wh'          => '0',
                    'global_datacenters' => '[]',
                    'templateset' => '[]',
                ]);
            } elseif ($siteName !== '') {
                // 仅新安装时更新站点名称，保留数据时不动
                Db::name('web')->where('id', 1)->update([
                    'name' => $siteName,
                    'description' => $siteName . ',提供快速、稳定、优质的虚拟主机服务！',
                    'keywords' => $siteName . ',虚拟主机,主机销售',
                ]);
            }

            // 添加固定授权密钥记录
            $domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
            $ip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '127.0.0.1';
            if (empty($ip) || $ip == '::1') {
                $ip = '127.0.0.1';
            }
            $authKey = 'LHXYYDS';
            $sqExists = Db::name('sq')->where('domain', $domain)->where('ip', $ip)->find();
            if (!$sqExists) {
                Db::name('sq')->insert([
                    'domain' => $domain,
                    'qq'     => $authKey,
                    'ip'     => $ip,
                    'time'   => time(),
                ]);
            }

            // 写入数据库专属已安装标记（不再生成 install.lock 文件）
            $this->markInstalled($prefix);
            // 清掉可能存在的旧锁文件与状态缓存
            if (is_file(PATH . 'install.lock')) {
                @unlink(PATH . 'install.lock');
            }
            if (is_file(PATH . 'runtime/install_state.cache')) {
                @unlink(PATH . 'runtime/install_state.cache');
            }

            // 注意：这里必须返回数组，由调用方统一 json() 输出。
            // 之前返回 json() 对象又被 step4 再包一层 json()，前端拿到的就是空对象，表现为「未知错误」。
            return ['code' => 1, 'msg' => $keepData ? '安装完成，已保留原有数据!' : '安装完成!'];
        } catch (\Exception $e) {
            $this->logInstallError('finalizeInstall', $e);
            return ['code' => -1, 'msg' => '安装完成处理失败: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            $this->logInstallError('finalizeInstall', $e);
            return ['code' => -1, 'msg' => '安装完成处理失败: ' . $e->getMessage()];
        }
    }

    /**
     * 安装过程中的异常落盘，便于线上排查（runtime/log/install_debug.log）
     */
    private function logInstallError($stage, $e)
    {
        $dir = (defined('LOG_PATH') ? LOG_PATH : (PATH . 'runtime/log/'));
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = date('Y-m-d H:i:s') . ' [' . $stage . '] ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
        @file_put_contents($dir . 'install_debug.log', $line, FILE_APPEND);
    }

    // 安装完成
    public function done()
    {
        if (!$this->installed()) {
            return $this->redirect('/install');
        }
        return $this->fetch('/default/done');
    }

    /**
     * 写入数据库中专属的已安装标记表
     * 供 public/index.php 与安装器判定「已安装」，替代 install.lock 文件
     */
    private function markInstalled($prefix)
    {
        Db::execute("CREATE TABLE IF NOT EXISTS `{$prefix}install` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `marker` varchar(64) NOT NULL DEFAULT 'installed',
  `version` varchar(32) NOT NULL DEFAULT '',
  `installed_at` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $exists = Db::name('install')->where('id', 1)->find();
        if (!$exists) {
            Db::name('install')->insert([
                'id'          => 1,
                'marker'      => 'installed',
                'version'     => '1.0',
                'installed_at'=> time(),
            ]);
        }
    }

    // 创建数据表
    private function createTables($pdo, $prefix, $keepData = false)
    {
        $sql = $this->getInstallSql($prefix);
        $pdo->exec("SET NAMES utf8");
        // PDO::exec 一次只能执行一条 SQL，需逐条执行；统一换行符避免 Windows 下
        // \r\n 导致 explode(";\n") 失效、多语句被当一条执行从而触发 MySQL 1064
        $statements = $this->splitSql($sql);
        foreach ($statements as $stmt) {
            // 保留数据模式跳过 DROP TABLE 语句
            if ($keepData && stripos($stmt, 'DROP TABLE') === 0) {
                continue;
            }
            $pdo->exec($stmt);
        }
    }

    /**
     * 将安装 SQL 拆成单条语句（兼容 \r\n / \r / \n 换行）
     * @param string $sql
     * @return array
     */
    private function splitSql($sql)
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $parts = preg_split('/;\s*\n/', $sql);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out;
    }

    // 确保核心表存在（用于跳过数据库安装或表缺失时兜底）
    private function ensureCoreTables($prefix)
    {
        $coreSql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$prefix}admin_role` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '角色名称',
  `permissions` text COMMENT '权限JSON',
  `description` varchar(255) DEFAULT '' COMMENT '角色描述',
  `created_at` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `qq` varchar(100) NOT NULL,
  `user` varchar(100) NOT NULL,
  `password` varchar(288) NOT NULL,
  `role_id` int(11) DEFAULT 0,
  `is_super` tinyint(1) DEFAULT 0,
  `status` tinyint(1) DEFAULT 1,
  `created_at` int(11) DEFAULT 0,
  `mail` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}web` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text,
  `keywords` text,
  `favicon` text,
  `template` varchar(288) NOT NULL DEFAULT 'default',
  `admintemplate` varchar(288) NOT NULL DEFAULT 'default',
  `wh` varchar(10) NOT NULL DEFAULT '0',
  `whxx` text,
  `email` varchar(100) DEFAULT '0',
  `emailchar` varchar(500) NOT NULL DEFAULT '',
  `emailsecure` varchar(100) NOT NULL DEFAULT '',
  `emailport` varchar(500) NOT NULL DEFAULT '',
  `emailhost` varchar(500) NOT NULL DEFAULT '',
  `emailname` varchar(500) NOT NULL DEFAULT '',
  `emailpass` varchar(500) NOT NULL DEFAULT '',
  `emailauth` varchar(500) NOT NULL DEFAULT '',
  `affdiscount` varchar(100) NOT NULL DEFAULT '',
  `affwithdrawal` varchar(100) NOT NULL DEFAULT '',
  `cronzz` varchar(100) NOT NULL DEFAULT '',
  `cronsc` varchar(100) NOT NULL DEFAULT '',
  `paycron` varchar(100) NOT NULL DEFAULT '',
  `tickcron` varchar(100) NOT NULL DEFAULT '',
  `zcyxyz` varchar(100) NOT NULL DEFAULT '0',
  `yxdl` varchar(288) NOT NULL DEFAULT '0',
  `logo` text,
  `logo_icon` varchar(255) NOT NULL DEFAULT 'fas fa-server',
  `icp` varchar(255) NOT NULL DEFAULT '',
  `business_license` varchar(255) NOT NULL DEFAULT '',
  `police_beian` varchar(255) NOT NULL DEFAULT '',
  `telecom_license` varchar(255) NOT NULL DEFAULT '',
  `qq_group` varchar(255) NOT NULL DEFAULT '' COMMENT 'QQ官方群号或链接',
  `global_datacenters` text,
  `popup_notice` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用弹窗公告',
  `popup_title` varchar(255) NOT NULL DEFAULT '' COMMENT '弹窗公告标题',
  `popup_content` text COMMENT '弹窗公告 HTML 内容',
  `templateset` text,
  `host_auto_create` varchar(10) DEFAULT '0' COMMENT '0=manual 1=auto',
  `host_auto_create_delay` varchar(50) DEFAULT '0' COMMENT 'minutes',
  `bg_image` varchar(500) DEFAULT '' COMMENT '全局背景图URL',
  `bg_type` varchar(20) DEFAULT 'image' COMMENT '背景类型：image/video/gif',
  `bg_video_loop` tinyint(1) NOT NULL DEFAULT '1' COMMENT '视频背景是否循环播放',
  `bg_video_muted` tinyint(1) NOT NULL DEFAULT '1' COMMENT '视频背景是否静音',
  `bg_blur` int(2) DEFAULT '0' COMMENT '背景图模糊程度(0-10)',
  `bg_gradient` varchar(50) DEFAULT 'default' COMMENT '预设渐变色',
  `bg_images` text COMMENT '轮播背景图URL列表（逗号分隔）',
  `bg_switch_interval` int(6) DEFAULT '0' COMMENT '背景图轮播间隔(秒，0=不轮播)',
  `glass_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用液态玻璃主题:0关闭 1开启',
  `glass_opacity` int(3) DEFAULT '72' COMMENT '液态玻璃透明度(30-100)',
  `loading_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '加载动画开关:0关闭 1开启',
  `loading_logo` varchar(500) DEFAULT '' COMMENT '加载动画专属LOGO',
  `loading_brand` varchar(100) DEFAULT '' COMMENT '加载动画品牌英文名',
  `loading_text` varchar(200) DEFAULT '' COMMENT '加载动画提示文字',
  `loading_subtext` varchar(200) DEFAULT '' COMMENT '加载动画副文字',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}sq` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` text NOT NULL,
  `qq` varchar(288) NOT NULL,
  `ip` varchar(288) NOT NULL,
  `time` varchar(288) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `user` varchar(200) NOT NULL,
  `password` varchar(255) NOT NULL,
  `money` varchar(200) NOT NULL DEFAULT '0',
  `mail` varchar(300) NOT NULL,
  `qq` varchar(100) NOT NULL,
  `address` text NOT NULL,
  `aff` varchar(100) NOT NULL,
  `affmoney` varchar(100) NOT NULL DEFAULT '0',
  `upperid` varchar(100) NOT NULL,
  `time` varchar(200) NOT NULL,
  `state` varchar(10) NOT NULL DEFAULT '1',
  `ban_time` int(11) NOT NULL DEFAULT 0 COMMENT '封禁到期时间戳，0=未封禁',
  `ban_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '封禁原因',
  `last_login_time` int(11) NOT NULL DEFAULT 0 COMMENT '最后登录时间戳',
  `last_login_ip` varchar(255) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `last_login_region` varchar(50) NOT NULL DEFAULT '' COMMENT '最后登录地区（省份）',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

        $statements = $this->splitSql($coreSql);
        foreach ($statements as $stmt) {
            Db::execute($stmt);
        }
    }

    // 获取安装SQL
    private function getInstallSql($prefix)
    {
        return <<<SQL
DROP TABLE IF EXISTS `{$prefix}admin`;

CREATE TABLE IF NOT EXISTS `{$prefix}admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `qq` varchar(100) NOT NULL,
  `user` varchar(100) NOT NULL,
  `password` varchar(288) NOT NULL,
  `role_id` int(11) DEFAULT 0,
  `is_super` tinyint(1) DEFAULT 0,
  `status` tinyint(1) DEFAULT 1,
  `created_at` int(11) DEFAULT 0,
  `mail` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `{$prefix}admin` (`id`, `name`, `qq`, `user`, `password`, `role_id`, `is_super`, `status`, `created_at`, `mail`) VALUES
(1, '管理员', '', 'admin', '', 1, 1, 1, UNIX_TIMESTAMP(), '');

DROP TABLE IF EXISTS `{$prefix}admin_role`;

CREATE TABLE IF NOT EXISTS `{$prefix}admin_role` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '角色名称',
  `permissions` text COMMENT '权限JSON',
  `description` varchar(255) DEFAULT '' COMMENT '角色描述',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `{$prefix}admin_role` (`id`, `name`, `permissions`, `description`) VALUES
(1, '超级管理员', '["all"]', '拥有所有权限');

CREATE TABLE IF NOT EXISTS `{$prefix}affsymoney` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `information` text NOT NULL,
  `money` varchar(100) NOT NULL,
  `userid` varchar(100) NOT NULL,
  `time` varchar(50) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}afftxjl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `information` text NOT NULL,
  `money` varchar(100) NOT NULL,
  `state` varchar(10) NOT NULL,
  `userid` varchar(100) NOT NULL,
  `time` varchar(50) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}announcement` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` text NOT NULL,
  `information` text NOT NULL,
  `time` varchar(288) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}cart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product` varchar(200) NOT NULL,
  `name` varchar(200) NOT NULL,
  `content` text NOT NULL,
  `money` varchar(200) NOT NULL DEFAULT '0',
  `cycle` varchar(100) NOT NULL,
  `firstmo` varchar(288) NOT NULL DEFAULT '0',
  `serverid` varchar(200) NOT NULL,
  `upgrade` varchar(10) NOT NULL DEFAULT '0',
  `upgrades` text,
  `buy` varchar(10) NOT NULL DEFAULT '0',
  `hide` varchar(10) NOT NULL DEFAULT '0',
  `sort` int(11) NOT NULL DEFAULT '0',
  `renew` varchar(100) NOT NULL DEFAULT '0',
  `limits` varchar(288) NOT NULL DEFAULT '0',
  `inventory` varchar(100) NOT NULL DEFAULT '0',
  `data1` text, `data2` text, `data3` text, `data4` text, `data5` text,
  `data6` text, `data7` text, `data8` text, `data9` text, `data10` text,
  `data11` text, `data12` text, `data13` text, `data14` text, `data15` text,
  `data16` text, `data17` text, `data18` text, `data19` text, `data20` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ordernumber` varchar(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `user` varchar(300) DEFAULT NULL,
  `password` varchar(320) DEFAULT NULL,
  `userid` varchar(100) NOT NULL,
  `cartid` varchar(100) NOT NULL,
  `atime` varchar(300) NOT NULL,
  `ztime` varchar(300) NOT NULL,
  `state` varchar(200) NOT NULL,
  `auto_create_at` varchar(50) DEFAULT '0' COMMENT '自动开通时间戳',
  `data1` text, `data2` text, `data3` text, `data4` text, `data5` text,
  `data6` text, `data7` text, `data8` text, `data9` text, `data10` text,
  `data11` text, `data12` text, `data13` text, `data14` text, `data15` text,
  `data16` text, `data17` text, `data18` text, `data19` text, `data20` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}pay` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(288) NOT NULL,
  `ordernumber` varchar(288) NOT NULL,
  `pay` varchar(288) NOT NULL,
  `money` varchar(288) NOT NULL,
  `userid` varchar(100) NOT NULL,
  `time` varchar(288) NOT NULL,
  `state` varchar(288) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}pays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(288) NOT NULL,
  `plugins` varchar(288) NOT NULL,
  `state` varchar(10) NOT NULL DEFAULT '0',
  `data` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}host_transfer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL COMMENT '订单ID',
  `userid` int(11) NOT NULL COMMENT '转让方用户ID',
  `target_userid` int(11) NOT NULL DEFAULT '0' COMMENT '指定接收用户ID(0=公开)',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '转让价格',
  `original_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '原购买价格',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=待转让 1=已完成 2=已驳回 3=已取消',
  `buyer_userid` int(11) NOT NULL DEFAULT '0' COMMENT '购买方用户ID',
  `email_verified` tinyint(1) NOT NULL DEFAULT '0' COMMENT '邮箱验证状态',
  `contact_info` varchar(500) DEFAULT '' COMMENT '卖家联系方式',
  `reject_reason` varchar(500) DEFAULT '' COMMENT '驳回原因',
  `created_at` int(11) NOT NULL DEFAULT '0',
  `updated_at` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_userid` (`userid`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}host_transfer_message` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL COMMENT '转让ID',
  `sender_id` int(11) NOT NULL COMMENT '发送方用户ID',
  `receiver_id` int(11) NOT NULL COMMENT '接收方用户ID',
  `content` text NOT NULL COMMENT '消息内容',
  `is_read` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=未读 1=已读',
  `created_at` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_transfer_id` (`transfer_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_receiver_id` (`receiver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}product` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `introduce` text NOT NULL,
  `hide` varchar(10) NOT NULL DEFAULT '0',
  `sort` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}server` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `host` varchar(100) NOT NULL,
  `ip` varchar(200) NOT NULL,
  `security` text NOT NULL,
  `port` varchar(200) NOT NULL,
  `ssl` varchar(200) NOT NULL DEFAULT '0',
  `user` varchar(300) NOT NULL,
  `password` varchar(300) NOT NULL,
  `serverplugins` varchar(288) NOT NULL,
  `data1` text, `data2` text, `data3` text, `data4` text, `data5` text,
  `data6` text, `data7` text, `data8` text, `data9` text, `data10` text,
  `data11` text, `data12` text, `data13` text, `data14` text, `data15` text,
  `data16` text, `data17` text, `data18` text, `data19` text, `data20` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}sq` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `domain` text NOT NULL,
  `qq` varchar(288) NOT NULL,
  `ip` varchar(288) NOT NULL,
  `time` varchar(288) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}ticket` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(288) NOT NULL,
  `content` mediumtext NOT NULL,
  `userid` varchar(100) NOT NULL,
  `time` varchar(100) NOT NULL,
  `state` varchar(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}transaction` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` varchar(288) NOT NULL,
  `content` text NOT NULL,
  `time` varchar(288) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$prefix}transferrecord` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` varchar(100) NOT NULL,
  `record` mediumtext NOT NULL,
  `time` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `{$prefix}user`;

CREATE TABLE IF NOT EXISTS `{$prefix}user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `user` varchar(200) NOT NULL,
  `password` varchar(255) NOT NULL,
  `money` varchar(200) NOT NULL DEFAULT '0',
  `mail` varchar(300) NOT NULL,
  `qq` varchar(100) NOT NULL,
  `address` text NOT NULL,
  `aff` varchar(100) NOT NULL,
  `affmoney` varchar(100) NOT NULL DEFAULT '0',
  `upperid` varchar(100) NOT NULL,
  `time` varchar(200) NOT NULL,
  `state` varchar(10) NOT NULL DEFAULT '1',
  `ban_time` int(11) NOT NULL DEFAULT 0 COMMENT '封禁到期时间戳，0=未封禁',
  `ban_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '封禁原因',
  `last_login_time` int(11) NOT NULL DEFAULT 0 COMMENT '最后登录时间戳',
  `last_login_ip` varchar(255) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `last_login_region` varchar(50) NOT NULL DEFAULT '' COMMENT '最后登录地区（省份）',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `{$prefix}web`;

CREATE TABLE IF NOT EXISTS `{$prefix}web` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text,
  `keywords` text,
  `favicon` text,
  `template` varchar(288) NOT NULL DEFAULT 'default',
  `admintemplate` varchar(288) NOT NULL DEFAULT 'default',
  `wh` varchar(10) NOT NULL DEFAULT '0',
  `whxx` text,
  `email` varchar(100) DEFAULT '0',
  `emailchar` varchar(500) NOT NULL DEFAULT '',
  `emailsecure` varchar(100) NOT NULL DEFAULT '',
  `emailport` varchar(500) NOT NULL DEFAULT '',
  `emailhost` varchar(500) NOT NULL DEFAULT '',
  `emailname` varchar(500) NOT NULL DEFAULT '',
  `emailpass` varchar(500) NOT NULL DEFAULT '',
  `emailauth` varchar(500) NOT NULL DEFAULT '',
  `affdiscount` varchar(100) NOT NULL DEFAULT '',
  `affwithdrawal` varchar(100) NOT NULL DEFAULT '',
  `cronzz` varchar(100) NOT NULL DEFAULT '',
  `cronsc` varchar(100) NOT NULL DEFAULT '',
  `paycron` varchar(100) NOT NULL DEFAULT '',
  `tickcron` varchar(100) NOT NULL DEFAULT '',
  `zcyxyz` varchar(100) NOT NULL DEFAULT '0',
  `yxdl` varchar(288) NOT NULL DEFAULT '0',
  `logo` text,
  `logo_icon` varchar(255) NOT NULL DEFAULT 'fas fa-server',
  `icp` varchar(255) NOT NULL DEFAULT '',
  `business_license` varchar(255) NOT NULL DEFAULT '',
  `police_beian` varchar(255) NOT NULL DEFAULT '',
  `telecom_license` varchar(255) NOT NULL DEFAULT '',
  `qq_group` varchar(255) NOT NULL DEFAULT '' COMMENT 'QQ官方群号或链接',
  `global_datacenters` text,
  `popup_notice` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用弹窗公告',
  `popup_title` varchar(255) NOT NULL DEFAULT '' COMMENT '弹窗公告标题',
  `popup_content` text COMMENT '弹窗公告 HTML 内容',
  `templateset` text,
  `host_auto_create` varchar(10) DEFAULT '0' COMMENT '0=manual 1=auto',
  `host_auto_create_delay` varchar(50) DEFAULT '0' COMMENT 'minutes',
  `bg_image` varchar(500) DEFAULT '' COMMENT '全局背景图URL',
  `bg_type` varchar(20) DEFAULT 'image' COMMENT '背景类型：image/video/gif',
  `bg_video_loop` tinyint(1) NOT NULL DEFAULT '1' COMMENT '视频背景是否循环播放',
  `bg_video_muted` tinyint(1) NOT NULL DEFAULT '1' COMMENT '视频背景是否静音',
  `bg_blur` int(2) DEFAULT '0' COMMENT '背景图模糊程度(0-10)',
  `bg_gradient` varchar(50) DEFAULT 'default' COMMENT '预设渐变色',
  `bg_images` text COMMENT '轮播背景图URL列表（逗号分隔）',
  `bg_switch_interval` int(6) DEFAULT '0' COMMENT '背景图轮播间隔(秒，0=不轮播)',
  `glass_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用液态玻璃主题:0关闭 1开启',
  `glass_opacity` int(3) DEFAULT '72' COMMENT '液态玻璃透明度(30-100)',
  `loading_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '加载动画开关:0关闭 1开启',
  `loading_logo` varchar(500) DEFAULT '' COMMENT '加载动画专属LOGO',
  `loading_brand` varchar(100) DEFAULT '' COMMENT '加载动画品牌英文名',
  `loading_text` varchar(200) DEFAULT '' COMMENT '加载动画提示文字',
  `loading_subtext` varchar(200) DEFAULT '' COMMENT '加载动画副文字',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `{$prefix}web` (`id`, `name`, `description`, `keywords`, `favicon`, `template`, `admintemplate`, `global_datacenters`, `templateset`) VALUES
(1, '我的主机站', '', '', '/favicon.ico', 'default', 'default', '[]', '[]');

CREATE TABLE IF NOT EXISTS `{$prefix}violation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT 0 COMMENT '用户ID',
  `username` varchar(100) DEFAULT '' COMMENT '用户名',
  `title` varchar(255) DEFAULT '' COMMENT '违规标题',
  `content` text COMMENT '违规内容描述',
  `reason` text COMMENT '处罚原因',
  `punishment` varchar(255) DEFAULT '' COMMENT '处罚措施',
  `images` text COMMENT '证据图片',
  `status` tinyint(1) DEFAULT 1 COMMENT '1=公示 0=隐藏',
  `create_time` int(11) DEFAULT 0,
  `update_time` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `rsthemes_displays` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `rsthemes_displays` (`id`, `name`, `active`, `created_at`, `updated_at`) VALUES
(1, 'Default', 1, NOW(), NOW());
SQL;
    }
}
