<?php
namespace app\admin\controller;

use think\Controller;
use think\Db;
use think\Request;

/**
 * 服务器状态（宝塔面板 API）
 * 文档：https://docs.bt.cn/api/system/
 */
class BtServer extends Controller
{
    protected $user = [];
    protected $web  = [];

    public function _initialize() {
        if (!session("adminid")) {
            $this->redirect(url('admin/login/index'));
        }
        $this->user = Db::name('admin')->where('id', session("adminid"))->find();
        $this->web  = web_config();

        $this->assign([
            'webname'        => $this->web['name'],
            'web'            => $this->web,
            'user'           => $this->user,
            'adminPermissions'=> get_admin_permissions($this->user),
            'templateset'    => file_exists(PATH . "/app/index/view/" . $this->web["template"] . "/set.php") ? "1" : "0",
            'csrf_token'     => csrf_token(),
        ]);

        ensure_bt_servers_table();
    }

    protected function isSuper() {
        return !empty($this->user['is_super']) || intval($this->user['role_id']) === 1;
    }

    protected function jsonErr($msg, $extra = []) {
        return json(array_merge(['code' => 0, 'msg' => $msg], $extra));
    }

    // 列表
    public function index() {
        $list = Db::name('bt_servers')->order('sort', 'DESC')->order('id', 'ASC')->select();
        return $this->fetch('/' . $this->web["admintemplate"] . '/bt_server', [
            'list' => $list ?: [],
            'csrf' => csrf_token(),
        ]);
    }

    // 新增/编辑
    public function save() {
        if (!Request::instance()->isPost()) return $this->jsonErr('非法请求');
        if (!csrf_verify(input('__token__'))) return $this->jsonErr('安全验证失败，请刷新页面重试');

        $id     = intval(input('id', 0));
        $name   = trim((string) input('name', ''));
        $url    = trim((string) input('panel_url', ''));
        $sk     = trim((string) input('api_sk', ''));
        $tag    = trim((string) input('tag', ''));
        $remark = trim((string) input('remark', ''));

        if ($name === '') return $this->jsonErr('请填写服务器名称');
        if ($url === '')  return $this->jsonErr('请填写宝塔面板地址');
        if ($sk === '')   return $this->jsonErr('请填写接口密钥 api_sk');
        if (mb_strlen($name) > 64)  $name = mb_substr($name, 0, 64);
        if (strlen($url) > 255)     $url  = substr($url, 0, 255);
        if (mb_strlen($tag) > 64)   $tag  = mb_substr($tag, 0, 64);

        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $url = 'http://' . $url;
        }
        $url = rtrim($url, '/');

        $data = [
            'name'        => $name,
            'tag'         => $tag,
            'panel_url'   => $url,
            'api_sk'      => $sk,
            'detail'      => intval(input('detail', 1)) ? 1 : 0,
            'cache_ttl'   => max(3, min(3600, intval(input('cache_ttl', 15)))),
            'sort'        => intval(input('sort', 0)),
            'status'      => intval(input('status', 1)) ? 1 : 0,
            'remark'      => $remark,
            'update_time' => time(),
        ];

        try {
            if ($id > 0) {
                Db::name('bt_servers')->where('id', $id)->update($data);
                admin_op_log('bt_server_edit', '编辑监控服务器：' . $name, ['id' => $id]);
            } else {
                $data['create_time'] = time();
                $id = Db::name('bt_servers')->insertGetId($data);
                admin_op_log('bt_server_add', '新增监控服务器：' . $name, ['id' => $id]);
            }
        } catch (\Exception $e) {
            return $this->jsonErr('保存失败：' . $e->getMessage());
        }

        return json(['code' => 1, 'msg' => '保存成功', 'id' => $id]);
    }

    // 删除
    public function del() {
        if (!Request::instance()->isPost()) return $this->jsonErr('非法请求');
        if (!csrf_verify(input('__token__'))) return $this->jsonErr('安全验证失败，请刷新页面重试');
        $id = intval(input('id', 0));
        if ($id <= 0) return $this->jsonErr('参数错误');
        $row = Db::name('bt_servers')->where('id', $id)->find();
        if (!$row) return $this->jsonErr('记录不存在');
        Db::name('bt_servers')->where('id', $id)->delete();
        @unlink(bt_cache_dir() . 's_' . $id . '.json');
        admin_op_log('bt_server_del', '删除监控服务器：' . $row['name'], ['id' => $id]);
        return json(['code' => 1, 'msg' => '已删除']);
    }

    // 测试连通性
    public function test() {
        if (!Request::instance()->isPost()) return $this->jsonErr('非法请求');
        if (!csrf_verify(input('__token__'))) return $this->jsonErr('安全验证失败，请刷新页面重试');

        $id   = intval(input('id', 0));
        $url  = trim((string) input('panel_url', ''));
        $sk   = trim((string) input('api_sk', ''));
        $name = trim((string) input('name', ''));

        // 未填则读取已保存配置（支持“直接测试已保存的服务器”）
        if (($url === '' || $sk === '') && $id > 0) {
            $row = Db::name('bt_servers')->where('id', $id)->find();
            if ($row) {
                if ($url === '') $url = $row['panel_url'];
                if ($sk === '')  $sk  = $row['api_sk'];
                if ($name === '') $name = $row['name'];
            }
        }
        if ($url === '') return $this->jsonErr('请填写宝塔面板地址');
        if ($sk === '')  return $this->jsonErr('请填写接口密钥 api_sk');
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) $url = 'http://' . $url;
        $url = rtrim($url, '/');

        $t0  = microtime(true);
        $res = bt_api_multi($url, $sk, ['GetSystemTotal', 'GetNetWork', 'GetDiskInfo', 'GetCpuInfo', 'GetLoadAverage', 'GetMemInfo'], 10);
        $ms  = round((microtime(true) - $t0) * 1000);

        $okCount = 0; $errMsg = ''; $detail = [];
        foreach ($res as $act => $r) {
            $detail[$act] = ['ok' => $r['ok'] ? 1 : 0, 'msg' => isset($r['msg']) ? $r['msg'] : '', 'http' => isset($r['http']) ? $r['http'] : 0];
            if ($r['ok']) $okCount++;
            elseif ($errMsg === '') $errMsg = $r['msg'];
        }

        if ($okCount === 0) {
            return json([
                'code' => 0,
                'msg'  => '连接失败：' . ($errMsg ? $errMsg : '无响应'),
                'ms'   => $ms,
                'detail' => $detail,
            ]);
        }

        $preview = [];
        if (!empty($res['GetSystemTotal']['ok'])) {
            $t = $res['GetSystemTotal']['data'];
            $preview = [
                'system'  => isset($t['system']) ? $t['system'] : '',
                'version' => isset($t['version']) ? $t['version'] : '',
                'uptime'  => isset($t['time']) ? $t['time'] : '',
                'cpu_num' => isset($t['cpuNum']) ? $t['cpuNum'] : 0,
                'cpu_used'=> isset($t['cpuRealUsed']) ? $t['cpuRealUsed'] : 0,
                'mem_total'=> isset($t['memTotal']) ? $t['memTotal'] : 0,
                'mem_used' => isset($t['memRealUsed']) ? $t['memRealUsed'] : 0,
            ];
        }

        return json([
            'code'   => 1,
            'msg'    => "连接成功（{$okCount}/6 个接口正常，耗时 {$ms}ms）",
            'ms'     => $ms,
            'detail' => $detail,
            'preview'=> $preview,
        ]);
    }

    // 立即刷新（清空缓存并重新采集）
    public function refresh() {
        if (!Request::instance()->isPost()) return $this->jsonErr('非法请求');
        if (!csrf_verify(input('__token__'))) return $this->jsonErr('安全验证失败，请刷新页面重试');
        $id = intval(input('id', 0));
        ensure_bt_servers_table();
        if ($id > 0) {
            $row = Db::name('bt_servers')->where('id', $id)->find();
            if (!$row) return $this->jsonErr('记录不存在');
            $st = bt_server_status($row, true);
            return json(['code' => 1, 'msg' => $st['online'] ? '已刷新' : ('离线：' . $st['error']), 'data' => $st]);
        }
        $list = bt_servers_status(true, false);
        $on = 0;
        foreach ($list as $s) if (!empty($s['online'])) $on++;
        return json(['code' => 1, 'msg' => "已刷新 " . count($list) . " 台，在线 {$on} 台", 'data' => $list]);
    }

    // 前台/后台共用的状态接口（JSON）
    public function status() {
        $force = intval(input('force', 0)) ? true : false;
        $list  = bt_servers_status($force, true);
        $on = 0;
        foreach ($list as $s) if (!empty($s['online'])) $on++;
        return json(['code' => 1, 'ts' => time(), 'online' => $on, 'total' => count($list), 'list' => $list]);
    }
}
