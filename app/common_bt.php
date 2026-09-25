<?php
/**
 * 宝塔面板 API 对接 —— 服务器实时状态监控
 * 官方文档：https://docs.bt.cn/api/system/
 *
 * 认证：POST /system?action=xxx
 *   request_time  = 当前时间戳
 *   request_token = md5(request_time + md5(api_sk))
 *
 * 采用接口：
 *   GetSystemTotal  系统统计（内存/CPU/运行时长/系统版本/面板版本）
 *   GetNetWork      网络流量 + CPU/负载/内存/磁盘综合
 *   GetDiskInfo     磁盘分区信息
 *   GetCpuInfo      CPU 使用率（含各核心）
 *   GetLoadAverage  系统负载
 *   GetMemInfo      内存详细信息
 */

if (!function_exists('ensure_bt_servers_table')) {
    /**
     * 建表（幂等）：dd_bt_servers
     */
    function ensure_bt_servers_table() {
        try {
            $prefix = \think\Db::getConfig('prefix');
            $prefix = $prefix ?: '';
            $table  = $prefix . 'bt_servers';
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(64) NOT NULL DEFAULT '' COMMENT '服务器名称',
              `tag` varchar(64) NOT NULL DEFAULT '' COMMENT '地区/分组标签',
              `panel_url` varchar(255) NOT NULL DEFAULT '' COMMENT '宝塔面板地址',
              `api_sk` varchar(255) NOT NULL DEFAULT '' COMMENT '接口密钥 api_sk',
              `detail` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=采集详细指标 0=仅基础',
              `cache_ttl` int(11) NOT NULL DEFAULT 15 COMMENT '状态缓存秒数',
              `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序，越大越靠前',
              `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=前台展示 0=停用',
              `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
              `create_time` int(11) NOT NULL DEFAULT 0,
              `update_time` int(11) NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              KEY `idx_status` (`status`),
              KEY `idx_sort` (`sort`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='宝塔API服务器列表';");

            // 兼容旧表：补齐可能缺失的列
            try {
                $cols = array_column(\think\Db::query("SHOW COLUMNS FROM `{$table}`"), 'Field');
                $adds = [
                    'tag'       => "ALTER TABLE `{$table}` ADD COLUMN `tag` varchar(64) NOT NULL DEFAULT '' COMMENT '地区/分组标签'",
                    'detail'    => "ALTER TABLE `{$table}` ADD COLUMN `detail` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=采集详细指标 0=仅基础'",
                    'cache_ttl' => "ALTER TABLE `{$table}` ADD COLUMN `cache_ttl` int(11) NOT NULL DEFAULT 15 COMMENT '状态缓存秒数'",
                    'sort'      => "ALTER TABLE `{$table}` ADD COLUMN `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序'",
                    'remark'    => "ALTER TABLE `{$table}` ADD COLUMN `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注'",
                ];
                foreach ($adds as $f => $sql) {
                    if (!in_array($f, $cols, true)) {
                        try { \think\Db::execute($sql); } catch (\Exception $e) {}
                    }
                }
            } catch (\Exception $e) {}
        } catch (\Exception $e) {}
    }
}

if (!function_exists('bt_api_sign')) {
    /**
     * 生成宝塔 API 签名
     * @return array [request_time, request_token]
     */
    function bt_api_sign($api_sk) {
        $request_time  = (string) time();
        $request_token = md5($request_time . md5((string) $api_sk));
        return [$request_time, $request_token];
    }
}

if (!function_exists('bt_api_build_url')) {
    function bt_api_build_url($panel_url, $action) {
        $panel_url = rtrim(trim((string) $panel_url), '/');
        if ($panel_url === '') return '';
        if (strpos($panel_url, 'http://') !== 0 && strpos($panel_url, 'https://') !== 0) {
            $panel_url = 'http://' . $panel_url;
        }
        return $panel_url . '/system?action=' . rawurlencode($action);
    }
}

if (!function_exists('bt_api_post')) {
    /**
     * 单次调用宝塔接口
     * @return array ['ok'=>bool,'data'=>mixed,'msg'=>string,'http'=>int]
     */
    function bt_api_post($panel_url, $api_sk, $action, $extra = [], $timeout = 8) {
        $url = bt_api_build_url($panel_url, $action);
        if ($url === '') return ['ok' => false, 'msg' => '面板地址为空', 'http' => 0];
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'msg' => 'PHP 未安装 curl 扩展', 'http' => 0];
        }
        list($request_time, $request_token) = bt_api_sign($api_sk);
        $post = array_merge([
            'request_time'  => $request_time,
            'request_token' => $request_token,
        ], is_array($extra) ? $extra : []);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, max(2, (int) $timeout)),
            CURLOPT_TIMEOUT        => max(3, (int) $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'BT-Panel-API-Client/1.0 (+host-monitor)',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $body === null) {
            return ['ok' => false, 'msg' => '连接失败：' . ($err ? $err : '无响应'), 'http' => $code];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $tip = ($code === 403 || $code === 401)
                ? '（面板拒绝访问，请检查 API 接口是否已开启、密钥是否正确、IP 白名单）'
                : '';
            return ['ok' => false, 'msg' => '面板返回非 JSON（HTTP ' . $code . '）' . $tip, 'http' => $code, 'raw' => mb_substr($body, 0, 200)];
        }
        if (isset($data['status']) && ($data['status'] === false || $data['status'] === 'false')) {
            return ['ok' => false, 'msg' => isset($data['msg']) ? $data['msg'] : '面板返回失败', 'http' => $code];
        }
        return ['ok' => true, 'data' => $data, 'http' => $code];
    }
}

if (!function_exists('bt_api_multi')) {
    /**
     * 并发调用多个 action（curl_multi，显著提速）
     * @return array [action => ['ok'=>bool,'data'=>mixed,'msg'=>string,'http'=>int]]
     */
    function bt_api_multi($panel_url, $api_sk, $actions, $timeout = 8) {
        $out = [];
        if (!function_exists('curl_multi_init')) {
            foreach ($actions as $act) $out[$act] = bt_api_post($panel_url, $api_sk, $act, [], $timeout);
            return $out;
        }
        $timeout = max(3, (int) $timeout);
        $mh  = curl_multi_init();
        $chs = [];
        foreach ($actions as $act) {
            $url = bt_api_build_url($panel_url, $act);
            if ($url === '') { $out[$act] = ['ok' => false, 'msg' => '面板地址为空', 'http' => 0]; continue; }
            list($request_time, $request_token) = bt_api_sign($api_sk);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'request_time'  => $request_time,
                    'request_token' => $request_token,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'BT-Panel-API-Client/1.0 (+host-monitor)',
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $chs[$act] = $ch;
        }

        $running = null;
        do {
            $mrc = curl_multi_exec($mh, $running);
            if ($running > 0) curl_multi_select($mh, 0.2);
        } while ($running > 0 && $mrc === CURLM_OK);

        foreach ($chs as $act => $ch) {
            $body = curl_multi_getcontent($ch);
            $err  = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($body === false || $body === '' || $body === null) {
                $out[$act] = ['ok' => false, 'msg' => '连接失败：' . ($err ? $err : '无响应'), 'http' => $code];
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                $tip = ($code === 403 || $code === 401) ? '（可能被面板安全拦截 / 密钥错误）' : '';
                $out[$act] = ['ok' => false, 'msg' => '非 JSON 响应（HTTP ' . $code . '）' . $tip, 'http' => $code, 'raw' => mb_substr($body, 0, 160)];
                continue;
            }
            // 面板业务级失败（部分接口返回 {"status":false,"msg":"..."}）
            if (isset($data['status']) && ($data['status'] === false || $data['status'] === 'false')) {
                $out[$act] = ['ok' => false, 'msg' => isset($data['msg']) ? $data['msg'] : '面板返回失败', 'http' => $code];
                continue;
            }
            $out[$act] = ['ok' => true, 'data' => $data, 'http' => $code];
        }
        curl_multi_close($mh);
        return $out;
    }
}

if (!function_exists('bt_num')) {
    function bt_num($v, $default = 0) {
        if (is_numeric($v)) return $v + 0;
        if (is_string($v)) {
            $s = preg_replace('/[^0-9.\-]/', '', $v);
            if ($s !== '' && is_numeric($s)) return $s + 0;
        }
        return $default;
    }
}

if (!function_exists('bt_cache_dir')) {
    function bt_cache_dir() {
        $dir = (defined('RUNTIME_PATH') ? RUNTIME_PATH : PATH . 'runtime/') . 'bt_status/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }
}

if (!function_exists('bt_status_cache')) {
    /**
     * 简易文件缓存读写
     * @param int    $id
     * @param int    $ttl  缓存秒数，0=不缓存
     * @param mixed  $set  传入则写入
     */
    function bt_status_cache($id, $ttl = 15, $set = null) {
        $file = bt_cache_dir() . 's_' . intval($id) . '.json';
        if ($set !== null) {
            @file_put_contents($file, json_encode(['t' => time(), 'd' => $set]), LOCK_EX);
            return $set;
        }
        if (!file_exists($file)) return null;
        $raw = @file_get_contents($file);
        if (!$raw) return null;
        $arr = @json_decode($raw, true);
        if (!is_array($arr) || !isset($arr['t'], $arr['d'])) return null;
        if ($ttl > 0 && (time() - intval($arr['t'])) > $ttl) return null;
        return $arr['d'];
    }
}

if (!function_exists('bt_req_timeout')) {
    function bt_req_timeout($server) {
        $t = isset($server['cache_ttl']) ? intval($server['cache_ttl']) : 15;
        return $t;
    }
}

if (!function_exists('bt_server_status')) {
    /**
     * 采集单台服务器的实时状态
     * @param array $server  dd_bt_servers 一行
     * @param bool  $force   忽略缓存
     * @return array
     */
    function bt_server_status($server, $force = false) {
        $id = intval($server['id']);
        $base = [
            'id'         => $id,
            'name'       => isset($server['name']) ? $server['name'] : ('服务器#' . $id),
            'tag'        => isset($server['tag']) ? $server['tag'] : '',
            'remark'     => isset($server['remark']) ? $server['remark'] : '',
            'online'     => false,
            'error'      => '',
            'updated_at' => time(),
            'cpu'        => ['used' => 0, 'num' => 0, 'model' => '', 'cores' => []],
            'load'       => ['one' => 0, 'five' => 0, 'fifteen' => 0, 'percent' => 0],
            'mem'        => ['total' => 0, 'real_used' => 0, 'free' => 0, 'buffers' => 0, 'cached' => 0, 'available' => 0, 'percent' => 0],
            'net'        => ['up' => 0, 'down' => 0, 'up_total' => 0, 'down_total' => 0, 'interfaces' => []],
            'disk'       => [],
            'system'     => '',
            'version'    => '',
            'uptime'     => '',
        ];

        $ttl = bt_req_timeout($server);
        if (!$force && $ttl > 0) {
            $cached = bt_status_cache($id, $ttl);
            if (is_array($cached)) {
                $cached['name']   = $base['name'];
                $cached['tag']    = $base['tag'];
                $cached['remark'] = $base['remark'];
                return $cached;
            }
        }

        $panel = isset($server['panel_url']) ? trim($server['panel_url']) : '';
        $sk    = isset($server['api_sk']) ? trim($server['api_sk']) : '';
        if ($panel === '' || $sk === '') {
            $base['error'] = '未配置面板地址或接口密钥';
            return $base;
        }

        $detail = intval(isset($server['detail']) ? $server['detail'] : 1);
        $actions = ['GetSystemTotal', 'GetNetWork'];
        if ($detail === 1) {
            $actions[] = 'GetDiskInfo';
            $actions[] = 'GetCpuInfo';
            $actions[] = 'GetLoadAverage';
            $actions[] = 'GetMemInfo';
        }

        $res = bt_api_multi($panel, $sk, $actions, 8);

        $total = (isset($res['GetSystemTotal']) && $res['GetSystemTotal']['ok']) ? $res['GetSystemTotal']['data'] : [];
        $netw  = (isset($res['GetNetWork']) && $res['GetNetWork']['ok']) ? $res['GetNetWork']['data'] : [];

        // —— 任一核心接口成功即视为在线 ——
        if (!is_array($total)) $total = [];
        if (!is_array($netw))  $netw  = [];
        if (!$total && !$netw) {
            $msg = '';
            foreach ($res as $a => $r) {
                if (!$r['ok']) { $msg = $r['msg']; break; }
            }
            $base['error'] = $msg ? $msg : '无法连接宝塔面板';
            bt_status_cache($id, $ttl, $base);
            return $base;
        }
        $base['online'] = true;

        // ===== CPU =====
        $cpuUsed = 0; $cpuNum = 0; $cpuModel = ''; $cores = [];
        if (isset($total['cpuRealUsed'])) $cpuUsed = bt_num($total['cpuRealUsed']);
        if (isset($total['cpuNum']))     $cpuNum  = intval(bt_num($total['cpuNum']));
        if (isset($res['GetCpuInfo']) && $res['GetCpuInfo']['ok'] && is_array($res['GetCpuInfo']['data'])) {
            $ci = $res['GetCpuInfo']['data'];
            if (isset($ci[0])) $cpuUsed  = bt_num($ci[0], $cpuUsed);
            if (isset($ci[1])) $cpuNum   = intval(bt_num($ci[1], $cpuNum));
            if (isset($ci[2]) && is_array($ci[2])) $cores = array_map(function ($v) { return bt_num($v); }, $ci[2]);
            if (isset($ci[3])) $cpuModel = (string) $ci[3];
        }
        // GetNetWork 的 cpu 结构同上
        if (!$cores && isset($netw['cpu']) && is_array($netw['cpu'])) {
            if (isset($netw['cpu'][0])) $cpuUsed = bt_num($netw['cpu'][0], $cpuUsed);
            if (isset($netw['cpu'][1])) $cpuNum  = intval(bt_num($netw['cpu'][1], $cpuNum));
            if (isset($netw['cpu'][2]) && is_array($netw['cpu'][2])) $cores = array_map(function ($v) { return bt_num($v); }, $netw['cpu'][2]);
            if (empty($cpuModel) && isset($netw['cpu'][3])) $cpuModel = (string) $netw['cpu'][3];
        }
        $base['cpu'] = ['used' => round($cpuUsed, 1), 'num' => $cpuNum, 'model' => $cpuModel, 'cores' => $cores];

        // ===== 系统负载 =====
        $load = ['one' => 0, 'five' => 0, 'fifteen' => 0];
        if (isset($netw['load']) && is_array($netw['load'])) {
            $load['one']     = bt_num(isset($netw['load']['one']) ? $netw['load']['one'] : 0);
            $load['five']    = bt_num(isset($netw['load']['five']) ? $netw['load']['five'] : 0);
            $load['fifteen'] = bt_num(isset($netw['load']['fifteen']) ? $netw['load']['fifteen'] : 0);
        }
        if (isset($res['GetLoadAverage']) && $res['GetLoadAverage']['ok'] && is_array($res['GetLoadAverage']['data'])) {
            $la = $res['GetLoadAverage']['data'];
            $map = ['one' => ['one', '1', 'lavg_1'], 'five' => ['five', '5', 'lavg_5'], 'fifteen' => ['fifteen', '15', 'lavg_15']];
            foreach ($map as $k => $keys) {
                foreach ($keys as $kk) {
                    if (isset($la[$kk]) && is_numeric($la[$kk])) { $load[$k] = bt_num($la[$kk]); break; }
                }
            }
        }
        // 负载百分比：以 CPU 核心数为基准（1.0/核 = 100%）
        $loadBase = $cpuNum > 0 ? $cpuNum : 1;
        $load['percent'] = round(min(100, ($load['five'] / $loadBase) * 100), 1);
        $base['load'] = $load;

        // ===== 内存 =====
        $mem = ['total' => 0, 'real_used' => 0, 'free' => 0, 'buffers' => 0, 'cached' => 0, 'available' => 0, 'percent' => 0];
        $memSrc = [];
        if (isset($res['GetMemInfo']) && $res['GetMemInfo']['ok'] && is_array($res['GetMemInfo']['data'])) $memSrc = $res['GetMemInfo']['data'];
        elseif ($total) $memSrc = $total;
        elseif (isset($netw['mem']) && is_array($netw['mem'])) $memSrc = $netw['mem'];
        // 兼容宝塔 GetMemInfo 返回的驼峰(memTotal/memRealUsed...)与下划线(total/real_used...)两种命名
        $memAlias = [
            'total'      => ['total', 'memTotal'],
            'real_used'  => ['real_used', 'memRealUsed', 'memUsed'],
            'free'       => ['free', 'memFree'],
            'buffers'    => ['buffers', 'memBuffers'],
            'cached'     => ['cached', 'memCached'],
            'available'  => ['available', 'memAvailable'],
        ];
        foreach ($memAlias as $k => $candidates) {
            foreach ($candidates as $ck) {
                if (isset($memSrc[$ck]) && is_numeric($memSrc[$ck])) { $mem[$k] = bt_num($memSrc[$ck]); break; }
            }
        }
        if ($mem['total'] > 0) {
            $mem['percent'] = round(min(100, ($mem['real_used'] / $mem['total']) * 100), 1);
        } elseif (isset($total['mem']) && is_numeric($total['mem'])) {
            // GetSystemTotal 的 mem 字段本身即内存使用率百分比（如 "30.5"）
            $mem['percent'] = round(min(100, bt_num($total['mem'])), 1);
        }
        $base['mem'] = $mem;

        // ===== 网络 =====
        $net = ['up' => 0, 'down' => 0, 'up_total' => 0, 'down_total' => 0, 'interfaces' => []];
        $net['up']   = round(bt_num(isset($netw['up']) ? $netw['up'] : 0), 2);
        $net['down'] = round(bt_num(isset($netw['down']) ? $netw['down'] : 0), 2);
        $net['up_total']   = bt_num(isset($netw['upTotal']) ? $netw['upTotal'] : 0);
        $net['down_total'] = bt_num(isset($netw['downTotal']) ? $netw['downTotal'] : 0);
        if (isset($netw['network']) && is_array($netw['network'])) {
            foreach ($netw['network'] as $ifName => $if) {
                if (!is_array($if)) continue;
                if (in_array(strtolower((string) $ifName), ['lo', 'lo0'], true)) continue; // 忽略回环
                $net['interfaces'][] = [
                    'name'      => (string) $ifName,
                    'up'        => round(bt_num(isset($if['up']) ? $if['up'] : 0), 2),
                    'down'      => round(bt_num(isset($if['down']) ? $if['down'] : 0), 2),
                    'up_total'  => bt_num(isset($if['upTotal']) ? $if['upTotal'] : 0),
                    'down_total'=> bt_num(isset($if['downTotal']) ? $if['downTotal'] : 0),
                ];
            }
        }
        // 若没有网卡明细，用汇总值兜底
        if (empty($net['interfaces']) && ($net['up'] > 0 || $net['down'] > 0)) {
            $net['interfaces'][] = [
                'name' => '全部网卡', 'up' => $net['up'], 'down' => $net['down'],
                'up_total' => $net['up_total'], 'down_total' => $net['down_total'],
            ];
        }
        $base['net'] = $net;

        // ===== 磁盘 =====
        $disks = [];
        $di = (isset($res['GetDiskInfo']) && $res['GetDiskInfo']['ok'] && is_array($res['GetDiskInfo']['data']))
            ? $res['GetDiskInfo']['data'] : null;
        if ($di === null && isset($netw['disk']) && is_array($netw['disk'])) $di = $netw['disk'];
        if (is_array($di)) {
            foreach ($di as $d) {
                // 关联数组（GetDiskInfo 标准结构）
                if (isset($d['path'])) {
                    $size = isset($d['size']) && is_array($d['size']) ? $d['size'] : [];
                    $disks[] = [
                        'path'       => (string) $d['path'],
                        'total'      => isset($size[0]) ? (string) $size[0] : '',
                        'used'       => isset($size[1]) ? (string) $size[1] : '',
                        'free'       => isset($size[2]) ? (string) $size[2] : '',
                        'percent'    => bt_num(isset($size[3]) ? $size[3] : 0),
                        'filesystem' => isset($d['filesystem']) ? (string) $d['filesystem'] : '',
                        'type'       => isset($d['type']) ? (string) $d['type'] : '',
                    ];
                    continue;
                }
                // 索引数组兼容（旧结构）
                if (is_array($d) && isset($d[0]) && is_array($d[1])) {
                    $disks[] = [
                        'path'       => (string) $d[0],
                        'total'      => isset($d[1][0]) ? (string) $d[1][0] : '',
                        'used'       => isset($d[1][1]) ? (string) $d[1][1] : '',
                        'free'       => isset($d[1][2]) ? (string) $d[1][2] : '',
                        'percent'    => bt_num(isset($d[1][3]) ? $d[1][3] : 0),
                        'filesystem' => isset($d[2]) ? (string) $d[2] : '',
                        'type'       => isset($d[3]) ? (string) $d[3] : '',
                    ];
                }
            }
        }
        $base['disk'] = $disks;

        // ===== 系统信息 =====
        $base['system']  = isset($total['system']) ? (string) $total['system'] : (isset($netw['system']) ? (string) $netw['system'] : '');
        $base['version'] = isset($total['version']) ? (string) $total['version'] : '';
        $base['uptime']  = isset($total['time']) ? (string) $total['time'] : (isset($netw['time']) ? (string) $netw['time'] : '');

        bt_status_cache($id, $ttl, $base);
        return $base;
    }
}

if (!function_exists('bt_servers_status')) {
    /**
     * 采集全部启用服务器
     * @param bool $force
     * @param bool $onlyPublic
     * @return array
     */
    function bt_servers_status($force = false, $onlyPublic = true) {
        ensure_bt_servers_table();
        try {
            $query = \think\Db::name('bt_servers');
            if ($onlyPublic) $query->where('status', 1);
            $list = $query->order('sort', 'DESC')->order('id', 'ASC')->select();
        } catch (\Exception $e) {
            return [];
        }
        if (empty($list)) return [];
        $out = [];
        foreach ($list as $s) {
            $out[] = bt_server_status($s, $force);
        }
        return $out;
    }
}
