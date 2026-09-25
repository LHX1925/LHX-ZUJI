<?php
namespace app\index\controller;

use think\Controller;

/**
 * 服务器实时状态（前台 JSON 接口）
 * 数据来自宝塔面板 API：https://docs.bt.cn/api/system/
 */
class Btstatus extends Controller
{
    /**
     * GET /server/status
     * 参数：force=1 强制刷新（忽略缓存）
     */
    public function index() {
        $force = intval(input('force', 0)) === 1;
        $list  = function_exists('bt_servers_status') ? bt_servers_status($force, true) : [];

        $online = 0;
        foreach ($list as $s) {
            if (!empty($s['online'])) $online++;
        }

        // 汇总（用于首页顶部概览）
        $summary = [
            'total'  => count($list),
            'online' => $online,
            'cpu'    => 0,
            'mem'    => 0,
            'up'     => 0,
            'down'   => 0,
        ];
        $cpuSum = 0; $memUsed = 0; $memTotal = 0;
        foreach ($list as $s) {
            $cpuSum  += isset($s['cpu']['used']) ? floatval($s['cpu']['used']) : 0;
            $memUsed += isset($s['mem']['real_used']) ? floatval($s['mem']['real_used']) : 0;
            $memTotal+= isset($s['mem']['total']) ? floatval($s['mem']['total']) : 0;
            $summary['up']   += isset($s['net']['up']) ? floatval($s['net']['up']) : 0;
            $summary['down'] += isset($s['net']['down']) ? floatval($s['net']['down']) : 0;
        }
        if ($online > 0) $summary['cpu'] = round($cpuSum / $online, 1);
        if ($memTotal > 0) $summary['mem'] = round(($memUsed / $memTotal) * 100, 1);
        $summary['up']   = round($summary['up'], 2);
        $summary['down'] = round($summary['down'], 2);

        $data = [
            'code'    => 1,
            'ts'      => time(),
            'summary' => $summary,
            'list'    => $list,
        ];

        if (method_exists($this, 'json')) {
            return json($data);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
