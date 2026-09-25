<?php
namespace app\index\controller;
use think\Controller;
use think\Db;
use think\Request;

/**
 * 数据大屏控制器（前台）
 * 世界地图显示用户分布，点击中国下钻到省份地图
 */
class Datav extends Base {
    public function _initialize() {
        $this->web = web_config();
        if ($this->web["template"] == "layui") {
            $this->web["template"] = "default";
        }
        if (function_exists('ensure_user_geo_columns')) ensure_user_geo_columns();
        if (function_exists('ensure_user_columns')) ensure_user_columns();
        $this->assignCommonVars();
    }

    public function index() {
        return $this->fetch('/'.$this->web["template"].'/datav', [
            'web' => $this->web,
            'dataUrl' => '/datav/data',
        ]);
    }

    /**
     * 数据接口：返回用户位置点 + 国家统计 + 省份统计
     */
    public function data() {
        // 查询有地区信息的用户
        $users = Db::name('user')
            ->field('id,name,avatar,last_login_region,last_login_city,last_login_lng,last_login_lat,last_login_country')
            ->where('last_login_region', 'not in', ['', '本地', '未知'])
            ->limit(2000)
            ->select();

        // 英文国家名 -> 中文（与前端 cnMap 对齐，保证面板/地图中文显示）
        $countryZh = [
            'China' => '中国', 'United States' => '美国', 'United States of America' => '美国', 'Japan' => '日本',
            'South Korea' => '韩国', 'Korea' => '韩国', 'Russia' => '俄罗斯', 'United Kingdom' => '英国',
            'France' => '法国', 'Germany' => '德国', 'Canada' => '加拿大', 'Australia' => '澳大利亚',
            'Singapore' => '新加坡', 'India' => '印度', 'Brazil' => '巴西', 'Thailand' => '泰国',
            'Vietnam' => '越南', 'Malaysia' => '马来西亚', 'Indonesia' => '印度尼西亚', 'Philippines' => '菲律宾',
            'Italy' => '意大利', 'Spain' => '西班牙', 'Netherlands' => '荷兰', 'Switzerland' => '瑞士',
            'Sweden' => '瑞典', 'Turkey' => '土耳其', 'Saudi Arabia' => '沙特阿拉伯', 'United Arab Emirates' => '阿联酋',
            'New Zealand' => '新西兰', 'Mexico' => '墨西哥', 'Argentina' => '阿根廷', 'South Africa' => '南非',
            'Egypt' => '埃及', 'Nigeria' => '尼日利亚', 'Kenya' => '肯尼亚', 'Pakistan' => '巴基斯坦',
            'Bangladesh' => '孟加拉国', 'Myanmar' => '缅甸', 'Cambodia' => '柬埔寨', 'Laos' => '老挝',
            'Nepal' => '尼泊尔', 'Sri Lanka' => '斯里兰卡', 'Kazakhstan' => '哈萨克斯坦', 'Uzbekistan' => '乌兹别克斯坦',
            'Iran' => '伊朗', 'Iraq' => '伊拉克', 'Israel' => '以色列', 'Jordan' => '约旦', 'Lebanon' => '黎巴嫩',
            'Syria' => '叙利亚', 'Afghanistan' => '阿富汗', 'Mongolia' => '蒙古', 'Ireland' => '爱尔兰',
            'Portugal' => '葡萄牙', 'Greece' => '希腊', 'Austria' => '奥地利', 'Belgium' => '比利时',
            'Denmark' => '丹麦', 'Finland' => '芬兰', 'Norway' => '挪威', 'Poland' => '波兰',
            'Czech Republic' => '捷克', 'Hungary' => '匈牙利', 'Romania' => '罗马尼亚', 'Bulgaria' => '保加利亚',
            'Serbia' => '塞尔维亚', 'Croatia' => '克罗地亚', 'Ukraine' => '乌克兰', 'Belarus' => '白俄罗斯',
            'Lithuania' => '立陶宛', 'Latvia' => '拉脱维亚', 'Estonia' => '爱沙尼亚', 'Iceland' => '冰岛',
            'Luxembourg' => '卢森堡', 'Slovakia' => '斯洛伐克', 'Slovenia' => '斯洛文尼亚', 'Albania' => '阿尔巴尼亚',
            'Macedonia' => '马其顿', 'Moldova' => '摩尔多瓦', 'Georgia' => '格鲁吉亚', 'Armenia' => '亚美尼亚',
            'Azerbaijan' => '阿塞拜疆', 'Cyprus' => '塞浦路斯', 'Malta' => '马耳他', 'Chile' => '智利',
            'Colombia' => '哥伦比亚', 'Peru' => '秘鲁', 'Venezuela' => '委内瑞拉', 'Ecuador' => '厄瓜多尔',
            'Bolivia' => '玻利维亚', 'Paraguay' => '巴拉圭', 'Uruguay' => '乌拉圭', 'Panama' => '巴拿马',
            'Costa Rica' => '哥斯达黎加', 'Honduras' => '洪都拉斯', 'Guatemala' => '危地马拉', 'Nicaragua' => '尼加拉瓜',
            'El Salvador' => '萨尔瓦多', 'Cuba' => '古巴', 'Jamaica' => '牙买加', 'Haiti' => '海地',
            'Dominican Rep.' => '多米尼加', 'Bahamas' => '巴哈马', 'Greenland' => '格陵兰', 'Falkland Is.' => '马尔维纳斯群岛',
            'W. Sahara' => '西撒哈拉', 'Dem. Rep. Congo' => '刚果（金）', 'Congo' => '刚果（布）', 'Tanzania' => '坦桑尼亚',
            'Angola' => '安哥拉', 'Mozambique' => '莫桑比克', 'Madagascar' => '马达加斯加', 'Cameroon' => '喀麦隆',
            'Ghana' => '加纳', 'Ethiopia' => '埃塞俄比亚', 'Sudan' => '苏丹', 'South Sudan' => '南苏丹',
            'Somalia' => '索马里', 'Uganda' => '乌干达', 'Zimbabwe' => '津巴布韦', 'Zambia' => '赞比亚',
            'Botswana' => '博茨瓦纳', 'Namibia' => '纳米比亚', 'Senegal' => '塞内加尔', 'Mali' => '马里',
            'Chad' => '乍得', 'Niger' => '尼日尔', 'Burkina Faso' => '布基纳法索', 'Ivory Coast' => '科特迪瓦',
            'Guinea' => '几内亚', 'Benin' => '贝宁', 'Togo' => '多哥', 'Sierra Leone' => '塞拉利昂',
            'Liberia' => '利比里亚', 'Mauritania' => '毛里塔尼亚', 'Lesotho' => '莱索托', 'Swaziland' => '斯威士兰',
            'Djibouti' => '吉布提', 'Comoros' => '科摩罗', 'Central African Rep.' => '中非', 'Gabon' => '加蓬',
            'Equatorial Guinea' => '赤道几内亚', 'Rwanda' => '卢旺达', 'Burundi' => '布隆迪', 'Eritrea' => '厄立特里亚',
            'Tunisia' => '突尼斯', 'Libya' => '利比亚',
        ];

        // 省份中心经纬度（无经纬度时按省份中心定位，确保用户也能在地图上显示）
        $provinceCenter = [
            '北京' => [116.40, 39.90], '天津' => [117.20, 39.12], '上海' => [121.47, 31.23],
            '重庆' => [106.55, 29.56], '广东' => [113.27, 23.13], '江苏' => [118.78, 32.06],
            '浙江' => [120.15, 30.28], '山东' => [117.00, 36.65], '河南' => [113.62, 34.75],
            '河北' => [114.50, 38.05], '山西' => [112.55, 37.87], '湖北' => [114.30, 30.60],
            '湖南' => [112.93, 28.23], '福建' => [119.30, 26.08], '安徽' => [117.28, 31.86],
            '江西' => [115.89, 28.68], '四川' => [104.07, 30.67], '贵州' => [106.71, 26.57],
            '云南' => [102.73, 25.04], '陕西' => [108.95, 34.27], '甘肃' => [103.82, 36.06],
            '青海' => [101.78, 36.62], '海南' => [110.35, 20.02], '辽宁' => [123.43, 41.80],
            '吉林' => [125.32, 43.90], '黑龙江' => [126.63, 45.75], '内蒙古' => [111.75, 40.84],
            '新疆' => [87.62, 43.83], '西藏' => [91.13, 29.65], '宁夏' => [106.27, 38.47],
            '广西' => [108.32, 22.82], '香港' => [114.17, 22.32], '澳门' => [113.55, 22.20],
            '台湾' => [121.55, 25.03],
        ];

        $points = [];
        $countryStats = [];  // 国家 => 数量
        $provinceStats = []; // 省份 => 数量

        foreach ($users as $u) {
            $lng = floatval($u['last_login_lng']);
            $lat = floatval($u['last_login_lat']);
            $region = $u['last_login_region'] ?: '';
            $country = $u['last_login_country'] ?: '';

            // 国家名中文化（英文 -> 中文），空值按地区推断
            if ($country !== '') {
                if (isset($countryZh[$country])) $country = $countryZh[$country];
                elseif (preg_match('/[\x{4e00}-\x{9fa5}]/u', $country) === 0 && strpos($country, '中国') === false) {
                    // 英文但不在映射表，保留原文
                }
            }
            // 若国家为空，根据 region 是否含中国省份判断是否中国
            if ($country === '') {
                $isChina = false;
                foreach ($provinceCenter as $prov => $coord) {
                    if (strpos($region, $prov) !== false) { $isChina = true; break; }
                }
                $country = $isChina ? '中国' : '其他';
            }

            // 无经纬度：尝试用省份中心坐标
            if ($lng == 0 && $lat == 0) {
                $matched = false;
                foreach ($provinceCenter as $prov => $coord) {
                    if (strpos($region, $prov) !== false) {
                        $lng = $coord[0];
                        $lat = $coord[1];
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) continue; // 实在没有定位信息的跳过
            }

            $avatar = function_exists('get_user_avatar') ? get_user_avatar($u) : '';
            // 头像相对路径补全为绝对 URL，供地图 image:// 符号使用（跳过 data: 和 http 开头的）
            if ($avatar && strpos($avatar, 'http') !== 0 && strpos($avatar, 'data:') !== 0 && strpos($avatar, '//') !== 0) {
                $domain = isset($this->web['domain']) && $this->web['domain'] ? rtrim($this->web['domain'], '/') : request()->domain();
                $avatar = $domain . '/' . ltrim($avatar, '/');
            }

            $points[] = [
                'name' => $u['name'] ?: ('用户#' . $u['id']),
                'value' => [$lng, $lat, 1],
                'region' => $region,
                'city' => $u['last_login_city'],
                'country' => $country,
                'avatar' => $avatar,
            ];

            // 统计
            $countryKey = $country;
            if (!isset($countryStats[$countryKey])) $countryStats[$countryKey] = 0;
            $countryStats[$countryKey]++;

            if ($region) {
                if (!isset($provinceStats[$region])) $provinceStats[$region] = 0;
                $provinceStats[$region]++;
            }
        }

        // 国家统计排序（取前 15）
        arsort($countryStats);
        $countryList = [];
        foreach ($countryStats as $c => $n) {
            $countryList[] = ['name' => $c, 'count' => $n];
        }

        // 省份统计排序（取前 20）
        arsort($provinceStats);
        $provinceList = [];
        foreach ($provinceStats as $p => $n) {
            $provinceList[] = ['name' => $p, 'count' => $n];
        }

        $totalUsers = Db::name('user')->count();
        $chinaUsers = isset($countryStats['中国']) ? $countryStats['中国'] : 0;
        $onlineCount = function_exists('get_online_count') ? get_online_count() : 0;
        $onlineUsers = function_exists('get_online_user_count') ? get_online_user_count() : 0;

        return json([
            'code' => 1,
            'points' => $points,
            'stats' => [
                'total_users' => $totalUsers,
                'china_users' => $chinaUsers,
                'online' => $onlineCount,
                'online_users' => $onlineUsers,
            ],
            'country_stats' => array_slice($countryList, 0, 15),
            'province_stats' => array_slice($provinceList, 0, 20),
        ]);
    }
}
