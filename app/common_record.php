<?php
/**
 * 系统重要记录中心
 * ------------------------------------------------------------
 * 设计原则：只写入「重要且有追溯价值」的数据，避免写入垃圾数据。
 *   记录：用户信息变更 / 后台登录 / 后台修改与配置 / 订单与支付 /
 *         资金变动 / 安全事件 / 系统每日重要统计
 *   不记录：前台用户的登录 IP、登录设备、浏览器 UA 等无关信息
 *
 * 所有类别均可由后台「记录设置」单独开关控制。
 */

if (!function_exists('ensure_sys_record_table')) {
    /**
     * 确保系统记录表存在
     */
    function ensure_sys_record_table() {
        static $done = false;
        if ($done) return true;
        try {
            $prefix = \think\Db::getConfig('prefix');
            $prefix = is_string($prefix) ? $prefix : '';
            $table  = "{$prefix}sys_record";
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `category` varchar(30) NOT NULL DEFAULT '' COMMENT '记录类别',
                `level` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1普通 2重要 3警告',
                `title` varchar(200) NOT NULL DEFAULT '' COMMENT '记录标题',
                `summary` varchar(500) NOT NULL DEFAULT '' COMMENT '摘要',
                `detail` text COMMENT '详情JSON',
                `operator_type` varchar(20) NOT NULL DEFAULT '' COMMENT 'admin/user/system',
                `operator_id` int(11) NOT NULL DEFAULT 0 COMMENT '操作者ID',
                `operator_name` varchar(60) NOT NULL DEFAULT '' COMMENT '操作者名称',
                `target_type` varchar(30) NOT NULL DEFAULT '' COMMENT '目标类型',
                `target_id` int(11) NOT NULL DEFAULT 0 COMMENT '目标ID',
                `ip` varchar(60) NOT NULL DEFAULT '' COMMENT '操作IP',
                `create_time` int(11) NOT NULL DEFAULT 0,
                `date` varchar(10) NOT NULL DEFAULT '' COMMENT '日期Y-m-d',
                PRIMARY KEY (`id`),
                KEY `idx_category` (`category`),
                KEY `idx_create_time` (`create_time`),
                KEY `idx_operator` (`operator_type`,`operator_id`),
                KEY `idx_target` (`target_type`,`target_id`),
                KEY `idx_level` (`level`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统重要记录'");
            $done = true;
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('ensure_sys_record_config_table')) {
    function ensure_sys_record_config_table() {
        static $done = false;
        if ($done) return true;
        try {
            $prefix = \think\Db::getConfig('prefix');
            $prefix = is_string($prefix) ? $prefix : '';
            $table  = "{$prefix}sys_record_config";
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
                `k` varchar(50) NOT NULL,
                `v` varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`k`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统记录开关'");
            $done = true;
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('sys_record_categories')) {
    /**
     * 记录类别定义（顺序即后台展示顺序）
     * @return array [key => ['name'=>..., 'desc'=>..., 'default'=>1/0]]
     */
    function sys_record_categories() {
        return [
            'user'      => ['name' => '用户信息',    'desc' => '用户注册、实名认证、状态变更、删除等重要资料变动', 'default' => 1],
            'admin_login' => ['name' => '后台登录',  'desc' => '管理员登录成功 / 失败（含异常拦截）',            'default' => 1],
            'admin_op'  => ['name' => '后台修改',    'desc' => '后台各项数据修改操作',                          'default' => 1],
            'config'    => ['name' => '功能配置',    'desc' => '网站设置、支付、验证码、接口等功能配置变更',      'default' => 1],
            'order'     => ['name' => '订单交易',    'desc' => '下单、开通、续费、支付成功',                    'default' => 1],
            'finance'   => ['name' => '资金变动',    'desc' => '用户充值、余额扣款、退款',                      'default' => 1],
            'security'  => ['name' => '安全事件',    'desc' => 'IP 封禁、爆破拦截、违规处理等',                 'default' => 1],
            'stats'     => ['name' => '系统统计',    'desc' => '每日核心数据快照（用户/订单/收入/主机）',        'default' => 1],
        ];
    }
}

if (!function_exists('sys_record_opt')) {
    /**
     * 读取 / 写入记录开关
     * @param string $key
     * @param mixed  $value 传入非 null 则写入
     * @return mixed
     */
    function sys_record_opt($key, $value = null) {
        static $cache = null;
        try {
            ensure_sys_record_config_table();
            if ($cache === null) {
                $cache = [];
                $rows = \think\Db::name('sys_record_config')->select();
                if ($rows) {
                    foreach ($rows as $r) {
                        $cache[$r['k']] = $r['v'];
                    }
                }
            }
            if ($value !== null) {
                $cache[$key] = $value;
                \think\Db::execute(
                    "REPLACE INTO `" . \think\Db::name('sys_record_config')->getTable() . "` (`k`,`v`) VALUES (?,?)",
                    [$key, (string)$value]
                );
                return $value;
            }
            return isset($cache[$key]) ? $cache[$key] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}

if (!function_exists('sys_record_enabled')) {
    /**
     * 判断某类别是否开启记录
     */
    function sys_record_enabled($category) {
        $cats = sys_record_categories();
        $def  = isset($cats[$category]) ? $cats[$category]['default'] : 1;
        $v    = sys_record_opt('rec_' . $category);
        if ($v === null) return (bool)$def;
        return (bool)intval($v);
    }
}

if (!function_exists('sys_record')) {
    /**
     * 写入一条系统重要记录
     *
     * @param string $category 类别（见 sys_record_categories）
     * @param string $title    标题，如「修改网站设置」
     * @param array|string $detail 详情（数组会转 JSON）
     * @param array  $opts     可选：level / operator_type / operator_id / operator_name /
     *                         target_type / target_id / ip / dedup(秒) / summary
     * @return int|false 记录ID 或 false（未开启 / 失败）
     */
    function sys_record($category, $title, $detail = [], $opts = []) {
        try {
            if (!sys_record_enabled($category)) return false;
            ensure_sys_record_table();

            $level         = isset($opts['level']) ? intval($opts['level']) : 1;
            $operatorType  = isset($opts['operator_type']) ? $opts['operator_type'] : '';
            $operatorId    = isset($opts['operator_id']) ? intval($opts['operator_id']) : 0;
            $operatorName  = isset($opts['operator_name']) ? mb_substr((string)$opts['operator_name'], 0, 60) : '';
            $targetType    = isset($opts['target_type']) ? $opts['target_type'] : '';
            $targetId      = isset($opts['target_id']) ? intval($opts['target_id']) : 0;
            $ip            = isset($opts['ip']) ? $opts['ip'] : (function_exists('get_client_ip') ? get_client_ip() : '');

            // 操作者自动补全
            if ($operatorType === '' || $operatorName === '') {
                if (PHP_SESSION_ACTIVE === session_status()) {
                    $aid = session('adminid') ? intval(session('adminid')) : 0;
                    if ($aid) {
                        if ($operatorType === '') $operatorType = 'admin';
                        $operatorId = $operatorId ?: $aid;
                        if ($operatorName === '') {
                            try {
                                $a = \think\Db::name('admin')->where('id', $aid)->field('user')->find();
                                $operatorName = $a ? $a['user'] : '';
                            } catch (\Exception $e) {}
                        }
                    } else {
                        $uid = session('userid') ? intval(session('userid')) : 0;
                        if ($uid) {
                            if ($operatorType === '') $operatorType = 'user';
                            $operatorId = $operatorId ?: $uid;
                            if ($operatorName === '') {
                                try {
                                    $u = \think\Db::name('user')->where('id', $uid)->field('user')->find();
                                    $operatorName = $u ? $u['user'] : '';
                                } catch (\Exception $e) {}
                            }
                        }
                    }
                }
            }
            if ($operatorType === '') $operatorType = 'system';

            // 摘要：从详情里挑关键信息拼成一行
            $summary = isset($opts['summary']) ? mb_substr((string)$opts['summary'], 0, 500) : '';
            if ($summary === '' && is_array($detail)) {
                $parts = [];
                foreach ($detail as $k => $v) {
                    if (in_array($k, ['time', 'url', 'method', 'referer', 'user_agent', 'role'], true)) continue;
                    if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                    $parts[] = $k . '：' . mb_substr((string)$v, 0, 60);
                    if (count($parts) >= 4) break;
                }
                $summary = implode('；', $parts);
            }

            // 去重节流：同类同目标在 N 秒内不重复写入（默认 0 不去重）
            $dedup = isset($opts['dedup']) ? intval($opts['dedup']) : 0;
            if ($dedup > 0) {
                $exists = \think\Db::name('sys_record')
                    ->where('category', $category)
                    ->where('target_type', $targetType)
                    ->where('target_id', $targetId)
                    ->where('create_time', '>', time() - $dedup)
                    ->count();
                if ($exists) return false;
            }

            $data = [
                'category'      => $category,
                'level'         => $level,
                'title'         => mb_substr((string)$title, 0, 200),
                'summary'       => mb_substr((string)$summary, 0, 500),
                'detail'        => is_array($detail) || is_object($detail)
                                    ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                    : (string)$detail,
                'operator_type' => $operatorType,
                'operator_id'   => $operatorId,
                'operator_name' => $operatorName,
                'target_type'   => $targetType,
                'target_id'     => $targetId,
                'ip'            => mb_substr((string)$ip, 0, 60),
                'create_time'   => time(),
                'date'          => date('Y-m-d'),
            ];
            return \think\Db::name('sys_record')->insertGetId($data);
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('sys_record_stats')) {
    /**
     * 记录统计：总数、今日数、各类别数量
     */
    function sys_record_stats() {
        $out = ['total' => 0, 'today' => 0, 'week' => 0, 'by_cat' => [], 'by_level' => [0, 0, 0]];
        try {
            ensure_sys_record_table();
            $m = \think\Db::name('sys_record');
            $out['total'] = (int)$m->count();
            $out['today'] = (int)$m->where('date', date('Y-m-d'))->count();
            $out['week']  = (int)$m->where('create_time', '>', time() - 7 * 86400)->count();
            $rows = \think\Db::name('sys_record')
                ->field('category, level, count(*) as c')
                ->group('category, level')
                ->select();
            if ($rows) {
                foreach ($rows as $r) {
                    $c = $r['category'];
                    if (!isset($out['by_cat'][$c])) $out['by_cat'][$c] = 0;
                    $out['by_cat'][$c] += intval($r['c']);
                    $lv = intval($r['level']);
                    if (isset($out['by_level'][$lv - 1])) $out['by_level'][$lv - 1] += intval($r['c']);
                }
            }
        } catch (\Exception $e) {}
        return $out;
    }
}

if (!function_exists('sys_record_recent')) {
    /**
     * 最近记录
     */
    function sys_record_recent($limit = 20, $category = '') {
        try {
            ensure_sys_record_table();
            $q = \think\Db::name('sys_record')->order('id desc');
            if ($category !== '') $q->where('category', $category);
            return $q->limit(intval($limit))->select() ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
}

if (!function_exists('sys_record_cleanup')) {
    /**
     * 清理 N 天前的记录
     */
    function sys_record_cleanup($days = 90) {
        try {
            ensure_sys_record_table();
            $cut = time() - intval($days) * 86400;
            return \think\Db::name('sys_record')->where('create_time', '<', $cut)->delete();
        } catch (\Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('sys_record_daily_snapshot')) {
    /**
     * 每日核心数据快照（一天只写一条，避免垃圾数据）
     * 只统计「重要」指标，不记录无关明细。
     */
    function sys_record_daily_snapshot($force = false) {
        try {
            if (!sys_record_enabled('stats')) return false;
            ensure_sys_record_table();
            $today = date('Y-m-d');
            if (!$force) {
                $has = \think\Db::name('sys_record')
                    ->where('category', 'stats')->where('date', $today)->count();
                if ($has) return false;
            }
            $d = [
                'users'    => (int)\think\Db::name('user')->count(),
                'orders'   => (int)\think\Db::name('order')->count(),
                'hosts'    => (int)\think\Db::name('order')->where('state', 1)->count(),
                'products' => (int)\think\Db::name('cart')->where('hide', 0)->count(),
                'income'   => round((float)\think\Db::name('pay')->sum('money'), 2),
                'today_income' => round((float)\think\Db::name('pay')
                    ->where('atime', '>', strtotime($today . ' 00:00:00'))->sum('money'), 2),
                'tickets'  => (int)\think\Db::name('ticket')->count(),
                'realname' => 0,
            ];
            try {
                $d['realname'] = (int)\think\Db::name('user')->where('realname_status', 1)->count();
            } catch (\Exception $e) {}
            return sys_record('stats', '每日数据快照 ' . $today, $d, [
                'operator_type' => 'system',
                'operator_name' => '系统',
                'level'         => 1,
            ]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
