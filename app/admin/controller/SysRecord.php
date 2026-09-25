<?php
namespace app\admin\controller;

use think\Controller;
use think\Db;
use think\Request;

/**
 * 系统重要记录
 * 只记录有追溯价值的数据：用户信息 / 后台登录 / 后台修改 / 功能配置 /
 * 订单交易 / 资金变动 / 安全事件 / 系统统计
 */
class SysRecord extends Controller
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

        // 权限：超级管理员或拥有「操作日志」权限
        $perms = get_admin_permissions($this->user);
        $isSuper = !empty($this->user['is_super']) || intval($this->user['role_id']) === 1;
        if (!$isSuper && !in_array('op_log', $perms)) {
            $this->error('您没有权限访问此页面', '/admin/index');
            exit;
        }

        if (function_exists('ensure_sys_record_table')) ensure_sys_record_table();
        if (function_exists('ensure_sys_record_config_table')) ensure_sys_record_config_table();
    }

    protected function isSuper() {
        return !empty($this->user['is_super']) || intval($this->user['role_id']) === 1;
    }

    protected function jsonErr($msg, $extra = []) {
        return json(array_merge(['code' => 0, 'msg' => $msg], $extra));
    }

    // 记录列表
    public function index() {
        $category = trim((string) input('category', ''));
        $level    = intval(input('level', 0));
        $search   = trim((string) input('search', ''));
        $date     = trim((string) input('date', ''));
        $opType   = trim((string) input('operator_type', ''));

        $query = Db::name('sys_record');
        if ($category !== '') $query->where('category', $category);
        if ($level > 0)       $query->where('level', $level);
        if ($opType !== '')   $query->where('operator_type', $opType);
        if ($date !== '')     $query->where('date', $date);
        if ($search !== '')   {
            $query->where('title|summary|operator_name|ip', 'like', '%' . $search . '%');
        }

        $list  = $query->order('id', 'desc')->paginate(20, false, ['query' => request()->param()]);
        $stats = function_exists('sys_record_stats') ? sys_record_stats() : ['total' => 0, 'today' => 0, 'week' => 0, 'by_cat' => [], 'by_level' => [0, 0, 0]];
        $cats  = function_exists('sys_record_categories') ? sys_record_categories() : [];

        // 类别中文名预先注入，避免模板复杂表达式
        $list->each(function ($item) use ($cats) {
            $item['category_name'] = isset($cats[$item['category']]) ? $cats[$item['category']]['name'] : $item['category'];
            $item['time_fmt']      = date('Y-m-d H:i:s', intval($item['create_time']));
            return $item;
        });

        // 附加开关状态
        // 注意：模板里不能用 {if $opts[$k]|default=0} —— |default= 只在输出标签生效，
        // 在 {if} 里会被原样编译成 PHP 中的 default 保留字，导致「意外令牌 default」语法错误。
        // 所以这里预先算好 ck 标记，模板只用 {if $c.ck} 这种最简单的条件。
        $opts = [];
        foreach ($cats as $k => $v) {
            $vv = function_exists('sys_record_opt') ? sys_record_opt('rec_' . $k) : null;
            $opts[$k] = ($vv === null) ? $v['default'] : intval($vv);
            $cats[$k]['ck'] = $opts[$k] ? 1 : 0;
        }
        $extraOpts = [];
        foreach (['rec_user_login' => 0, 'rec_ua' => 0, 'rec_geo' => 0, 'rec_visitor' => 1] as $k => $d) {
            $vv = function_exists('sys_record_opt') ? sys_record_opt($k) : null;
            $on = ($vv === null) ? $d : intval($vv);
            $extraOpts[$k] = ['ck' => $on ? 1 : 0];
        }

        return $this->fetch('/' . $this->web["admintemplate"] . '/sys_record', [
            'list'      => $list,
            'stats'     => $stats,
            'cats'      => $cats,
            'opts'      => $opts,
            'extraOpts' => $extraOpts,
            'category'  => $category,
            'level'     => $level,
            'search'    => $search,
            'date'      => $date,
            'opType'    => $opType,
            'csrf'      => csrf_token(),
        ]);
    }

    // 记录详情
    public function detail() {
        $id = intval(input('id', 0));
        if (!$id) return $this->jsonErr('参数错误');
        $row = Db::name('sys_record')->where('id', $id)->find();
        if (!$row) return $this->jsonErr('记录不存在');
        $detail = $row['detail'];
        $arr = json_decode($detail, true);
        return json([
            'code' => 1,
            'data' => [
                'id'         => $row['id'],
                'category'   => $row['category'],
                'title'      => $row['title'],
                'summary'    => $row['summary'],
                'operator'   => $row['operator_name'] ?: $row['operator_type'],
                'ip'         => $row['ip'],
                'time'       => date('Y-m-d H:i:s', $row['create_time']),
                'detail_obj' => $arr ?: null,
                'detail_raw' => $arr ? null : $detail,
            ],
        ]);
    }

    // 保存记录开关
    public function setting() {
        if (!Request::instance()->isPost()) return $this->jsonErr('非法请求');
        if (!csrf_verify(input('__token__'))) return $this->jsonErr('安全验证失败，请刷新页面重试');
        if (!function_exists('sys_record_opt')) return $this->jsonErr('记录模块未加载');

        $cats = function_exists('sys_record_categories') ? sys_record_categories() : [];
        $n = 0;
        foreach ($cats as $k => $v) {
            $val = input('rec_' . $k, null);
            sys_record_opt('rec_' . $k, ($val === null || $val === '' || $val === '0') ? '0' : '1');
            $n++;
        }
        foreach (['rec_user_login', 'rec_ua', 'rec_geo', 'rec_visitor'] as $k) {
            $val = input($k, null);
            sys_record_opt($k, ($val === null || $val === '' || $val === '0') ? '0' : '1');
            $n++;
        }
        return json(['code' => 1, 'msg' => '已保存 ' . $n . ' 项设置']);
    }

    // 清理旧记录
    public function clear() {
        if (!Request::instance()->isPost()) return $this->jsonErr('非法请求');
        if (!csrf_verify(input('__token__'))) return $this->jsonErr('安全验证失败，请刷新页面重试');
        if (!$this->isSuper()) return $this->jsonErr('仅超级管理员可执行此操作');

        $days     = intval(input('days', 90));
        $category = trim((string) input('category', ''));
        if ($days < 1) $days = 1;

        try {
            $q = Db::name('sys_record')->where('create_time', '<', time() - $days * 86400);
            if ($category !== '') $q->where('category', $category);
            $n = $q->delete();
            return json(['code' => 1, 'msg' => '已清理 ' . intval($n) . ' 条记录']);
        } catch (\Exception $e) {
            return $this->jsonErr('清理失败：' . $e->getMessage());
        }
    }

    // 手动生成今日数据快照
    public function snapshot() {
        if (!Request::instance()->isPost()) return $this->jsonErr('非法请求');
        if (!csrf_verify(input('__token__'))) return $this->jsonErr('安全验证失败，请刷新页面重试');
        if (!function_exists('sys_record_daily_snapshot')) return $this->jsonErr('记录模块未加载');
        $id = sys_record_daily_snapshot(true);
        return $id ? json(['code' => 1, 'msg' => '已生成今日数据快照']) : $this->jsonErr('生成失败');
    }
}
