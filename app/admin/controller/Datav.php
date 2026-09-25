<?php
namespace app\admin\controller;
use think\Controller;
use think\Db;
use think\Request;

/**
 * 后台数据大屏控制器
 * 说明：不展示任何单个用户的地理位置散点与头像，只按「国家 / 省份」维度呈现汇总数量
 */
class Datav extends Controller {
    protected $user = null;
    protected $web = null;

    public function _initialize() {
        $this->web = web_config();
        $adminid = session("adminid");
        if (!$adminid) {
            $this->redirect(function_exists('admin_login_url') ? admin_login_url() : '/admin/login');
        }
        $this->user = Db::name('admin')->where('id', $adminid)->find();
        if (!$this->user) {
            session("adminid", null);
            $this->redirect(function_exists('admin_login_url') ? admin_login_url() : '/admin/login');
        }
        if (function_exists('ensure_user_geo_columns')) ensure_user_geo_columns();
        if (function_exists('ensure_user_columns')) ensure_user_columns();

        // 注入后台公共模板变量，避免 header 中 $webname / $user / $adminPermissions 等未定义
        $file = file_exists(PATH . "/app/index/view/" . $this->web["template"] . "/set.php");
        $templateset = $file ? "1" : "0";
        $adminPermissions = function_exists('get_admin_permissions') ? get_admin_permissions($this->user) : [];
        $csrfToken = function_exists('csrf_token') ? csrf_token() : '';
        $this->assign([
            'webname' => $this->web['name'] ?? '',
            'user' => $this->user,
            'templateset' => $templateset,
            'adminPermissions' => $adminPermissions,
            'csrf_token' => $csrfToken,
        ]);
    }

    public function index() {
        return $this->fetch('/'.$this->web["admintemplate"].'/admin_datav', [
            'web' => $this->web,
            'dataUrl' => '/admin/datav/data',
        ]);
    }

    /** 英文国家名 -> 中文 */
    private function countryZhMap() {
        return [
            'China' => '中国', 'United States' => '美国', 'United States of America' => '美国', 'Japan' => '日本',
            'South Korea' => '韩国', 'Korea' => '韩国', 'Democratic Rep. Korea' => '朝鲜', 'Russia' => '俄罗斯',
            'United Kingdom' => '英国', 'France' => '法国', 'Germany' => '德国', 'Canada' => '加拿大',
            'Australia' => '澳大利亚', 'Singapore' => '新加坡', 'India' => '印度', 'Brazil' => '巴西',
            'Thailand' => '泰国', 'Vietnam' => '越南', 'Malaysia' => '马来西亚', 'Indonesia' => '印度尼西亚',
            'Philippines' => '菲律宾', 'Italy' => '意大利', 'Spain' => '西班牙', 'Netherlands' => '荷兰',
            'Switzerland' => '瑞士', 'Sweden' => '瑞典', 'Turkey' => '土耳其', 'Saudi Arabia' => '沙特阿拉伯',
            'United Arab Emirates' => '阿联酋', 'New Zealand' => '新西兰', 'Mexico' => '墨西哥',
            'Argentina' => '阿根廷', 'South Africa' => '南非', 'Egypt' => '埃及', 'Nigeria' => '尼日利亚',
            'Kenya' => '肯尼亚', 'Morocco' => '摩洛哥', 'Algeria' => '阿尔及利亚', 'Pakistan' => '巴基斯坦',
            'Bangladesh' => '孟加拉国', 'Myanmar' => '缅甸', 'Cambodia' => '柬埔寨', 'Laos' => '老挝',
            'Nepal' => '尼泊尔', 'Sri Lanka' => '斯里兰卡', 'Kazakhstan' => '哈萨克斯坦', 'Uzbekistan' => '乌兹别克斯坦',
            'Iran' => '伊朗', 'Iraq' => '伊拉克', 'Israel' => '以色列', 'Jordan' => '约旦', 'Lebanon' => '黎巴嫩',
            'Syria' => '叙利亚', 'Afghanistan' => '阿富汗', 'Mongolia' => '蒙古', 'Ireland' => '爱尔兰',
            'Portugal' => '葡萄牙', 'Greece' => '希腊', 'Austria' => '奥地利', 'Belgium' => '比利时',
            'Denmark' => '丹麦', 'Finland' => '芬兰', 'Norway' => '挪威', 'Poland' => '波兰',
            'Czech Republic' => '捷克', 'Czech Rep.' => '捷克', 'Hungary' => '匈牙利', 'Romania' => '罗马尼亚',
            'Bulgaria' => '保加利亚', 'Serbia' => '塞尔维亚', 'Croatia' => '克罗地亚', 'Ukraine' => '乌克兰',
            'Belarus' => '白俄罗斯', 'Lithuania' => '立陶宛', 'Latvia' => '拉脱维亚', 'Estonia' => '爱沙尼亚',
            'Iceland' => '冰岛', 'Luxembourg' => '卢森堡', 'Slovakia' => '斯洛伐克', 'Slovenia' => '斯洛文尼亚',
            'Bosnia and Herz.' => '波黑', 'Albania' => '阿尔巴尼亚', 'Macedonia' => '马其顿', 'Moldova' => '摩尔多瓦',
            'Georgia' => '格鲁吉亚', 'Armenia' => '亚美尼亚', 'Azerbaijan' => '阿塞拜疆', 'Cyprus' => '塞浦路斯',
            'Malta' => '马耳他', 'Chile' => '智利', 'Colombia' => '哥伦比亚', 'Peru' => '秘鲁',
            'Venezuela' => '委内瑞拉', 'Ecuador' => '厄瓜多尔', 'Bolivia' => '玻利维亚', 'Paraguay' => '巴拉圭',
            'Uruguay' => '乌拉圭', 'Panama' => '巴拿马', 'Costa Rica' => '哥斯达黎加', 'Honduras' => '洪都拉斯',
            'Guatemala' => '危地马拉', 'Nicaragua' => '尼加拉瓜', 'El Salvador' => '萨尔瓦多', 'Cuba' => '古巴',
            'Jamaica' => '牙买加', 'Haiti' => '海地', 'Dominican Rep.' => '多米尼加', 'Bahamas' => '巴哈马',
            'Greenland' => '格陵兰', 'Falkland Is.' => '马尔维纳斯群岛', 'W. Sahara' => '西撒哈拉',
            'Dem. Rep. Congo' => '刚果（金）', 'Congo' => '刚果（布）', 'Tanzania' => '坦桑尼亚', 'Angola' => '安哥拉',
            'Mozambique' => '莫桑比克', 'Madagascar' => '马达加斯加', 'Cameroon' => '喀麦隆', 'Ghana' => '加纳',
            'Ethiopia' => '埃塞俄比亚', 'Sudan' => '苏丹', 'South Sudan' => '南苏丹', 'Somalia' => '索马里',
            'Uganda' => '乌干达', 'Zimbabwe' => '津巴布韦', 'Zambia' => '赞比亚', 'Botswana' => '博茨瓦纳',
            'Namibia' => '纳米比亚', 'Senegal' => '塞内加尔', 'Mali' => '马里', 'Chad' => '乍得',
            'Niger' => '尼日尔', 'Burkina Faso' => '布基纳法索', 'Ivory Coast' => '科特迪瓦', 'Guinea' => '几内亚',
            'Benin' => '贝宁', 'Togo' => '多哥', 'Sierra Leone' => '塞拉利昂', 'Liberia' => '利比里亚',
            'Mauritania' => '毛里塔尼亚', 'Lesotho' => '莱索托', 'Swaziland' => '斯威士兰', 'Djibouti' => '吉布提',
            'Comoros' => '科摩罗', 'Central African Rep.' => '中非', 'Gabon' => '加蓬',
            'Equatorial Guinea' => '赤道几内亚', 'Rwanda' => '卢旺达', 'Burundi' => '布隆迪', 'Eritrea' => '厄立特里亚',
            'Tunisia' => '突尼斯', 'Libya' => '利比亚',
        ];
    }

    /** 中文省份名 -> echarts china.json 中的全名 */
    private function normProvince($name) {
        if (function_exists('normalize_province_name')) return normalize_province_name($name);
        return $name;
    }

    /** 按天聚合（一次查询，避免 30 次循环查询） */
    private function trendByDay($table, $timeField, $startTs, $days, $valueExpr = 'COUNT(*)') {
        $out = [];
        try {
            $rows = Db::name($table)
                ->field("FROM_UNIXTIME(`{$timeField}`, '%m-%d') AS d, {$valueExpr} AS v")
                ->where($timeField, '>', 0)
                ->where($timeField, '>=', $startTs)
                ->group('d')
                ->select();
            $map = [];
            foreach ($rows as $r) { $map[$r['d']] = floatval($r['v']); }
            for ($i = $days - 1; $i >= 0; $i--) {
                $ts  = strtotime("-{$i} days");
                $key = date('m-d', $ts);
                $out[] = ['date' => $key, 'value' => isset($map[$key]) ? $map[$key] : 0];
            }
        } catch (\Exception $e) {}
        return $out;
    }

    public function data() {
        $now        = time();
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $monthStart = strtotime(date('Y-m-01 00:00:00'));
        $yesterdayStart = $todayStart - 86400;
        $invalidRegions = ['', '本地', '未知'];
        $userTable = Db::name('user')->getTable();

        // ================= 用户 =================
        $totalUsers   = Db::name('user')->count();
        $todayUsers   = Db::name('user')->where('time', '>=', $todayStart)->count();
        $monthUsers   = Db::name('user')->where('time', '>=', $monthStart)->count();
        $yesterdayUsers = Db::name('user')->where('time', '>=', $yesterdayStart)->where('time', '<', $todayStart)->count();
        $online       = function_exists('get_online_count') ? get_online_count() : 0;
        $onlineUsers  = function_exists('get_online_user_count') ? get_online_user_count() : 0;
        $loginToday   = 0;
        try { $loginToday = Db::name('user')->where('last_login_time', '>=', $todayStart)->count(); } catch (\Exception $e) {}

        // ================= 订单 / 主机 =================
        $orderTotal = Db::name('order')->count();
        $orderToday = Db::name('order')->where('atime', '>=', $todayStart)->count();
        $orderMonth = Db::name('order')->where('atime', '>=', $monthStart)->count();
        $orderYesterday = Db::name('order')->where('atime', '>=', $yesterdayStart)->where('atime', '<', $todayStart)->count();

        $orderStatus = [];
        $statusMap = ['0' => '开通中', '1' => '正常运行', '2' => '已暂停', '3' => '已终止', '4' => '已退款'];
        $statusColor = ['0' => '#38BDF8', '1' => '#10B981', '2' => '#F59E0B', '3' => '#EF4444', '4' => '#94A3B8'];
        try {
            $rows = Db::name('order')->field('state, COUNT(*) AS c')->group('state')->select();
            foreach ($rows as $r) {
                $k = (string) $r['state'];
                $orderStatus[] = [
                    'name'  => isset($statusMap[$k]) ? $statusMap[$k] : ('状态' . $k),
                    'value' => intval($r['c']),
                    'color' => isset($statusColor[$k]) ? $statusColor[$k] : '#64748B',
                ];
            }
        } catch (\Exception $e) {}

        $hostActive   = Db::name('order')->where('state', '1')->count();
        $hostSusp     = Db::name('order')->where('state', '2')->count();
        $hostCreating = Db::name('order')->where('state', '0')->count();
        $hostTerm     = Db::name('order')->where('state', '3')->count();

        $cartPending = 0;
        try { $cartPending = Db::name('shopping_cart')->where('status', '0')->count(); } catch (\Exception $e) {}

        // ================= 收入 =================
        $payTotal = floatval(Db::name('pay')->where('state', '1')->sum('money') ?: 0);
        $payToday = floatval(Db::name('pay')->where('state', '1')->where('time', '>=', $todayStart)->sum('money') ?: 0);
        $payMonth = floatval(Db::name('pay')->where('state', '1')->where('time', '>=', $monthStart)->sum('money') ?: 0);
        $payYesterday = floatval(Db::name('pay')->where('state', '1')->where('time', '>=', $yesterdayStart)->where('time', '<', $todayStart)->sum('money') ?: 0);
        $payCount   = Db::name('pay')->where('state', '1')->count();
        $payPending = Db::name('pay')->where('state', '2')->count();
        $avgRecharge = $payCount > 0 ? round($payTotal / $payCount, 2) : 0;

        // ================= 实名 / 工单 / 卡密 =================
        $realnameVerified = 0; $realnamePending = 0;
        try {
            $realnameVerified = Db::name('user')->where('realname_status', '1')->count();
            $realnamePending  = Db::name('user')->where('realname_status', '3')->count();
        } catch (\Exception $e) {}
        $ticketPending = 0; $ticketTotal = 0;
        try {
            $ticketPending = Db::name('ticket')->where('state', 'in', ['1', '2'])->count();
            $ticketTotal   = Db::name('ticket')->count();
        } catch (\Exception $e) {}
        $cdkeyTotal = 0; $cdkeyUsed = 0;
        try {
            $cdkeyTotal = Db::name('cdkey')->count();
            $cdkeyUsed  = Db::name('cdkey')->where('state', '1')->count();
        } catch (\Exception $e) {}
        $serverCount = 0; $productCount = 0;
        try { $serverCount = Db::name('server')->count(); } catch (\Exception $e) {}
        try { $productCount = Db::name('cart')->count(); } catch (\Exception $e) {}

        // ================= 地域分布（仅汇总数量，不含经纬度/头像） =================
        $countryZh = $this->countryZhMap();
        $countryStats  = [];   // 中文名 => ['count'=>n,'en'=>English]
        $provinceStats = [];   // 省份全名 => count
        $cnProvinces = ['北京', '天津', '上海', '重庆', '河北', '山西', '辽宁', '吉林', '黑龙江', '江苏', '浙江',
            '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南', '广东', '海南', '四川', '贵州', '云南',
            '陕西', '甘肃', '青海', '台湾', '内蒙古', '广西', '西藏', '宁夏', '新疆', '香港', '澳门'];

        try {
            $rows = Db::name('user')
                ->field('last_login_region as region, last_login_country as country, COUNT(*) AS c')
                ->where('last_login_region', 'not in', $invalidRegions)
                ->group('last_login_region, last_login_country')
                ->select();
            foreach ($rows as $r) {
                $region  = trim((string) $r['region']);
                $country = trim((string) $r['country']);
                $cnt     = intval($r['c']);
                if ($region === '') continue;

                if ($country !== '' && isset($countryZh[$country])) {
                    $countryZhName = $countryZh[$country];
                    $countryEnName = $country;
                } else {
                    $isChina = false;
                    foreach ($cnProvinces as $p) { if (strpos($region, $p) !== false) { $isChina = true; break; } }
                    $countryZhName = $isChina ? '中国' : ($country !== '' ? $country : '其他');
                    $countryEnName = $isChina ? 'China' : $country;
                }

                if (!isset($countryStats[$countryZhName])) {
                    $countryStats[$countryZhName] = ['count' => 0, 'en' => $countryEnName];
                }
                $countryStats[$countryZhName]['count'] += $cnt;

                $prov = $this->normProvince($region);
                if ($prov === '') continue;
                if (!isset($provinceStats[$prov])) $provinceStats[$prov] = 0;
                $provinceStats[$prov] += $cnt;
            }
        } catch (\Exception $e) {}

        // 地区订单数
        $orderProvince = [];
        try {
            $rows = Db::name('order')
                ->alias('o')
                ->join($userTable . ' u', 'o.userid = u.id', 'LEFT')
                ->field('u.last_login_region as region, COUNT(*) AS c')
                ->where('u.last_login_region', 'not in', $invalidRegions)
                ->group('u.last_login_region')
                ->select();
            foreach ($rows as $r) {
                $prov = $this->normProvince(trim((string) $r['region']));
                if ($prov === '') continue;
                if (!isset($orderProvince[$prov])) $orderProvince[$prov] = 0;
                $orderProvince[$prov] += intval($r['c']);
            }
        } catch (\Exception $e) {}

        // 地区支付金额
        $payProvince = [];
        try {
            $rows = Db::name('pay')
                ->alias('p')
                ->join($userTable . ' u', 'p.userid = u.id', 'LEFT')
                ->field('u.last_login_region as region, SUM(p.money) AS m')
                ->where('p.state', '1')
                ->where('u.last_login_region', 'not in', $invalidRegions)
                ->group('u.last_login_region')
                ->select();
            foreach ($rows as $r) {
                $prov = $this->normProvince(trim((string) $r['region']));
                if ($prov === '') continue;
                if (!isset($payProvince[$prov])) $payProvince[$prov] = 0;
                $payProvince[$prov] += floatval($r['m']);
            }
        } catch (\Exception $e) {}

        // 排序 + 截断
        $toList = function ($arr, $limit, $round = false) {
            $out = [];
            foreach ($arr as $k => $v) {
                $out[] = ['name' => $k, 'value' => $round ? round(floatval($v), 2) : intval($v)];
            }
            usort($out, function ($a, $b) { return $b['value'] > $a['value'] ? 1 : -1; });
            return array_slice($out, 0, $limit);
        };

        $countryList = [];
        foreach ($countryStats as $zh => $info) {
            $countryList[] = ['name' => $zh, 'en' => $info['en'], 'value' => intval($info['count'])];
        }
        usort($countryList, function ($a, $b) { return $b['value'] > $a['value'] ? 1 : -1; });
        $countryList = array_slice($countryList, 0, 20);

        $provinceList      = $toList($provinceStats, 34);
        $orderProvinceList = $toList($orderProvince, 34);
        $payProvinceList   = $toList($payProvince, 34, true);

        $chinaUsers = isset($countryStats['中国']) ? $countryStats['中国']['count'] : 0;

        // ================= 趋势（近 30 天） =================
        $d30 = strtotime(date('Y-m-d 00:00:00', strtotime('-29 days')));
        $orderTrend = $this->trendByDay('order', 'atime', $d30, 30, 'COUNT(*)');
        $userTrend  = $this->trendByDay('user', 'time', $d30, 30, 'COUNT(*)');

        $payTrend = [];
        try {
            $rows = Db::name('pay')
                ->field("FROM_UNIXTIME(`time`, '%m-%d') AS d, SUM(money) AS v")
                ->where('state', '1')
                ->where('time', '>', 0)
                ->where('time', '>=', $d30)
                ->group('d')
                ->select();
            $map = [];
            foreach ($rows as $r) { $map[$r['d']] = floatval($r['v']); }
            for ($i = 29; $i >= 0; $i--) {
                $ts  = strtotime("-{$i} days");
                $key = date('m-d', $ts);
                $payTrend[] = ['date' => $key, 'value' => isset($map[$key]) ? round($map[$key], 2) : 0];
            }
        } catch (\Exception $e) {}

        // ================= 24 小时下单时段 =================
        $hourDist = [];
        try {
            $rows = Db::name('order')
                ->field("HOUR(FROM_UNIXTIME(`atime`)) AS h, COUNT(*) AS c")
                ->where('atime', '>', 0)
                ->group('h')
                ->select();
            $map = [];
            foreach ($rows as $r) { $map[intval($r['h'])] = intval($r['c']); }
            for ($h = 0; $h < 24; $h++) {
                $hourDist[] = ['hour' => ($h < 10 ? '0' . $h : $h) . ':00', 'value' => isset($map[$h]) ? $map[$h] : 0];
            }
        } catch (\Exception $e) {}

        // ================= 支付方式分布 =================
        $payChannelList = [];
        try {
            $channelNames = Db::name('pays')->column('name', 'id');
            $rows = Db::name('pay')
                ->field('pay AS channel, COUNT(*) AS c, SUM(money) AS m')
                ->where('state', '1')
                ->group('pay')
                ->select();
            $tmp = [];
            foreach ($rows as $r) {
                $key  = (string) $r['channel'];
                $name = isset($channelNames[$key]) ? $channelNames[$key] : ($key === '' ? '未知渠道' : $key);
                if (!isset($tmp[$name])) $tmp[$name] = ['value' => 0, 'count' => 0];
                $tmp[$name]['value'] += floatval($r['m']);
                $tmp[$name]['count'] += intval($r['c']);
            }
            foreach ($tmp as $n => $v) {
                $payChannelList[] = ['name' => $n, 'value' => round($v['value'], 2), 'count' => $v['count']];
            }
            usort($payChannelList, function ($a, $b) { return $b['value'] > $a['value'] ? 1 : -1; });
            $payChannelList = array_slice($payChannelList, 0, 8);
        } catch (\Exception $e) {}

        // ================= 热销套餐 TOP10 =================
        $topProducts = [];
        try {
            $cartNames = Db::name('cart')->column('name', 'id');
            $rows = Db::name('order')
                ->field('cartid, COUNT(*) AS c')
                ->group('cartid')
                ->order('c', 'desc')
                ->limit(200)
                ->select();
            foreach ($rows as $r) {
                $cid = intval($r['cartid']);
                $topProducts[] = [
                    'name'   => isset($cartNames[$cid]) ? $cartNames[$cid] : ('套餐#' . $cid),
                    'orders' => intval($r['c']),
                ];
            }
            usort($topProducts, function ($a, $b) { return $b['orders'] > $a['orders'] ? 1 : -1; });
            $topProducts = array_slice($topProducts, 0, 10);
        } catch (\Exception $e) {}

        // ================= 消费排行 TOP10 =================
        $topUsers = [];
        try {
            $rows = Db::name('pay')
                ->alias('p')
                ->join($userTable . ' u', 'p.userid = u.id', 'LEFT')
                ->field('p.userid, u.name AS uname, u.user AS account, SUM(p.money) AS m')
                ->where('p.state', '1')
                ->group('p.userid')
                ->order('m', 'desc')
                ->limit(10)
                ->select();
            foreach ($rows as $r) {
                $topUsers[] = [
                    'name'  => $r['uname'] ?: ($r['account'] ?: ('用户#' . $r['userid'])),
                    'value' => round(floatval($r['m']), 2),
                ];
            }
        } catch (\Exception $e) {}

        // ================= 实时订单流水 =================
        $recentOrders = [];
        try {
            $cartNames2 = Db::name('cart')->column('name', 'id');
            $rows = Db::name('order')
                ->alias('o')
                ->field('o.id, o.user, o.userid, o.cartid, o.atime, o.state')
                ->order('o.id', 'desc')
                ->limit(15)
                ->select();
            foreach ($rows as $r) {
                $recentOrders[] = [
                    'id'       => intval($r['id']),
                    'user'     => $r['user'] ?: ('用户#' . $r['userid']),
                    'product'  => isset($cartNames2[intval($r['cartid'])]) ? $cartNames2[intval($r['cartid'])] : ('套餐#' . $r['cartid']),
                    'state'    => isset($statusMap[(string) $r['state']]) ? $statusMap[(string) $r['state']] : ('状态' . $r['state']),
                    'state_id' => (string) $r['state'],
                    'time'     => $r['atime'] ? date('m-d H:i', intval($r['atime'])) : '-',
                ];
            }
        } catch (\Exception $e) {}

        // ================= 环比 =================
        $growth = [
            'user_today'  => $yesterdayUsers > 0 ? round((($todayUsers - $yesterdayUsers) / $yesterdayUsers) * 100, 1) : ($todayUsers > 0 ? 100 : 0),
            'order_today' => $orderYesterday > 0 ? round((($orderToday - $orderYesterday) / $orderYesterday) * 100, 1) : ($orderToday > 0 ? 100 : 0),
            'pay_today'   => $payYesterday > 0 ? round((($payToday - $payYesterday) / $payYesterday) * 100, 1) : ($payToday > 0 ? 100 : 0),
        ];

        // ===== 系统重要记录（最近 18 条 + 统计） =====
        $recentRecords = [];
        $recordStats   = ['total' => 0, 'today' => 0, 'week' => 0, 'by_cat' => []];
        try {
            if (function_exists('sys_record_recent')) {
                $rows = sys_record_recent(18);
                $cats = function_exists('sys_record_categories') ? sys_record_categories() : [];
                foreach ($rows as $r) {
                    $recentRecords[] = [
                        'id'       => intval($r['id']),
                        'category' => $r['category'],
                        'cat_name' => isset($cats[$r['category']]) ? $cats[$r['category']]['name'] : $r['category'],
                        'title'    => $r['title'],
                        'summary'  => $r['summary'],
                        'operator' => $r['operator_name'] ?: $r['operator_type'],
                        'level'    => intval($r['level']),
                        'time'     => date('H:i:s', intval($r['create_time'])),
                        'date'     => $r['date'],
                    ];
                }
            }
            if (function_exists('sys_record_stats')) {
                $recordStats = sys_record_stats();
            }
        } catch (\Exception $e) {}

        return json([
            'code' => 1,
            'generated_at' => date('Y-m-d H:i:s'),
            'stats' => [
                'total_users'       => $totalUsers,
                'today_users'       => $todayUsers,
                'month_users'       => $monthUsers,
                'online'            => intval($online),
                'online_users'      => intval($onlineUsers),
                'login_today'       => intval($loginToday),
                'china_users'       => intval($chinaUsers),
                'order_total'       => $orderTotal,
                'order_today'       => $orderToday,
                'order_month'       => $orderMonth,
                'host_active'       => $hostActive,
                'host_suspended'    => $hostSusp,
                'host_creating'     => $hostCreating,
                'host_terminated'   => $hostTerm,
                'cart_pending'      => intval($cartPending),
                'pay_total'         => round($payTotal, 2),
                'pay_today'         => round($payToday, 2),
                'pay_month'         => round($payMonth, 2),
                'pay_count'         => $payCount,
                'pay_pending'       => $payPending,
                'avg_recharge'      => $avgRecharge,
                'realname_verified' => $realnameVerified,
                'realname_pending'  => $realnamePending,
                'ticket_pending'    => $ticketPending,
                'ticket_total'      => $ticketTotal,
                'cdkey_total'       => $cdkeyTotal,
                'cdkey_used'        => $cdkeyUsed,
                'server_count'      => $serverCount,
                'product_count'     => $productCount,
            ],
            'growth'         => $growth,
            'country_stats'  => $countryList,
            'province_stats' => $provinceList,
            'order_province' => $orderProvinceList,
            'pay_province'   => $payProvinceList,
            'trend'          => [
                'dates' => array_column($payTrend, 'date'),
                'pay'   => array_column($payTrend, 'value'),
                'order' => array_column($orderTrend, 'value'),
                'user'  => array_column($userTrend, 'value'),
            ],
            'hour_dist'     => $hourDist,
            'pay_channel'   => $payChannelList,
            'order_status'  => $orderStatus,
            'top_products'  => $topProducts,
            'top_users'     => $topUsers,
            'recent_orders' => $recentOrders,
            'records'       => $recentRecords,
            'record_stats'  => $recordStats,
        ]);
    }
}
