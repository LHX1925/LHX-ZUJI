<?php
namespace app\index\controller;
use think\Controller;
use think\Db;
use think\Request;

/**
 * 活动中心 + 每日抽奖 前台控制器
 */
class Activity extends Base {
    public function _initialize() {
        $this->web = web_config();
        if ($this->web["template"] == "layui") {
            $this->web["template"] = "default";
        }
        if ($this->web["wh"] == "1") {
            exit($this->web["whxx"]);
        }
        if (!session("userid")) {
            $this->redirect('/login');
        }
        $this->user = Db::name('user')->where('id', session("userid"))->find();
        if (!$this->user || $this->user["state"] == "0") {
            session("userid", null);
            $this->redirect('/login');
        }
        ensure_activity_tables();
        $this->assignCommonVars();
        // 注入会员信息（user/header.html 顶部 VIP 角标需要）
        $membershipLevel = intval($this->user['membership_level'] ?? 0);
        $membershipInfo = null;
        if ($membershipLevel > 0) {
            try {
                $membershipInfo = Db::name('membership_levels')->where('level', $membershipLevel)->where('status', 1)->find();
            } catch (\Exception $e) {}
        }
        $this->assign([
            'user' => $this->user,
            'membershipLevel' => $membershipLevel,
            'membershipInfo'  => $membershipInfo,
        ]);
    }

    /**
     * 活动中心首页
     */
    public function index() {
        $activities = Db::name('quiz_activity')->where('status', 1)->order('sort asc, id asc')->select();
        foreach ($activities as &$a) {
            $a['type_name'] = $this->typeName($a['type']);
            $a['prize_text'] = $this->prizeText($a);
        }
        // 抽奖配置
        $lotteryConfig = Db::name('lottery_config')->where('id', 1)->find();
        if (!$lotteryConfig) {
            Db::name('lottery_config')->insert(['id' => 1, 'daily_times' => 1, 'need_realname' => 0, 'status' => 1, 'notice' => '']);
            $lotteryConfig = Db::name('lottery_config')->where('id', 1)->find();
        }
        $today = date('Y-m-d');
        $lotteryTodayCount = Db::name('lottery_record')->where('userid', session('userid'))->where('date', $today)->count();
        $lotteryRemain = max(0, intval($lotteryConfig['daily_times']) - $lotteryTodayCount);

        // 抽奖奖品列表
        $lotteryPrizes = Db::name('lottery_prize')->where('status', 1)->order('sort asc, id asc')->select();

        return $this->fetch('/'.$this->web["template"].'/user/activity', [
            'activities' => $activities,
            'lotteryConfig' => $lotteryConfig,
            'lotteryTodayCount' => $lotteryTodayCount,
            'lotteryRemain' => $lotteryRemain,
            'lotteryPrizes' => $lotteryPrizes,
            'user' => $this->user,
        ]);
    }

    /**
     * 答题页面（获取题目）
     */
    public function quiz() {
        $activityId = intval(input('id', 0));
        $activity = Db::name('quiz_activity')->where('id', $activityId)->where('status', 1)->find();
        if (!$activity) {
            $this->error('活动不存在或已关闭', '/user/activity');
        }
        // 检查每日答题次数
        $today = date('Y-m-d');
        $todayStart = strtotime($today . ' 00:00:00');
        $todayCount = Db::name('quiz_record')->where('activity_id', $activityId)->where('userid', session('userid'))->where('create_time', '>=', $todayStart)->count();
        if ($todayCount >= intval($activity['daily_limit'])) {
            $this->error('今日答题次数已用完，请明天再来', '/user/activity');
        }

        // 抽取题目
        $questionCount = intval($activity['question_count']);
        $questions = Db::name('quiz_question')->where('status', 1)->orderRaw('RAND()')->limit($questionCount)->select();
        if (count($questions) < $questionCount) {
            // 题库不足
            $questions = Db::name('quiz_question')->where('status', 1)->select();
        }
        if (empty($questions)) {
            $this->error('题库暂无题目，请稍后再试', '/user/activity');
        }
        // 打乱题目顺序
        shuffle($questions);

        return $this->fetch('/'.$this->web["template"].'/user/quiz', [
            'activity' => $activity,
            'questions' => $questions,
            'user' => $this->user,
        ]);
    }

    /**
     * 提交答题结果
     */
    public function quizSubmit() {
        $array = ['code' => '-1', 'msg' => ''];
        if (!Request::instance()->isPost()) {
            $array['msg'] = '非法请求';
            return json($array);
        }
        $activityId = intval(input('activity_id', 0));
        $answers = input('answers', ''); // JSON: {"qid":idx,...}
        $activity = Db::name('quiz_activity')->where('id', $activityId)->where('status', 1)->find();
        if (!$activity) {
            $array['msg'] = '活动不存在或已关闭';
            return json($array);
        }

        // 检查每日答题次数
        $today = date('Y-m-d');
        $todayStart = strtotime($today . ' 00:00:00');
        $todayCount = Db::name('quiz_record')->where('activity_id', $activityId)->where('userid', session('userid'))->where('create_time', '>=', $todayStart)->count();
        if ($todayCount >= intval($activity['daily_limit'])) {
            $array['msg'] = '今日答题次数已用完';
            return json($array);
        }

        $answersArr = json_decode($answers, true);
        if (!is_array($answersArr) || empty($answersArr)) {
            $array['msg'] = '请完成答题';
            return json($array);
        }

        // 判分
        $score = 0;
        $total = 0;
        foreach ($answersArr as $qid => $ansIdx) {
            $q = Db::name('quiz_question')->where('id', intval($qid))->find();
            if ($q) {
                $total++;
                if (intval($ansIdx) === intval($q['answer'])) {
                    $score++;
                }
            }
        }

        $passed = $score >= intval($activity['pass_count']);
        $recordId = Db::name('quiz_record')->insertGetId([
            'activity_id' => $activityId,
            'userid' => session('userid'),
            'score' => $score,
            'total' => $total,
            'passed' => $passed ? 1 : 0,
            'prize_status' => 0,
            'create_time' => time(),
        ]);

        $msg = '答对 ' . $score . '/' . $total . ' 题';
        if (!$passed) {
            $msg .= '，未达到通过要求（需答对 ' . $activity['pass_count'] . ' 题），无奖励';
            return json(['code' => '1', 'msg' => $msg, 'passed' => 0]);
        }

        // 发放奖励
        $prizeMsg = $this->grantPrize($activity, session('userid'));
        Db::name('quiz_record')->where('id', $recordId)->update(['prize_status' => 1]);

        return json(['code' => '1', 'msg' => $msg . '，' . $prizeMsg, 'passed' => 1]);
    }

    /**
     * 发放答题奖励
     */
    private function grantPrize($activity, $userid) {
        $type = $activity['type'];
        if ($type == 'points') {
            $points = intval($activity['prize_points']);
            Db::name('user')->where('id', $userid)->setInc('points', $points);
            if (function_exists('ensure_points_log_table')) {
                ensure_points_log_table();
                Db::name('points_log')->insert([
                    'userid' => $userid,
                    'type' => 'quiz',
                    'points' => $points,
                    'content' => '答题活动奖励，获得' . $points . '积分',
                    'time' => time(),
                ]);
            }
            return '获得 ' . $points . ' 积分';
        }
        if ($type == 'balance') {
            $balance = floatval($activity['prize_balance']);
            Db::name('user')->where('id', $userid)->setInc('money', $balance);
            Db::name('transaction')->insert([
                'userid' => $userid,
                'content' => '答题活动奖励，余额增加' . $balance . '元',
                'time' => time(),
            ]);
            return '获得 ' . $balance . ' 元余额';
        }
        if ($type == 'host') {
            $cartid = intval($activity['prize_cartid']);
            $days = intval($activity['prize_host_days']);
            if ($cartid > 0) {
                $this->grantHost($userid, $cartid, $days);
                return '获得主机奖励（' . $days . '天）';
            }
            return '主机奖励已发放';
        }
        return '';
    }

    /**
     * 发放主机奖励（自动开通主机）
     */
    private function grantHost($userid, $cartid, $days) {
        try {
            $cart = Db::name('cart')->where('id', $cartid)->find();
            if (!$cart) return;
            $atime = time();
            $ztime = $atime + intval($days) * 86400;
            $orderId = Db::name('order')->insertGetId([
                'userid' => $userid,
                'cartid' => $cartid,
                'user' => 'user' . rand(10000, 99999),
                'password' => substr(md5(uniqid(mt_rand(), true)), 0, 12),
                'atime' => $atime,
                'ztime' => $ztime,
                'state' => '0',
                'auto_create_at' => time(),
            ]);
            if (function_exists('ensure_order_ordernumber_column')) {
                ensure_order_ordernumber_column();
                $orderNumber = generate_order_number($orderId);
                Db::name('order')->where('id', $orderId)->update(['ordernumber' => $orderNumber]);
            }
        } catch (\Throwable $e) {}
    }

    /**
     * 每日抽奖（执行抽奖）
     */
    public function lottery() {
        $array = ['code' => '-1', 'msg' => ''];
        if (!Request::instance()->isPost()) {
            $array['msg'] = '非法请求';
            return json($array);
        }
        ensure_activity_tables();
        $config = Db::name('lottery_config')->where('id', 1)->find();
        if (!$config || $config['status'] != 1) {
            $array['msg'] = '抽奖活动未开启';
            return json($array);
        }
        // 实名校验
        if ($config['need_realname'] == 1 && $this->user['realname_status'] != 1) {
            $array['msg'] = '请先完成实名认证后再参与抽奖';
            return json($array);
        }
        // 检查每日次数
        $today = date('Y-m-d');
        $todayCount = Db::name('lottery_record')->where('userid', session('userid'))->where('date', $today)->count();
        if ($todayCount >= intval($config['daily_times'])) {
            $array['msg'] = '今日抽奖次数已用完，请明天再来';
            return json($array);
        }

        // 按概率抽取
        $prizes = Db::name('lottery_prize')->where('status', 1)->order('sort asc, id asc')->select();
        if (empty($prizes)) {
            $array['msg'] = '暂无奖品配置';
            return json($array);
        }

        // 过滤库存不足的奖品
        $available = [];
        foreach ($prizes as $p) {
            if (intval($p['stock']) == 0) continue; // 库存为0跳过
            $available[] = $p;
        }
        if (empty($available)) {
            $array['msg'] = '奖品已抽完';
            return json($array);
        }

        // 按概率抽取
        $prize = $this->drawByProbability($available);

        // 记录
        $recordId = Db::name('lottery_record')->insertGetId([
            'userid' => session('userid'),
            'prize_id' => $prize['id'],
            'prize_name' => $prize['name'],
            'prize_type' => $prize['type'],
            'prize_value' => '',
            'prize_status' => 0,
            'date' => $today,
            'create_time' => time(),
        ]);

        // 发放奖励
        $prizeValue = '';
        if ($prize['type'] != 'none') {
            $prizeValue = $this->grantLotteryPrize($prize, session('userid'));
            Db::name('lottery_record')->where('id', $recordId)->update(['prize_status' => 1, 'prize_value' => $prizeValue]);
            // 扣库存
            if (intval($prize['stock']) > 0) {
                Db::name('lottery_prize')->where('id', $prize['id'])->setDec('stock');
            }
        }

        return json([
            'code' => '1',
            'msg' => $prize['type'] == 'none' ? '很遗憾，未中奖' : '恭喜中奖！' . $prizeValue,
            'prize' => $prize,
            'prize_value' => $prizeValue,
        ]);
    }

    /**
     * 当前用户的抽奖记录
     */
    public function records() {
        $userid = intval(session('userid'));
        if (!$userid) return json(['code' => -1, 'msg' => '请先登录']);
        $list = Db::name('lottery_record')->where('userid', $userid)->order('id desc')->limit(50)->select();
        $records = [];
        foreach ($list as $r) {
            $records[] = [
                'time' => date('Y-m-d H:i:s', intval($r['create_time'])),
                'prize_name' => $r['prize_name'],
                'prize_type' => $r['prize_type'],
            ];
        }
        return json(['code' => 1, 'records' => $records]);
    }

    /**
     * 公开的用户参与记录（头像+用户名+抽奖时间+中奖内容）
     */
    public function publicRecords() {
        ensure_activity_tables();
        // 只取中奖记录（prize_type != 'none'），按时间倒序
        $list = Db::name('lottery_record')
            ->where('prize_type', 'neq', 'none')
            ->order('id desc')
            ->limit(20)
            ->select();
        $records = [];
        foreach ($list as $r) {
            $user = Db::name('user')->where('id', $r['userid'])->field('name,avatar')->find();
            $avatar = '';
            if ($user) {
                $avatar = function_exists('get_user_avatar') ? get_user_avatar(['id' => $r['userid'], 'avatar' => $user['avatar'], 'name' => $user['name']]) : '';
                if ($avatar && strpos($avatar, 'http') !== 0 && strpos($avatar, 'data:') !== 0 && strpos($avatar, '//') !== 0) {
                    $domain = isset($this->web['domain']) && $this->web['domain'] ? rtrim($this->web['domain'], '/') : request()->domain();
                    $avatar = $domain . '/' . ltrim($avatar, '/');
                }
            }
            $records[] = [
                'name' => $user ? $user['name'] : ('用户#' . $r['userid']),
                'avatar' => $avatar,
                'time' => date('Y-m-d H:i', intval($r['create_time'])),
                'prize_name' => $r['prize_name'],
            ];
        }
        return json(['code' => 1, 'records' => $records]);
    }

    /**
     * 答题正确率排行榜
     */
    public function quizRank() {
        ensure_activity_tables();
        // 按用户聚合答题记录，计算正确率 = 总答对/总题数
        $list = Db::name('quiz_record')
            ->field('userid, SUM(score) as total_score, SUM(total) as total_questions, COUNT(*) as times')
            ->group('userid')
            ->having('total_questions > 0')
            ->order('total_score desc, total_questions asc')
            ->limit(50)
            ->select();

        $rank = [];
        $idx = 0;
        foreach ($list as $r) {
            $idx++;
            $user = Db::name('user')->where('id', $r['userid'])->field('name,avatar')->find();
            $avatar = '';
            if ($user) {
                $avatar = function_exists('get_user_avatar') ? get_user_avatar(['id' => $r['userid'], 'avatar' => $user['avatar'], 'name' => $user['name']]) : '';
                if ($avatar && strpos($avatar, 'http') !== 0 && strpos($avatar, 'data:') !== 0 && strpos($avatar, '//') !== 0) {
                    $domain = isset($this->web['domain']) && $this->web['domain'] ? rtrim($this->web['domain'], '/') : request()->domain();
                    $avatar = $domain . '/' . ltrim($avatar, '/');
                }
            }
            $accuracy = $r['total_questions'] > 0 ? round($r['total_score'] / $r['total_questions'] * 100, 1) : 0;
            $rank[] = [
                'rank' => $idx,
                'name' => $user ? $user['name'] : ('用户#' . $r['userid']),
                'avatar' => $avatar,
                'accuracy' => $accuracy,
                'score' => intval($r['total_score']),
                'questions' => intval($r['total_questions']),
                'times' => intval($r['times']),
            ];
        }
        return json(['code' => 1, 'rank' => $rank]);
    }

    /**
     * 按概率抽取奖品
     */
    private function drawByProbability($prizes) {
        $total = 0;
        foreach ($prizes as $p) {
            $total += floatval($p['probability']);
        }
        if ($total <= 0) {
            // 无概率配置，随机
            return $prizes[array_rand($prizes)];
        }
        $rand = mt_rand(1, intval($total * 10000)) / 10000;
        $cur = 0;
        foreach ($prizes as $p) {
            $cur += floatval($p['probability']);
            if ($rand <= $cur) {
                return $p;
            }
        }
        return end($prizes);
    }

    /**
     * 发放抽奖奖励
     */
    private function grantLotteryPrize($prize, $userid) {
        $type = $prize['type'];
        if ($type == 'points') {
            $points = intval($prize['prize_points']);
            Db::name('user')->where('id', $userid)->setInc('points', $points);
            if (function_exists('ensure_points_log_table')) {
                ensure_points_log_table();
                Db::name('points_log')->insert([
                    'userid' => $userid,
                    'type' => 'lottery',
                    'points' => $points,
                    'content' => '抽奖中奖，获得' . $points . '积分',
                    'time' => time(),
                ]);
            }
            return '获得 ' . $points . ' 积分';
        }
        if ($type == 'balance') {
            $balance = floatval($prize['prize_balance']);
            Db::name('user')->where('id', $userid)->setInc('money', $balance);
            Db::name('transaction')->insert([
                'userid' => $userid,
                'content' => '抽奖中奖，余额增加' . $balance . '元',
                'time' => time(),
            ]);
            return '获得 ' . $balance . ' 元余额';
        }
        if ($type == 'host') {
            $cartid = intval($prize['prize_cartid']);
            $days = intval($prize['prize_host_days']);
            if ($cartid > 0) {
                $this->grantHost($userid, $cartid, $days);
                return '获得主机奖励（' . $days . '天）';
            }
            return '主机奖励';
        }
        return '';
    }

    private function typeName($type) {
        $map = ['points' => '答题领积分', 'host' => '答题领主机', 'balance' => '答题领余额'];
        return isset($map[$type]) ? $map[$type] : $type;
    }

    private function prizeText($activity) {
        if ($activity['type'] == 'points') return '奖励 ' . $activity['prize_points'] . ' 积分';
        if ($activity['type'] == 'balance') return '奖励 ' . $activity['prize_balance'] . ' 元余额';
        if ($activity['type'] == 'host') return '奖励主机 ' . $activity['prize_host_days'] . ' 天';
        return '';
    }
}
