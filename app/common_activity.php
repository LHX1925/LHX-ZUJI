<?php
/**
 * ============================================================
 * 活动中心 + 每日抽奖 + 域名商城 + 数据大屏 公共函数库
 * ============================================================
 * 本文件由 common.php 末尾自动加载
 */

if (!function_exists('ensure_email_queue_table')) {
/**
 * 确保邮件发送队列表存在
 */
function ensure_email_queue_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $p = $prefix ?: 'sale_';
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}email_queue` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `email` varchar(255) NOT NULL DEFAULT '',
            `subject` varchar(500) NOT NULL DEFAULT '',
            `body` text,
            `attempts` int(11) NOT NULL DEFAULT '0' COMMENT '重试次数',
            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待发送1已发送2失败',
            `last_error` varchar(1000) NOT NULL DEFAULT '',
            `create_time` int(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
}

if (!function_exists('enqueue_email')) {
/**
 * 将邮件加入队列并立即尝试发送（同步发送 + 队列兜底重试）
 * @param string $email 收件人邮箱
 * @param string $subject 邮件标题
 * @param string $body 邮件正文（纯文本或HTML片段）
 * @return bool 是否成功入队
 */
function enqueue_email($email, $subject, $body) {
    try {
        ensure_email_queue_table();
        $queueId = \think\Db::name('email_queue')->insertGetId([
            'email' => $email,
            'subject' => $subject,
            'body' => $body,
            'attempts' => 0,
            'status' => 0,
            'create_time' => time(),
        ]);
        // 立即尝试同步发送（不等 cron）
        if ($queueId) {
            try_send_email_now(intval($queueId));
        }
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
}

if (!function_exists('try_send_email_now')) {
/**
 * 立即尝试发送队列中的单条邮件（同步方式，请求内直接发）
 * @param int $queueId 队列记录ID
 */
function try_send_email_now($queueId) {
    // 显式加载 PHPMailer（防止自动加载失效导致邮件全部静默发送失败）
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        $pmDir = rtrim(PATH, '/\\') . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'PHPMailer' . DIRECTORY_SEPARATOR . 'PHPMailer' . DIRECTORY_SEPARATOR;
        if (is_file($pmDir . 'Exception.php')) require_once $pmDir . 'Exception.php';
        if (is_file($pmDir . 'PHPMailer.php')) require_once $pmDir . 'PHPMailer.php';
        if (is_file($pmDir . 'SMTP.php')) require_once $pmDir . 'SMTP.php';
    }
    try {
        $row = \think\Db::name('email_queue')->where('id', $queueId)->find();
        if (!$row || $row['status'] != 0) return;
        $web = web_config();
        $host = trim($web['emailhost'] ?? '');
        $username = trim($web['emailname'] ?? '');
        $password = trim($web['emailpass'] ?? '');
        if (empty($host) || empty($username) || empty($password)) {
            // SMTP 未配置，标记失败
            \think\Db::name('email_queue')->where('id', $queueId)->update([
                'status' => 2,
                'attempts' => 5,
                'last_error' => 'SMTP 未配置：请在「网站设置 → 邮件设置」填写 SMTP 信息',
            ]);
            return;
        }
        $mail = new \PHPMailer\PHPMailer\PHPMailer();
        $mail->IsSMTP();
        $mail->CharSet = $web['emailchar'] ?? 'UTF-8';
        $mail->SMTPAuth = ($web['emailauth'] ?? 'true') === 'false' ? false : true;
        $mail->Timeout = 15;
        $mail->SMTPDebug = 0;
        if (!empty($web['emailsecure'])) {
            $mail->SMTPSecure = $web['emailsecure'];
        }
        $mail->Port = intval($web['emailport'] ?? 25);
        $mail->Host = $host;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->From = $username;
        $mail->FromName = $web['name'] ?? '';
        $mail->AddAddress($row['email']);
        $mail->Subject = $row['subject'];
        $mail->Body = build_email_html($row['subject'], $row['body']);
        $mail->WordWrap = 80;
        $mail->isHTML(true);
        if ($mail->Send()) {
            \think\Db::name('email_queue')->where('id', $queueId)->update([
                'status' => 1,
                'attempts' => $row['attempts'] + 1,
                'last_error' => '',
            ]);
        } else {
            $err = $mail->ErrorInfo ?: '未知发送错误';
            \think\Db::name('email_queue')->where('id', $queueId)->update([
                'attempts' => $row['attempts'] + 1,
                'last_error' => $err,
                'status' => ($row['attempts'] + 1 >= 5) ? 2 : 0,
            ]);
        }
    } catch (\Throwable $e) {
        try {
            \think\Db::name('email_queue')->where('id', $queueId)->update([
                'attempts' => 1,
                'last_error' => $e->getMessage(),
                'status' => 0,
            ]);
        } catch (\Throwable $ex) {}
    }
}
}

if (!function_exists('process_email_queue')) {
/**
 * 处理邮件队列（由 cron 调用），重试发送失败的邮件
 * @param int $limit 每次最多处理条数
 * @return int 成功发送的条数
 */
function process_email_queue($limit = 20) {
    ensure_email_queue_table();
    // 显式加载 PHPMailer（防止自动加载失效）
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        $pmDir = rtrim(PATH, '/\\') . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'PHPMailer' . DIRECTORY_SEPARATOR . 'PHPMailer' . DIRECTORY_SEPARATOR;
        if (is_file($pmDir . 'Exception.php')) require_once $pmDir . 'Exception.php';
        if (is_file($pmDir . 'PHPMailer.php')) require_once $pmDir . 'PHPMailer.php';
        if (is_file($pmDir . 'SMTP.php')) require_once $pmDir . 'SMTP.php';
    }
    $sent = 0;
    try {
        $rows = \think\Db::name('email_queue')
            ->where('status', 0)
            ->where('attempts', '<', 5)
            ->order('id asc')
            ->limit(intval($limit))
            ->select();
        if (empty($rows)) return 0;
        $web = web_config();
        foreach ($rows as $row) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer();
                $mail->IsSMTP();
                $mail->CharSet = $web['emailchar'] ?? 'UTF-8';
                $mail->SMTPAuth = $web['emailauth'] ?? true;
                $mail->Timeout = 15;
                $mail->SMTPDebug = 0;
                if (!empty($web['emailsecure'])) $mail->SMTPSecure = $web['emailsecure'];
                $mail->Port = intval($web['emailport'] ?? 25);
                $mail->Host = $web['emailhost'] ?? '';
                $mail->Username = $web['emailname'] ?? '';
                $mail->Password = $web['emailpass'] ?? '';
                $mail->From = $web['emailname'] ?? '';
                $mail->FromName = $web['name'] ?? '';
                $mail->AddAddress($row['email']);
                $mail->Subject = $row['subject'];
                $mail->Body = build_email_html($row['subject'], $row['body']);
                $mail->WordWrap = 80;
                $mail->isHTML(true);
                if ($mail->Send()) {
                    \think\Db::name('email_queue')->where('id', $row['id'])->update(['status' => 1, 'last_error' => '']);
                    $sent++;
                } else {
                    \think\Db::name('email_queue')->where('id', $row['id'])->update([
                        'attempts' => $row['attempts'] + 1,
                        'last_error' => $mail->ErrorInfo ?: '未知错误',
                        'status' => ($row['attempts'] + 1 >= 5) ? 2 : 0,
                    ]);
                }
            } catch (\Throwable $e) {
                \think\Db::name('email_queue')->where('id', $row['id'])->update([
                    'attempts' => $row['attempts'] + 1,
                    'last_error' => $e->getMessage(),
                    'status' => ($row['attempts'] + 1 >= 5) ? 2 : 0,
                ]);
            }
        }
    } catch (\Throwable $e) {}
    return $sent;
}
}

if (!function_exists('fetch_user_emails')) {
/**
 * 取用户邮箱列表（仅返回格式合法的邮箱）
 * @param string $where 额外 SQL 条件（不含 WHERE，字段已加反引号）
 * @return array 邮箱数组
 */
function fetch_user_emails($where = '', $limit = 0) {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $prefix = $prefix ?: '';
        $sql = "SELECT `mail` FROM `{$prefix}user` WHERE `mail` <> ''";
        if ($where !== '') $sql .= ' AND ' . $where;
        $sql .= ' ORDER BY `id` ASC';
        if ($limit > 0) $sql .= ' LIMIT ' . intval($limit);
        $rows = \think\Db::query($sql);
        $out = [];
        foreach ((array) $rows as $r) {
            $m = trim((string) ($r['mail'] ?? ''));
            if ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL) && !in_array($m, $out, true)) {
                $out[] = $m;
            }
        }
        return $out;
    } catch (\Throwable $e) {
        return [];
    }
}
}

if (!function_exists('email_enqueue_bulk')) {
/**
 * 批量入队邮件（只写队列，不立即发送）。
 * 群发必须走这里：逐条同步发送会让 PHP 超时，交由 /cron 分批投递。
 * @param array  $emails 收件人邮箱数组
 * @param string $subject 标题
 * @param string $body HTML 正文片段
 * @return int 入队条数
 */
function email_enqueue_bulk($emails, $subject, $body) {
    try {
        ensure_email_queue_table();
        $emails = array_values(array_unique(array_filter((array) $emails)));
        if (empty($emails)) return 0;
        $now = time();
        $count = 0;
        foreach (array_chunk($emails, 200) as $chunk) {
            $rows = [];
            foreach ($chunk as $m) {
                $rows[] = [
                    'email' => $m,
                    'subject' => $subject,
                    'body' => $body,
                    'attempts' => 0,
                    'status' => 0,
                    'last_error' => '',
                    'create_time' => $now,
                ];
            }
            \think\Db::name('email_queue')->insertAll($rows);
            $count += count($rows);
        }
        return $count;
    } catch (\Throwable $e) {
        return 0;
    }
}
}

if (!function_exists('send_announcement_mail')) {
/**
 * 把某条公告群发给全体用户（写入邮件队列，由 cron 投递）
 * @param int $id 公告ID
 * @return int 入队条数；失败返回 -1
 */
function send_announcement_mail($id) {
    try {
        ensure_announcements_table();
        $row = \think\Db::name('announcements')->where('id', intval($id))->find();
        if (!$row) return -1;
        $web = web_config();
        $siteName = $web['name'] ?? '';
        $subject = trim((string) $row['title']);
        if ($subject === '') $subject = $siteName . ' 公告';
        $content = (string) $row['content'];
        // 公告正文本身就是 HTML（后台富文本编辑），直接作为 HTML 邮件正文
        $body = '<div style="font-size:15px;line-height:1.8;color:#334155;">' . $content . '</div>';
        $emails = fetch_user_emails();
        $n = email_enqueue_bulk($emails, $subject, $body);
        // 小批量（≤5 封）立即同步投递，避免用户以为"没发送"；其余交给 /cron 分批投递
        if ($n > 0 && $n <= 5 && function_exists('process_email_queue')) {
            try { process_email_queue(5); } catch (\Throwable $e) {}
        }
        \think\Db::name('announcements')->where('id', intval($id))->update([
            'email_sent' => 1,
            'updated_at' => time(),
        ]);
        return $n;
    } catch (\Throwable $e) {
        return -1;
    }
}
}

if (!function_exists('ensure_email_tasks_table')) {
/**
 * 邮件推送任务表（支持 HTML 正文、定向发送、定时发送、手动发送）
 */
function ensure_email_tasks_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $p = $prefix ?: '';
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}email_tasks` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL DEFAULT '' COMMENT '邮件标题',
            `content` text COMMENT '邮件正文（支持 HTML）',
            `target_type` varchar(20) NOT NULL DEFAULT 'all' COMMENT 'all=全部用户 level=按会员等级 users=指定用户ID emails=指定邮箱',
            `target_value` varchar(1000) NOT NULL DEFAULT '' COMMENT '目标参数（等级ID/用户ID列表/邮箱列表）',
            `schedule_time` int(11) NOT NULL DEFAULT '0' COMMENT '定时发送时间戳，0=立即发送',
            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待发送 1已投递 2已取消 3失败',
            `total_count` int(11) NOT NULL DEFAULT '0' COMMENT '收件人总数',
            `sent_count` int(11) NOT NULL DEFAULT '0' COMMENT '已入队条数',
            `last_error` varchar(1000) NOT NULL DEFAULT '',
            `admin_id` int(11) NOT NULL DEFAULT '0',
            `created_at` int(11) NOT NULL DEFAULT '0',
            `finish_time` int(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`),
            KEY `idx_schedule` (`schedule_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
}

if (!function_exists('email_task_recipients')) {
/**
 * 展开某个邮件任务的收件人邮箱列表
 * @param array $task email_tasks 记录
 * @return array 邮箱数组
 */
function email_task_recipients($task) {
    $type = isset($task['target_type']) ? $task['target_type'] : 'all';
    $value = isset($task['target_value']) ? trim((string) $task['target_value']) : '';
    if ($type === 'level') {
        $levels = array_filter(array_map('intval', preg_split('/[^\d]+/', $value)));
        if (empty($levels)) return [];
        return fetch_user_emails('`membership_level` IN (' . implode(',', $levels) . ')');
    }
    if ($type === 'users') {
        $ids = array_filter(array_map('intval', preg_split('/[^\d]+/', $value)));
        if (empty($ids)) return [];
        return fetch_user_emails('`id` IN (' . implode(',', $ids) . ')');
    }
    if ($type === 'emails') {
        $parts = preg_split('/[\s,;，；]+/u', $value);
        $out = [];
        foreach ((array) $parts as $m) {
            $m = trim($m);
            if ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL)) $out[] = $m;
        }
        return array_values(array_unique($out));
    }
    // all：全体有邮箱的用户
    return fetch_user_emails();
}
}

if (!function_exists('run_email_task')) {
/**
 * 执行（投递）一个邮件推送任务：展开收件人 → 写入邮件队列 → 更新任务状态
 * @param int $taskId
 * @return int 入队条数；-1 失败
 */
function run_email_task($taskId) {
    try {
        ensure_email_tasks_table();
        $task = \think\Db::name('email_tasks')->where('id', intval($taskId))->find();
        if (!$task) return -1;
        if (intval($task['status']) === 1) return -2; // 已投递过
        $emails = email_task_recipients($task);
        $web = web_config();
        $subject = trim((string) $task['title']);
        if ($subject === '') $subject = ($web['name'] ?? '') . ' 通知';
        $content = (string) $task['content'];
        $n = email_enqueue_bulk($emails, $subject, $content);
        \think\Db::name('email_tasks')->where('id', intval($taskId))->update([
            'status' => ($n > 0 ? 1 : 3),
            'total_count' => count($emails),
            'sent_count' => $n,
            'last_error' => ($n > 0 ? '' : '没有匹配到任何收件人邮箱'),
            'finish_time' => time(),
        ]);
        // 少量邮件立即同步投递，其余交给 /cron
        if ($n > 0 && $n <= 5 && function_exists('process_email_queue')) {
            try { process_email_queue(5); } catch (\Throwable $e) {}
        }
        return $n;
    } catch (\Throwable $e) {
        try {
            \think\Db::name('email_tasks')->where('id', intval($taskId))->update([
                'status' => 3,
                'last_error' => $e->getMessage(),
            ]);
        } catch (\Throwable $ex) {}
        return -1;
    }
}
}

if (!function_exists('process_email_tasks')) {
/**
 * 处理到期的定时邮件任务（由 /cron 调用）
 * @return int 本次执行的任务数
 */
function process_email_tasks($limit = 3) {
    try {
        ensure_email_tasks_table();
        $rows = \think\Db::name('email_tasks')
            ->where('status', 0)
            ->where('schedule_time', '<=', time())
            ->order('id asc')
            ->limit(intval($limit))
            ->select();
        $done = 0;
        foreach ((array) $rows as $t) {
            run_email_task($t['id']);
            $done++;
        }
        return $done;
    } catch (\Throwable $e) {
        return 0;
    }
}
}

if (!function_exists('import_default_quiz_questions')) {
/**
 * 导入默认红色/历史/现代知识题库
 * @return int 导入条数
 */
function import_default_quiz_questions() {
    // 注意：不再调用 ensure_activity_tables()，避免 ensure 内部自动导入时产生递归；
    // 调用方需保证 quiz_question 表已存在（ensure_activity_tables 或 AdminActivity::_initialize 已建表）。
    try {
        $questions = [
        // 红色历史类
        ['category' => 'history', 'question' => '中国共产党成立于哪一年？', 'options' => json_encode(['1921年', '1927年', '1931年', '1949年']), 'answer' => 0, 'analysis' => '中国共产党于1921年7月在上海成立。'],
        ['category' => 'history', 'question' => '中华人民共和国成立于哪一年？', 'options' => json_encode(['1945年', '1949年', '1950年', '1954年']), 'answer' => 1, 'analysis' => '1949年10月1日中华人民共和国成立。'],
        ['category' => 'history', 'question' => '长征开始于哪一年？', 'options' => json_encode(['1931年', '1934年', '1937年', '1945年']), 'answer' => 1, 'analysis' => '红军长征始于1934年10月。'],
        ['category' => 'history', 'question' => '遵义会议召开于哪一年？', 'options' => json_encode(['1934年', '1935年', '1936年', '1937年']), 'answer' => 1, 'analysis' => '遵义会议于1935年1月召开，是党的历史上生死攸关的转折点。'],
        ['category' => 'history', 'question' => '抗日战争全面爆发是在哪一年？', 'options' => json_encode(['1931年', '1937年', '1941年', '1945年']), 'answer' => 1, 'analysis' => '1937年七七事变（卢沟桥事变）标志着全面抗战爆发。'],
        ['category' => 'history', 'question' => '新中国成立时的国旗是什么？', 'options' => json_encode(['五星红旗', '青天白日旗', '镰刀锤头旗', '五色旗']), 'answer' => 0, 'analysis' => '五星红旗是中华人民共和国国旗。'],
        ['category' => 'history', 'question' => '"两弹一星"精神中的"两弹"指什么？', 'options' => json_encode(['原子弹和氢弹', '导弹和原子弹', '氢弹和导弹', '原子弹和中子弹']), 'answer' => 0, 'analysis' => '"两弹"指核弹（原子弹、氢弹）和导弹。'],
        ['category' => 'history', 'question' => '改革开放始于哪一年？', 'options' => json_encode(['1976年', '1978年', '1980年', '1982年']), 'answer' => 1, 'analysis' => '1978年党的十一届三中全会开启了改革开放。'],
        ['category' => 'history', 'question' => '香港回归祖国是哪一年？', 'options' => json_encode(['1997年', '1998年', '1999年', '2000年']), 'answer' => 0, 'analysis' => '香港于1997年7月1日回归祖国。'],
        ['category' => 'history', 'question' => '澳门回归祖国是哪一年？', 'options' => json_encode(['1997年', '1998年', '1999年', '2000年']), 'answer' => 2, 'analysis' => '澳门于1999年12月20日回归祖国。'],
        ['category' => 'history', 'question' => '五四运动爆发于哪一年？', 'options' => json_encode(['1917年', '1918年', '1919年', '1921年']), 'answer' => 2, 'analysis' => '五四运动爆发于1919年。'],
        ['category' => 'history', 'question' => '抗日战争胜利纪念日是几月几日？', 'options' => json_encode(['8月15日', '9月3日', '9月18日', '7月7日']), 'answer' => 1, 'analysis' => '9月3日是中国人民抗日战争胜利纪念日。'],
        ['category' => 'history', 'question' => '新中国成立初期实行的土地改革废除了什么制度？', 'options' => json_encode(['封建土地所有制', '资本主义制度', '奴隶制度', '农奴制度']), 'answer' => 0, 'analysis' => '土地改革废除了封建土地所有制。'],
        ['category' => 'history', 'question' => '雷锋同志是哪个时代的楷模？', 'options' => json_encode(['革命战争年代', '社会主义建设时期', '改革开放时期', '新时代']), 'answer' => 1, 'analysis' => '雷锋是社会主义建设时期全心全意为人民服务的楷模。'],
        ['category' => 'history', 'question' => '抗美援朝战争开始于哪一年？', 'options' => json_encode(['1949年', '1950年', '1951年', '1952年']), 'answer' => 1, 'analysis' => '抗美援朝战争始于1950年10月。'],
        // 现代知识类
        ['category' => 'modern', 'question' => '中国第一颗人造卫星叫什么名字？', 'options' => json_encode(['东方红一号', '神舟一号', '嫦娥一号', '天宫一号']), 'answer' => 0, 'analysis' => '东方红一号于1970年发射，是中国第一颗人造卫星。'],
        ['category' => 'modern', 'question' => '中国首次载人航天飞行是神舟几号？', 'options' => json_encode(['神舟四号', '神舟五号', '神舟六号', '神舟七号']), 'answer' => 1, 'analysis' => '神舟五号2003年搭载杨利伟完成首次载人飞行。'],
        ['category' => 'modern', 'question' => '中国首艘航空母舰叫什么？', 'options' => json_encode(['山东舰', '辽宁舰', '福建舰', '海南舰']), 'answer' => 1, 'analysis' => '辽宁舰是中国首艘航母，2012年交付海军。'],
        ['category' => 'modern', 'question' => '中国探月工程的月球车叫什么名字？', 'options' => json_encode(['玉兔号', '天问号', '祝融号', '嫦娥号']), 'answer' => 0, 'analysis' => '玉兔号是中国首辆月球车。'],
        ['category' => 'modern', 'question' => '中国火星车叫什么名字？', 'options' => json_encode(['玉兔号', '天问号', '祝融号', '嫦娥号']), 'answer' => 2, 'analysis' => '祝融号是中国首辆火星车。'],
        ['category' => 'modern', 'question' => '"北斗"系统是什么？', 'options' => json_encode(['卫星导航系统', '通信卫星系统', '气象卫星系统', '侦察卫星系统']), 'answer' => 0, 'analysis' => '北斗是中国自主建设的全球卫星导航系统。'],
        ['category' => 'modern', 'question' => '中国空间站叫什么名字？', 'options' => json_encode(['天宫', '天和', '梦天', '问天']), 'answer' => 0, 'analysis' => '中国空间站名为"天宫"。'],
        ['category' => 'modern', 'question' => '青藏铁路全线通车是哪一年？', 'options' => json_encode(['2004年', '2006年', '2008年', '2010年']), 'answer' => 1, 'analysis' => '青藏铁路2006年7月1日全线通车。'],
        ['category' => 'modern', 'question' => '中国高铁最高运营时速达到多少？', 'options' => json_encode(['250公里', '300公里', '350公里', '400公里']), 'answer' => 2, 'analysis' => '中国高铁最高运营时速350公里。'],
        ['category' => 'modern', 'question' => '5G是指第几代移动通信技术？', 'options' => json_encode(['第三代', '第四代', '第五代', '第六代']), 'answer' => 2, 'analysis' => '5G是第五代移动通信技术。'],
        ['category' => 'modern', 'question' => '"一带一路"倡议是由谁提出的？', 'options' => json_encode(['习近平主席', '邓小平', '毛泽东', '周恩来']), 'answer' => 0, 'analysis' => '"一带一路"倡议由习近平主席提出。'],
        ['category' => 'modern', 'question' => '中国第一艘国产航母是？', 'options' => json_encode(['辽宁舰', '山东舰', '福建舰', '广东舰']), 'answer' => 1, 'analysis' => '山东舰是中国第一艘国产航母。'],
        ['category' => 'modern', 'question' => '天眼FAST是什么类型的设施？', 'options' => json_encode(['射电望远镜', '光学望远镜', '粒子加速器', '量子计算机']), 'answer' => 0, 'analysis' => 'FAST是500米口径球面射电望远镜。'],
        ['category' => 'modern', 'question' => '港珠澳大桥连接了哪三个地方？', 'options' => json_encode(['香港、珠海、澳门', '香港、深圳、澳门', '广州、珠海、澳门', '香港、中山、澳门']), 'answer' => 0, 'analysis' => '港珠澳大桥连接香港、珠海、澳门三地。'],
        ['category' => 'modern', 'question' => '中国自主研制的民用大飞机C919是什么类型？', 'options' => json_encode(['货机', '客机', '直升机', '战斗机']), 'answer' => 1, 'analysis' => 'C919是中国自主研制的大型喷气式客机。'],
        // 红色历史类（补充）
        ['category' => 'history', 'question' => '南昌起义发生在哪一年？', 'options' => json_encode(['1926年', '1927年', '1928年', '1931年']), 'answer' => 1, 'analysis' => '1927年8月1日南昌起义，打响了武装反抗国民党反动派的第一枪。'],
        ['category' => 'history', 'question' => '秋收起义的领导者是谁？', 'options' => json_encode(['周恩来', '毛泽东', '朱德', '彭德怀']), 'answer' => 1, 'analysis' => '1927年9月毛泽东领导了秋收起义。'],
        ['category' => 'history', 'question' => '井冈山革命根据地是谁创建的？', 'options' => json_encode(['毛泽东和朱德', '周恩来和叶挺', '彭德怀和贺龙', '刘伯承和邓小平']), 'answer' => 0, 'analysis' => '毛泽东和朱德在井冈山创建了第一个农村革命根据地。'],
        ['category' => 'history', 'question' => '红军长征途中翻越的第一座大雪山叫什么？', 'options' => json_encode(['夹金山', '六盘山', '岷山', '大雪山']), 'answer' => 0, 'analysis' => '夹金山是红军长征途中翻越的第一座大雪山。'],
        ['category' => 'history', 'question' => '西安事变发生在哪一年？', 'options' => json_encode(['1935年', '1936年', '1937年', '1938年']), 'answer' => 1, 'analysis' => '1936年12月12日张学良、杨虎城发动西安事变。'],
        ['category' => 'history', 'question' => '百团大战的指挥者是谁？', 'options' => json_encode(['朱德', '彭德怀', '林彪', '刘伯承']), 'answer' => 1, 'analysis' => '1940年彭德怀指挥百团大战，重创日军交通线。'],
        ['category' => 'history', 'question' => '辽沈战役发生在哪个地区？', 'options' => json_encode(['东北地区', '华北地区', '西北地区', '华东地区']), 'answer' => 0, 'analysis' => '辽沈战役是解放战争中东北战场的决定性战役。'],
        ['category' => 'history', 'question' => '淮海战役的总前委书记是谁？', 'options' => json_encode(['刘伯承', '邓小平', '陈毅', '粟裕']), 'answer' => 1, 'analysis' => '邓小平担任淮海战役总前委书记。'],
        ['category' => 'history', 'question' => '平津战役中和平解放的城市是？', 'options' => json_encode(['天津', '北平', '张家口', '唐山']), 'answer' => 1, 'analysis' => '北平（今北京）通过和平谈判实现和平解放。'],
        ['category' => 'history', 'question' => '《新民主主义论》是谁的著作？', 'options' => json_encode(['毛泽东', '周恩来', '刘少奇', '陈独秀']), 'answer' => 0, 'analysis' => '毛泽东于1940年发表《新民主主义论》。'],
        ['category' => 'history', 'question' => '渡江战役的口号是？', 'options' => json_encode(['打过长江去，解放全中国', '将革命进行到底', '打倒蒋介石，解放全中国', '宜将剩勇追穷寇']), 'answer' => 0, 'analysis' => '渡江战役口号是"打过长江去，解放全中国"。'],
        ['category' => 'history', 'question' => '中华人民共和国国歌原名是什么？', 'options' => json_encode(['义勇军进行曲', '黄河大合唱', '歌唱祖国', '我的祖国']), 'answer' => 0, 'analysis' => '国歌原名《义勇军进行曲》，由田汉作词、聂耳作曲。'],
        ['category' => 'history', 'question' => '开国大典上谁升起了第一面五星红旗？', 'options' => json_encode(['毛泽东', '朱德', '周恩来', '刘少奇']), 'answer' => 0, 'analysis' => '1949年10月1日毛泽东在天安门城楼按下电钮升起五星红旗。'],
        ['category' => 'history', 'question' => '第一个五年计划从哪年开始？', 'options' => json_encode(['1949年', '1950年', '1953年', '1956年']), 'answer' => 2, 'analysis' => '第一个五年计划从1953年开始，至1957年完成。'],
        ['category' => 'history', 'question' => '抗美援朝战争中上甘岭战役发生在哪一年？', 'options' => json_encode(['1950年', '1951年', '1952年', '1953年']), 'answer' => 2, 'analysis' => '上甘岭战役发生在1952年10月至11月。'],
        // 现代知识类（补充）
        ['category' => 'modern', 'question' => '中国天宫空间站由几个舱段组成？', 'options' => json_encode(['2个', '3个', '4个', '5个']), 'answer' => 1, 'analysis' => '天宫空间站由天和核心舱、问天实验舱、梦天实验舱三个舱段组成。'],
        ['category' => 'modern', 'question' => '神舟十四号航天员乘组有几位航天员？', 'options' => json_encode(['2位', '3位', '4位', '5位']), 'answer' => 1, 'analysis' => '神舟十四号乘组为陈冬、刘洋、蔡旭哲3位航天员。'],
        ['category' => 'modern', 'question' => '中国第一位进入太空的航天员是谁？', 'options' => json_encode(['杨利伟', '聂海胜', '景海鹏', '翟志刚']), 'answer' => 0, 'analysis' => '2003年杨利伟乘神舟五号成为第一位进入太空的中国人。'],
        ['category' => 'modern', 'question' => '中国第一位女航天员是谁？', 'options' => json_encode(['刘洋', '王亚平', '陈冬', '张晓光']), 'answer' => 0, 'analysis' => '刘洋是第一位进入太空的中国女航天员（神舟九号）。'],
        ['category' => 'modern', 'question' => '嫦娥四号实现了什么历史性突破？', 'options' => json_encode(['首次登月', '月球背面软着陆', '采样返回', '建立月球基地']), 'answer' => 1, 'analysis' => '嫦娥四号实现人类首次月球背面软着陆。'],
        ['category' => 'modern', 'question' => '中国量子科学实验卫星叫什么名字？', 'options' => json_encode(['墨子号', '张衡号', '祖冲之号', '悟空号']), 'answer' => 0, 'analysis' => '墨子号是世界首颗量子科学实验卫星。'],
        ['category' => 'modern', 'question' => '中国暗物质粒子探测卫星叫什么？', 'options' => json_encode(['墨子号', '悟空号', '慧眼号', '嫦娥号']), 'answer' => 1, 'analysis' => '悟空号是中国的暗物质粒子探测卫星。'],
        ['category' => 'modern', 'question' => '中国首条穿越沙漠的高速公路是？', 'options' => json_encode(['京新高速', '连霍高速', '包茂高速', '兰海高速']), 'answer' => 0, 'analysis' => '京新高速穿越戈壁沙漠，是世界最长的沙漠高速公路。'],
        ['category' => 'modern', 'question' => '港珠澳大桥全长约多少公里？', 'options' => json_encode(['35公里', '55公里', '75公里', '100公里']), 'answer' => 1, 'analysis' => '港珠澳大桥全长约55公里，是世界最长的跨海大桥。'],
        ['category' => 'modern', 'question' => '中国天眼FAST的口径是多少米？', 'options' => json_encode(['300米', '400米', '500米', '600米']), 'answer' => 2, 'analysis' => 'FAST口径500米，是世界最大单口径射电望远镜。'],
        ['category' => 'modern', 'question' => '中国第三艘航母的名字是？', 'options' => json_encode(['辽宁舰', '山东舰', '福建舰', '海南舰']), 'answer' => 2, 'analysis' => '福建舰是中国第三艘航母，也是首艘电磁弹射航母。'],
        ['category' => 'modern', 'question' => '北斗导航系统由多少颗卫星组成全球组网？', 'options' => json_encode(['24颗', '30颗', '35颗', '40颗']), 'answer' => 1, 'analysis' => '北斗三号全球系统由30颗卫星组成。'],
        ['category' => 'modern', 'question' => '中国研制的"奋斗者"号创造了多少米的深潜纪录？', 'options' => json_encode(['7000米', '8000米', '10000米', '10909米']), 'answer' => 3, 'analysis' => '奋斗者号在马里亚纳海沟创造了10909米的中国深潜纪录。'],
        ['category' => 'modern', 'question' => '中国亚运会首次在哪个城市举办？', 'options' => json_encode(['北京', '上海', '广州', '深圳']), 'answer' => 0, 'analysis' => '1990年北京首次举办亚运会。'],
        // 红色历史类（第二批补充）
        ['category' => 'history', 'question' => '中国共产党第一次全国代表大会在哪座城市开幕？', 'options' => json_encode(['上海', '北京', '广州', '武汉']), 'answer' => 0, 'analysis' => '1921年中共一大在上海开幕，后转移至嘉兴南湖闭幕。'],
        ['category' => 'history', 'question' => '遵义会议是哪一年召开的？', 'options' => json_encode(['1933年', '1934年', '1935年', '1936年']), 'answer' => 2, 'analysis' => '1935年1月遵义会议召开，确立了毛泽东的领导地位。'],
        ['category' => 'history', 'question' => '"七七事变"（卢沟桥事变）发生在哪一年？', 'options' => json_encode(['1935年', '1936年', '1937年', '1938年']), 'answer' => 2, 'analysis' => '1937年7月7日卢沟桥事变，全面抗战爆发。'],
        ['category' => 'history', 'question' => '平型关大捷是哪个部队取得的？', 'options' => json_encode(['八路军115师', '八路军120师', '八路军129师', '新四军']), 'answer' => 0, 'analysis' => '1937年9月八路军115师在平型关伏击日军，取得抗战以来首次大捷。'],
        ['category' => 'history', 'question' => '延安时期整风运动的核心内容是什么？', 'options' => json_encode(['反对主观主义、宗派主义、党八股', '反对官僚主义', '反对形式主义', '反对享乐主义']), 'answer' => 0, 'analysis' => '整风运动反对主观主义、宗派主义和党八股。'],
        ['category' => 'history', 'question' => '中共七大将什么思想确立为党的指导思想？', 'options' => json_encode(['毛泽东思想', '邓小平理论', '三个代表', '科学发展观']), 'answer' => 0, 'analysis' => '1945年中共七大确立毛泽东思想为党的指导思想。'],
        ['category' => 'history', 'question' => '三大改造完成标志着什么？', 'options' => json_encode(['社会主义制度建立', '改革开放开始', '新中国成立', '市场经济确立']), 'answer' => 0, 'analysis' => '1956年三大改造完成，标志着社会主义制度在中国基本建立。'],
        ['category' => 'history', 'question' => '党的十一届三中全会是哪一年召开的？', 'options' => json_encode(['1976年', '1977年', '1978年', '1979年']), 'answer' => 2, 'analysis' => '1978年12月十一届三中全会召开，开启改革开放新时期。'],
        ['category' => 'history', 'question' => '中华人民共和国成立的时间是？', 'options' => json_encode(['1949年10月1日', '1949年9月1日', '1950年10月1日', '1948年10月1日']), 'answer' => 0, 'analysis' => '1949年10月1日中华人民共和国成立。'],
        ['category' => 'history', 'question' => '焦裕禄在哪个县担任县委书记？', 'options' => json_encode(['兰考县', '兰陵县', '临县', '蓝田县']), 'answer' => 0, 'analysis' => '焦裕禄在河南兰考县任县委书记，带领群众治理风沙。'],
        ['category' => 'history', 'question' => '雷锋精神的本质是什么？', 'options' => json_encode(['全心全意为人民服务', '艰苦奋斗', '勤俭节约', '无私奉献']), 'answer' => 0, 'analysis' => '雷锋精神的本质是全心全意为人民服务。'],
        ['category' => 'history', 'question' => '抗美援朝战争始于哪一年？', 'options' => json_encode(['1949年', '1950年', '1951年', '1952年']), 'answer' => 1, 'analysis' => '1950年10月中国人民志愿军入朝作战，抗美援朝战争爆发。'],
        ['category' => 'history', 'question' => '井冈山会师是哪两支队伍会师？', 'options' => json_encode(['朱毛红军', '红一方面军与红四方面军', '红二方面军与红四方面军', '新四军与八路军']), 'answer' => 0, 'analysis' => '1928年4月朱德、毛泽东在井冈山会师。'],
        ['category' => 'history', 'question' => '长征的终点是哪里？', 'options' => json_encode(['延安', '吴起镇', '会宁', '遵义']), 'answer' => 2, 'analysis' => '1936年红军三大主力在甘肃会宁会师，长征胜利结束。'],
        ['category' => 'history', 'question' => '中共一大共有多少位代表参加？', 'options' => json_encode(['11位', '12位', '13位', '14位']), 'answer' => 2, 'analysis' => '中共一大共有13位代表参加。'],
        ['category' => 'history', 'question' => '中国共产党成立于哪一年？', 'options' => json_encode(['1919年', '1920年', '1921年', '1922年']), 'answer' => 2, 'analysis' => '中国共产党成立于1921年7月。'],
        ['category' => 'history', 'question' => '改革开放总设计师是谁？', 'options' => json_encode(['邓小平', '毛泽东', '周恩来', '刘少奇']), 'answer' => 0, 'analysis' => '邓小平被誉为中国改革开放的总设计师。'],
        ['category' => 'history', 'question' => '两弹一星中的"两弹"指什么？', 'options' => json_encode(['原子弹和氢弹', '导弹和原子弹', '氢弹和中子弹', '核弹和导弹']), 'answer' => 3, 'analysis' => '两弹指核弹（原子弹、氢弹）和导弹。'],
        ['category' => 'history', 'question' => '中国第一颗原子弹是哪一年爆炸成功的？', 'options' => json_encode(['1962年', '1964年', '1966年', '1967年']), 'answer' => 1, 'analysis' => '1964年10月16日中国第一颗原子弹爆炸成功。'],
        ['category' => 'history', 'question' => '中国第一颗人造卫星叫什么？', 'options' => json_encode(['东方红一号', '神舟一号', '嫦娥一号', '北斗一号']), 'answer' => 0, 'analysis' => '1970年东方红一号卫星发射成功。'],
        // 现代知识类（第二批补充）
        ['category' => 'modern', 'question' => '中国空间站首舱叫什么名字？', 'options' => json_encode(['天和核心舱', '问天实验舱', '梦天实验舱', '天舟货运舱']), 'answer' => 0, 'analysis' => '天和核心舱是中国空间站的首个舱段，2021年发射。'],
        ['category' => 'modern', 'question' => '神舟十三号航天员翟志刚、王亚平、叶光富在轨驻留多久？', 'options' => json_encode(['3个月', '6个月', '9个月', '1年']), 'answer' => 1, 'analysis' => '神舟十三号乘组在轨驻留约6个月，创当时纪录。'],
        ['category' => 'modern', 'question' => '嫦娥五号完成了什么任务？', 'options' => json_encode(['月球正面软着陆', '月球背面软着陆', '月球采样返回', '火星着陆']), 'answer' => 2, 'analysis' => '嫦娥五号实现月球采样返回，带回1731克月壤。'],
        ['category' => 'modern', 'question' => '中国火星探测任务叫什么名字？', 'options' => json_encode(['天问一号', '嫦娥五号', '神舟号', '长征号']), 'answer' => 0, 'analysis' => '天问一号是中国首个火星探测任务，成功着陆火星。'],
        ['category' => 'modern', 'question' => '中国长征系列运载火箭的研制机构是？', 'options' => json_encode(['中国航天科技集团', '中国科学院', '中国工程院', '国防科工局']), 'answer' => 0, 'analysis' => '长征系列火箭由中国航天科技集团研制。'],
        ['category' => 'modern', 'question' => '中国最大的咸水湖是？', 'options' => json_encode(['青海湖', '鄱阳湖', '洞庭湖', '太湖']), 'answer' => 0, 'analysis' => '青海湖是中国最大的咸水湖，也是最大的内陆湖。'],
        ['category' => 'modern', 'question' => '中国最长的河流是？', 'options' => json_encode(['长江', '黄河', '珠江', '黑龙江']), 'answer' => 0, 'analysis' => '长江全长约6300公里，是中国最长的河流。'],
        ['category' => 'modern', 'question' => '中国最高的山峰是？', 'options' => json_encode(['珠穆朗玛峰', '乔戈里峰', '贡嘎山', '梅里雪山']), 'answer' => 0, 'analysis' => '珠穆朗玛峰海拔8848.86米，是世界最高峰。'],
        ['category' => 'modern', 'question' => '中国四大发明不包括以下哪项？', 'options' => json_encode(['造纸术', '指南针', '火药', '瓷器']), 'answer' => 3, 'analysis' => '四大发明是造纸术、印刷术、指南针和火药，瓷器不在其中。'],
        ['category' => 'modern', 'question' => '中国首艘国产大型邮轮叫什么？', 'options' => json_encode(['爱达·魔都号', '招商伊敦号', '中华泰山号', '鼓浪屿号']), 'answer' => 0, 'analysis' => '爱达·魔都号是中国首艘国产大型邮轮。'],
        ['category' => 'modern', 'question' => '中国5G基站数量位居世界第几？', 'options' => json_encode(['第一', '第二', '第三', '第四']), 'answer' => 0, 'analysis' => '中国5G基站数量全球第一。'],
        ['category' => 'modern', 'question' => '中国新能源汽车产销量连续多年位居世界第几？', 'options' => json_encode(['第一', '第二', '第三', '第四']), 'answer' => 0, 'analysis' => '中国新能源汽车产销连续多年位居世界第一。'],
        ['category' => 'modern', 'question' => '中国天问一号火星车叫什么名字？', 'options' => json_encode(['祝融号', '玉兔号', '嫦娥号', '悟空号']), 'answer' => 0, 'analysis' => '祝融号是天问一号搭载的火星车。'],
        ['category' => 'modern', 'question' => '中国首次实现航天员出舱活动的是哪次任务？', 'options' => json_encode(['神舟五号', '神舟六号', '神舟七号', '神舟八号']), 'answer' => 2, 'analysis' => '神舟七号任务中翟志刚实现中国航天员首次出舱。'],
        ['category' => 'modern', 'question' => '中国自主研发的北斗卫星导航系统向全球提供服务始于哪一年？', 'options' => json_encode(['2018年', '2019年', '2020年', '2021年']), 'answer' => 2, 'analysis' => '2020年7月北斗三号全球卫星导航系统正式开通。'],
        ['category' => 'modern', 'question' => '中国高铁运营里程位居世界第几？', 'options' => json_encode(['第一', '第二', '第三', '第四']), 'answer' => 0, 'analysis' => '中国高铁运营里程居世界第一，超过4万公里。'],
        ['category' => 'modern', 'question' => '中国首个国家公园是？', 'options' => json_encode(['三江源国家公园', '武夷山国家公园', '海南热带雨林国家公园', '大熊猫国家公园']), 'answer' => 0, 'analysis' => '三江源国家公园是中国首个国家公园（试点）。'],
        ['category' => 'modern', 'question' => '中国GDP总量位居世界第几？', 'options' => json_encode(['第一', '第二', '第三', '第四']), 'answer' => 1, 'analysis' => '中国GDP总量位居世界第二，仅次于美国。'],
        ['category' => 'modern', 'question' => '中国农历新年又称什么？', 'options' => json_encode(['春节', '元宵节', '端午节', '中秋节']), 'answer' => 0, 'analysis' => '农历新年即春节，是中国最重要的传统节日。'],
        ['category' => 'modern', 'question' => '中国人口最多的省级行政区是？', 'options' => json_encode(['广东', '山东', '河南', '四川']), 'answer' => 0, 'analysis' => '广东省是中国常住人口最多的省份。'],
        ['category' => 'modern', 'question' => '中国面积最大的省级行政区是？', 'options' => json_encode(['新疆', '西藏', '内蒙古', '青海']), 'answer' => 0, 'analysis' => '新疆维吾尔自治区是中国面积最大的省级行政区。'],
    ];
    $count = 0;
    foreach ($questions as $q) {
        $exists = \think\Db::name('quiz_question')->where('question', $q['question'])->find();
        if (!$exists) {
            $q['create_time'] = time();
            $q['status'] = 1;
            \think\Db::name('quiz_question')->insert($q);
            $count++;
        }
    }
    return $count;
    } catch (\Throwable $e) {
        return 0;
    }
}
}

if (!function_exists('domain_api_verify')) {
/**
 * 域名 API 对接验证（阿里云万网 / 腾讯云 DNSPod）
 * @param string $provider aliyun/dnspod
 * @param string $domain 域名
 * @param array $apiConfig ['appid','appkey']
 * @return bool|string true=验证通过，字符串=错误信息
 */
function domain_api_verify($provider, $domain, $apiConfig) {
    try {
        if ($provider == 'aliyun') {
            // 阿里云域名 API（DescribeDomainList），需要 AccessKey
            // 简化：使用域名基本信息查询接口
            return domain_aliyun_verify($domain, $apiConfig);
        }
        if ($provider == 'dnspod') {
            // 腾讯云 DNSPod API
            return domain_dnspod_verify($domain, $apiConfig);
        }
        return '不支持的域名注册商';
    } catch (\Throwable $e) {
        return '域名验证异常：' . $e->getMessage();
    }
}
}

if (!function_exists('domain_aliyun_verify')) {
/**
 * 阿里云域名验证（DescribeDomainRecords / 域名查询）
 */
function domain_aliyun_verify($domain, $apiConfig) {
    if (empty($apiConfig['appid']) || empty($apiConfig['appkey'])) {
        return '阿里云 AccessKey 未配置';
    }
    // 阿里云域名 OpenAPI 需要复杂签名（RPC 签名），这里提供基础实现
    // 实际使用时需要完整的阿里云签名算法（HMAC-SHA1）
    // 简化：检查域名格式和 API 配置有效性
    $accessKeyId = $apiConfig['appid'];
    $accessKeySecret = $apiConfig['appkey'];

    // 构建阿里云域名查询请求（DescribeDomainRecords）
    $params = [
        'Action' => 'DescribeDomainRecords',
        'DomainName' => $domain,
        'Version' => '2018-01-29',
        'Format' => 'JSON',
        'AccessKeyId' => $accessKeyId,
        'SignatureMethod' => 'HMAC-SHA1',
        'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'SignatureVersion' => '1.0',
        'SignatureNonce' => uniqid(mt_rand(1, 999999999)),
    ];
    ksort($params);
    $canonicalized = '';
    foreach ($params as $k => $v) {
        $canonicalized .= '&' . percent_encode($k) . '=' . percent_encode($v);
    }
    $stringToSign = 'GET&%2F&' . percent_encode(substr($canonicalized, 1));
    $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
    $params['Signature'] = $signature;

    $url = 'https://alidns.aliyuncs.com/?' . http_build_query($params);
    $res = http_get($url, 5);
    if ($res === false) {
        return '阿里云 API 请求失败，请检查网络';
    }
    $json = json_decode($res, true);
    if (!empty($json['Code'])) {
        return '阿里云返回错误：' . $json['Code'] . ' ' . (isset($json['Message']) ? $json['Message'] : '');
    }
    return true;
}
}

if (!function_exists('domain_dnspod_verify')) {
/**
 * 腾讯云 DNSPod 域名验证
 */
function domain_dnspod_verify($domain, $apiConfig) {
    if (empty($apiConfig['appid']) || empty($apiConfig['appkey'])) {
        return 'DNSPod SecretId/SecretKey 未配置';
    }
    // DNSPod 使用腾讯云 API 签名（TC3-HMAC-SHA256）
    $secretId = $apiConfig['appid'];
    $secretKey = $apiConfig['appkey'];

    $service = 'dnspod';
    $host = 'dnspod.tencentcloudapi.com';
    $action = 'DescribeDomain';
    $version = '2021-03-23';
    $timestamp = time();
    $date = gmdate('Y-m-d', $timestamp);

    $payload = json_encode(['Domain' => $domain]);

    // TC3 签名
    $canonicalRequest = "POST\n/\n\ncontent-type:application/json; charset=utf-8\nhost:{$host}\n\ncontent-type;host\n" . hash('sha256', $payload);
    $credentialScope = "{$date}/{$service}/tc3_request";
    $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
    $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
    $secretService = hash_hmac('sha256', $service, $secretDate, true);
    $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
    $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
    $authorization = "TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, SignedHeaders=content-type;host, Signature={$signature}";

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://{$host}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Version: ' . $version,
            'X-TC-Timestamp: ' . $timestamp,
            'Authorization: ' . $authorization,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json; charset=utf-8\r\nHost: {$host}\r\nX-TC-Action: {$action}\r\nX-TC-Version: {$version}\r\nX-TC-Timestamp: {$timestamp}\r\nAuthorization: {$authorization}\r\n",
                'content' => $payload,
                'timeout' => 5,
            ],
        ]);
        $res = @file_get_contents("https://{$host}", false, $ctx);
    }
    if ($res === false) {
        return 'DNSPod API 请求失败，请检查网络';
    }
    $json = json_decode($res, true);
    if (!empty($json['Response']['Error'])) {
        return 'DNSPod 返回错误：' . $json['Response']['Error']['Code'] . ' ' . (isset($json['Response']['Error']['Message']) ? $json['Response']['Error']['Message'] : '');
    }
    return true;
}
}

if (!function_exists('percent_encode')) {
/**
 * 阿里云签名 URL 编码
 */
function percent_encode($str) {
    $res = urlencode($str);
    $res = str_replace('+', '%20', $res);
    $res = str_replace('*', '%2A', $res);
    $res = str_replace('%7E', '~', $res);
    return $res;
}
}

if (!function_exists('ensure_activity_tables')) {
/**
 * 确保活动中心相关表存在
 */
function ensure_activity_tables() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $p = $prefix ?: 'sale_';

        // 题目表
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}quiz_question` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `category` varchar(30) NOT NULL DEFAULT 'history' COMMENT '分类:history红色历史/modern现代知识/other',
            `question` text NOT NULL COMMENT '题目',
            `options` text NOT NULL COMMENT '选项JSON [A,B,C,D]',
            `answer` tinyint(1) NOT NULL DEFAULT '0' COMMENT '正确答案索引0-3',
            `analysis` text COMMENT '答案解析',
            `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1启用0禁用',
            `create_time` int(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_category` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 答题活动表
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}quiz_activity` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL DEFAULT '' COMMENT '活动名称',
            `type` varchar(20) NOT NULL DEFAULT 'points' COMMENT '奖品类型:points积分/host主机/balance余额',
            `question_count` int(11) NOT NULL DEFAULT '10' COMMENT '每次答题数',
            `pass_count` int(11) NOT NULL DEFAULT '8' COMMENT '通过所需答对数',
            `prize_points` int(11) NOT NULL DEFAULT '0' COMMENT '奖励积分',
            `prize_balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '奖励余额',
            `prize_cartid` int(11) NOT NULL DEFAULT '0' COMMENT '奖励主机产品ID',
            `prize_host_days` int(11) NOT NULL DEFAULT '30' COMMENT '奖励主机时长(天)',
            `daily_limit` int(11) NOT NULL DEFAULT '1' COMMENT '每人每日答题次数',
            `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1启用0禁用',
            `sort` int(11) NOT NULL DEFAULT '0',
            `create_time` int(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 答题记录表
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}quiz_record` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `activity_id` int(11) NOT NULL DEFAULT '0',
            `userid` int(11) NOT NULL DEFAULT '0',
            `score` int(11) NOT NULL DEFAULT '0' COMMENT '答对数',
            `total` int(11) NOT NULL DEFAULT '0' COMMENT '总题数',
            `passed` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否通过',
            `prize_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0未发放1已发放',
            `create_time` int(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_user` (`userid`),
            KEY `idx_activity` (`activity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 兼容已有表：补 total 字段（正确率排行榜用）
        try {
            $cols = \think\Db::query("SHOW COLUMNS FROM `{$p}quiz_record`");
            $hasTotal = false;
            foreach ($cols as $c) { if (isset($c['Field']) && $c['Field'] == 'total') { $hasTotal = true; break; } }
            if (!$hasTotal) {
                \think\Db::execute("ALTER TABLE `{$p}quiz_record` ADD COLUMN `total` int(11) NOT NULL DEFAULT '0' COMMENT '总题数' AFTER `score`");
            }
        } catch (\Throwable $e) {}

        // 抽奖奖品表
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}lottery_prize` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL DEFAULT '' COMMENT '奖品名称',
            `type` varchar(20) NOT NULL DEFAULT 'points' COMMENT '奖品类型:points积分/balance余额/host主机/none谢谢参与',
            `prize_points` int(11) NOT NULL DEFAULT '0',
            `prize_balance` decimal(10,2) NOT NULL DEFAULT '0.00',
            `prize_cartid` int(11) NOT NULL DEFAULT '0' COMMENT '主机产品ID',
            `prize_host_days` int(11) NOT NULL DEFAULT '30',
            `probability` decimal(6,4) NOT NULL DEFAULT '0.0000' COMMENT '中奖概率0-1',
            `icon` varchar(500) NOT NULL DEFAULT '' COMMENT '奖品图标',
            `stock` int(11) NOT NULL DEFAULT '-1' COMMENT '库存,-1不限',
            `status` tinyint(1) NOT NULL DEFAULT '1',
            `sort` int(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 抽奖记录表
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}lottery_record` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `userid` int(11) NOT NULL DEFAULT '0',
            `prize_id` int(11) NOT NULL DEFAULT '0',
            `prize_name` varchar(100) NOT NULL DEFAULT '',
            `prize_type` varchar(20) NOT NULL DEFAULT 'none',
            `prize_value` varchar(200) NOT NULL DEFAULT '' COMMENT '奖励内容描述',
            `prize_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0未发放1已发放',
            `date` varchar(10) NOT NULL DEFAULT '' COMMENT '日期Y-m-d',
            `create_time` int(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_user` (`userid`),
            KEY `idx_date` (`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 抽奖配置表
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}lottery_config` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `daily_times` int(11) NOT NULL DEFAULT '1' COMMENT '每日抽奖次数',
            `need_realname` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否需要实名',
            `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否开启抽奖',
            `notice` varchar(500) NOT NULL DEFAULT '' COMMENT '活动说明',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 域名表
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}domain_product` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `userid` int(11) NOT NULL DEFAULT '0' COMMENT '卖家用户ID',
            `domain` varchar(255) NOT NULL DEFAULT '' COMMENT '域名',
            `price` decimal(12,2) NOT NULL DEFAULT '0.00',
            `registrar` varchar(50) NOT NULL DEFAULT '' COMMENT '注册商:aliyun/dnspod',
            `description` varchar(1000) NOT NULL DEFAULT '',
            `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1上架0下架2已售',
            `email_verified` tinyint(1) NOT NULL DEFAULT '0' COMMENT '邮箱已验证',
            `create_time` int(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_domain` (`domain`),
            KEY `idx_user` (`userid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 域名订单表
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}domain_order` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `domain_id` int(11) NOT NULL DEFAULT '0',
            `buyer_id` int(11) NOT NULL DEFAULT '0',
            `seller_id` int(11) NOT NULL DEFAULT '0',
            `domain` varchar(255) NOT NULL DEFAULT '',
            `price` decimal(12,2) NOT NULL DEFAULT '0.00',
            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待处理1已完成2取消',
            `create_time` int(11) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_domain` (`domain_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 域名API配置表
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}domain_api_config` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `provider` varchar(20) NOT NULL DEFAULT 'aliyun' COMMENT 'aliyun/dnspod',
            `appid` varchar(200) NOT NULL DEFAULT '',
            `appkey` varchar(200) NOT NULL DEFAULT '',
            `status` tinyint(1) NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 在线人数统计表
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}online_stats` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `date` varchar(10) NOT NULL DEFAULT '',
            `hour` int(2) NOT NULL DEFAULT '0',
            `count` int(11) NOT NULL DEFAULT '0' COMMENT '在线人数',
            PRIMARY KEY (`id`),
            KEY `idx_date` (`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 自动导入默认题库：题库为空时自动生成，无需管理员手动点击「导入默认题库」
        static $autoImported = false;
        if (!$autoImported) {
            $autoImported = true;
            try {
                $questionCount = \think\Db::name('quiz_question')->count();
                if ($questionCount == 0) {
                    import_default_quiz_questions();
                }
            } catch (\Throwable $e) {}
        }

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
}

if (!function_exists('ensure_realname_idcard_table')) {
/**
 * 确保身份证上传实名表存在
 */
function ensure_realname_idcard_table() {
    try {
        $prefix = \think\Db::getConfig('prefix');
        $p = $prefix ?: 'sale_';
        \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$p}realname_idcard` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `userid` int(11) NOT NULL DEFAULT '0',
            `realname` varchar(50) NOT NULL DEFAULT '',
            `idcard` varchar(20) NOT NULL DEFAULT '',
            `mobile` varchar(20) NOT NULL DEFAULT '',
            `idcard_front` varchar(500) NOT NULL DEFAULT '' COMMENT '身份证正面图片',
            `idcard_back` varchar(500) NOT NULL DEFAULT '' COMMENT '身份证背面图片',
            `method` varchar(20) NOT NULL DEFAULT 'idcard' COMMENT '认证方式:idcard身份证上传/phone手机三要素/manual人工',
            `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0待审核1通过2驳回',
            `create_time` int(11) NOT NULL DEFAULT '0',
            `review_time` int(11) NOT NULL DEFAULT '0',
            `review_remark` varchar(500) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `idx_user` (`userid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // 确保表编码为 utf8mb4（兼容 emoji 等4字节字符）
        \think\Db::execute("ALTER TABLE `{$p}realname_idcard` DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
}

if (!function_exists('shumai_character_ocr')) {
/**
 * 数脉API 通用文字识别 OCR（腾讯云市场商品 40588）
 * 通过腾讯云市场 API 网关调用，认证方式与 cloudmarket_realname_verify 一致（HMAC-SHA1 签 x-date）
 * @param string $imgBase64 身份证人像面图片 Base64
 * @param string $secretId   腾讯云市场 SecretId（AKID开头）
 * @param string $secretKey  腾讯云市场 SecretKey
 * @param string $apiUrl     接口地址（可后台覆盖，默认数脉通用文字识别）
 * @return array ['name'=>'','idcard'=>''] 或含 'error' 键
 */
function shumai_character_ocr($imgBase64, $secretId, $secretKey, $apiUrl = '', $imgPublicUrl = '') {
    $result = ['name' => '', 'idcard' => ''];
    if ((empty($imgBase64) && empty($imgPublicUrl)) || empty($secretId) || empty($secretKey)) {
        return ['name' => '', 'idcard' => '', 'error' => '数脉 OCR 密钥未配置'];
    }
    if (!function_exists('hash_hmac') || !function_exists('curl_init')) {
        return ['name' => '', 'idcard' => '', 'error' => '服务器未启用 hash/cURL 扩展'];
    }
    try {
        $url = $apiUrl !== '' ? $apiUrl : 'https://ap-beijing.cloudmarket-apigw.com/service-edn57zdz/v2/character/ocr';

        // 腾讯云市场网关签名（HMAC-SHA1 签 x-date，与云市场实名认证一致）
        $datetime = gmdate('D, d M Y H:i:s T');
        $signStr = sprintf('x-date: %s', $datetime);
        $sign = base64_encode(hash_hmac('sha1', $signStr, $secretKey, true));
        $auth = sprintf('{"id": "%s", "x-date": "%s", "signature": "%s"}', $secretId, $datetime, $sign);
        $requestId = strtolower(md5(uniqid(mt_rand(), true)));

        $headers = [
            'Authorization: ' . $auth,
            'request-id: ' . $requestId,
            'X-Requested-With: XMLHttpRequest',
            'Content-Type: application/x-www-form-urlencoded',
        ];

        // 数脉 40588 通用文字识别为 GET 请求，参数放 Query（image / url 二选一）
        // 优先使用图片公网 URL，避免 base64 图片过长导致 URL 超限
        $queryParams = [];
        if (!empty($imgPublicUrl)) {
            $queryParams['url'] = $imgPublicUrl;
        } else {
            $queryParams['image'] = $imgBase64;
        }
        $queryString = http_build_query($queryParams);
        $fullUrl = $url . (strpos($url, '?') === false ? '?' : '&') . $queryString;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $err) {
            return ['name' => '', 'idcard' => '', 'error' => '数脉 OCR 请求失败：' . ($err ?: '网络错误')];
        }
        $json = json_decode($res, true);
        if (!is_array($json)) {
            return ['name' => '', 'idcard' => '', 'error' => '数脉 OCR 返回数据异常（HTTP ' . $httpCode . '）：' . substr($res, 0, 300)];
        }
        // 业务错误
        if (isset($json['code']) && intval($json['code']) !== 200) {
            return ['name' => '', 'idcard' => '', 'error' => '数脉 OCR 返回错误：' . (isset($json['msg']) ? $json['msg'] : ('code=' . $json['code']))];
        }
        if (isset($json['success']) && !$json['success']) {
            return ['name' => '', 'idcard' => '', 'error' => '数脉 OCR 识别失败：' . (isset($json['msg']) ? $json['msg'] : '')];
        }
        // 网关层错误（响应里既没有业务 code 也没有 success，只带 message/requestid）：
        // 多为接口地址 / 密钥与该商品不匹配，例如 {"message":"在配置中使用计划不存在",...}（HTTP 421）
        if (!isset($json['code']) && !isset($json['success']) && (isset($json['message']) || isset($json['requestid']))) {
            $gwMsg = isset($json['message']) ? (string) $json['message'] : '';
            return [
                'name' => '',
                'idcard' => '',
                'error' => 'OCR 接口返回异常（HTTP ' . $httpCode . '）' . ($gwMsg !== '' ? '：' . $gwMsg : '：' . substr($res, 0, 200))
                    . '。请到腾讯云 → 云市场 → 我的订单，复制该 OCR 商品自己的接口地址与密钥，填到后台「系统设置 → 实名认证 → 身份证识别」对应项。',
            ];
        }
        // 提取文字块列表：data.result.results[] = { text, textRectangles }
        $results = [];
        if (isset($json['data']['result']['results']) && is_array($json['data']['result']['results'])) {
            $results = $json['data']['result']['results'];
        } elseif (isset($json['data']['result']) && is_array($json['data']['result'])) {
            $results = $json['data']['result'];
        }
        if (empty($results)) {
            return ['name' => '', 'idcard' => '', 'error' => '数脉 OCR 未识别到文字'];
        }
        return extract_name_idcard_from_ocr_texts($results);
    } catch (\Throwable $e) {
        return ['name' => '', 'idcard' => '', 'error' => '数脉 OCR 异常：' . $e->getMessage()];
    }
}
}

if (!function_exists('extract_name_idcard_from_ocr_texts')) {
/**
 * 从通用文字识别结果中提取身份证姓名 + 身份证号
 * 通用 OCR 返回文字块列表（含坐标），身份证人像面布局固定：姓名在左上角，身份证号是18位
 * @param array $results OCR 文字块数组，每项含 text / textRectangles(top,left,...)
 * @return array ['name'=>'','idcard'=>'']
 */
function extract_name_idcard_from_ocr_texts($results) {
    $name = '';
    $idcard = '';
    $blocks = [];
    foreach ($results as $r) {
        if (!is_array($r)) continue;
        $text = trim(isset($r['text']) ? (string)$r['text'] : '');
        if ($text === '') continue;
        $rect = isset($r['textRectangles']) && is_array($r['textRectangles']) ? $r['textRectangles'] : [];
        $blocks[] = [
            'text' => $text,
            'top' => isset($rect['top']) ? floatval($rect['top']) : 0,
            'left' => isset($rect['left']) ? floatval($rect['left']) : 0,
        ];
    }
    if (empty($blocks)) {
        return ['name' => '', 'idcard' => ''];
    }

    // 1. 提取身份证号（18位，末位可为X，格式唯一，最可靠）
    foreach ($blocks as $b) {
        if (preg_match('/\d{17}[\dXx]/', $b['text'], $m)) {
            $idcard = strtoupper($m[0]);
            break;
        }
    }

    // 2. 提取姓名
    $excludeLabels = ['姓名', '性别', '民族', '出生', '住址', '公民身份号码', '公民身份证号', '签发机关', '有效期限'];
    // 策略A：找到"姓名"标签块，取其同区域右侧的纯汉字块
    foreach ($blocks as $i => $b) {
        if (mb_strpos($b['text'], '姓名') !== false) {
            // 同块："姓名张三" / "姓名：张三"
            if (preg_match('/姓名\s*[:：]?\s*([\x{4e00}-\x{9fa5}·]{2,4})/u', $b['text'], $m)) {
                $candidate = $m[1];
                if (!in_array($candidate, $excludeLabels, true)) {
                    $name = $candidate;
                    break;
                }
            }
            // 相邻块：同一行(top相近)、更靠右(left更大)的纯2-4汉字
            foreach ($blocks as $j => $b2) {
                if ($j === $i) continue;
                if (abs($b2['top'] - $b['top']) < 40 && $b2['left'] > $b['left']) {
                    if (preg_match('/^[\x{4e00}-\x{9fa5}·]{2,4}$/u', $b2['text']) && !in_array($b2['text'], $excludeLabels, true)) {
                        $name = $b2['text'];
                        break 2;
                    }
                }
            }
        }
    }

    // 策略B：兜底——找顶部区域(top最小)的纯2-4汉字块（排除字段标签）
    if ($name === '') {
        $sorted = $blocks;
        usort($sorted, function($a, $b) { return ($a['top'] - $b['top']) <= 0 ? -1 : 1; });
        foreach ($sorted as $b) {
            if (preg_match('/^[\x{4e00}-\x{9fa5}·]{2,4}$/u', $b['text']) && !in_array($b['text'], $excludeLabels, true)) {
                $name = $b['text'];
                break;
            }
        }
    }

    return ['name' => $name, 'idcard' => $idcard];
}
}

if (!function_exists('tencent_idcard_ocr')) {
/**
 * 腾讯云身份证 OCR（官方 IDCardOCR 接口，TC3-HMAC-SHA256 签名）
 * @param string $imgBase64 身份证人像面图片 Base64
 * @param string $secretId   腾讯云 SecretId
 * @param string $secretKey  腾讯云 SecretKey
 * @return array ['name'=>'','idcard'=>''] 或含 'error' 键的错误信息
 */
function tencent_idcard_ocr($imgBase64, $secretId, $secretKey) {
    $result = ['name' => '', 'idcard' => ''];
    if (empty($imgBase64) || empty($secretId) || empty($secretKey)) {
        return $result;
    }
    if (!function_exists('hash_hmac') || !function_exists('curl_init')) {
        return ['name' => '', 'idcard' => '', 'error' => '服务器未启用 hash/cURL 扩展，无法调用腾讯云 OCR'];
    }
    try {
        $service = 'ocr';
        $host = 'ocr.tencentcloudapi.com';
        $action = 'IDCardOCR';
        $version = '2018-11-19';
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);
        $payload = json_encode(['ImageBase64' => $imgBase64, 'CardSide' => 'FRONT']);

        // TC3-HMAC-SHA256 签名
        $canonicalRequest = "POST\n/\n\ncontent-type:application/json; charset=utf-8\nhost:{$host}\n\ncontent-type;host\n" . hash('sha256', $payload);
        $credentialScope = "{$date}/{$service}/tc3_request";
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
        $authorization = "TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, SignedHeaders=content-type;host, Signature={$signature}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://{$host}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Version: ' . $version,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Region: ap-guangzhou',
            'Authorization: ' . $authorization,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        if ($res === false) {
            return ['name' => '', 'idcard' => '', 'error' => '腾讯云 OCR 请求失败，请检查网络'];
        }
        $json = json_decode($res, true);
        if (!is_array($json)) {
            return ['name' => '', 'idcard' => '', 'error' => '腾讯云 OCR 返回数据异常'];
        }
        if (!empty($json['Response']['Error'])) {
            $e = $json['Response']['Error'];
            return ['name' => '', 'idcard' => '', 'error' => '腾讯云 OCR 错误：' . $e['Code'] . ' ' . (isset($e['Message']) ? $e['Message'] : '')];
        }
        $resp = isset($json['Response']) ? $json['Response'] : $json;
        $result['name'] = isset($resp['Name']) ? trim((string)$resp['Name']) : '';
        $result['idcard'] = isset($resp['IdNum']) ? trim((string)$resp['IdNum']) : '';
        return $result;
    } catch (\Throwable $e) {
        return ['name' => '', 'idcard' => '', 'error' => '腾讯云 OCR 异常：' . $e->getMessage()];
    }
}
}

if (!function_exists('realname_extract_idcard')) {
/**
 * 身份证图片自动提取姓名+身份证号（二要素 OCR）
 * 支持腾讯云官方 OCR / 阿里云市场 OCR，未配置时返回空数组（由用户手动补充）
 * @param string $imgUrl 图片URL
 * @return array ['name'=>'','idcard'=>'']
 */
function realname_extract_idcard($imgUrl) {
    $result = ['name' => '', 'idcard' => ''];
    if (empty($imgUrl)) return $result;
    try {
        $web = web_config();
        $ocrProvider = isset($web['idcard_ocr_provider']) ? $web['idcard_ocr_provider'] : '0';
        // 未启用 OCR，返回空（前端会让用户手动填写）
        if (empty($ocrProvider) || $ocrProvider == '0') {
            return $result;
        }
        $imgPath = PATH . 'public' . $imgUrl;
        if (!file_exists($imgPath)) return $result;
        $imgBase64 = base64_encode(file_get_contents($imgPath));

        // 数脉API 通用文字识别（腾讯云市场商品）
        if ($ocrProvider == 'shumai') {
            $secretId = isset($web['idcard_ocr_secret_id']) ? trim($web['idcard_ocr_secret_id']) : '';
            $secretKey = isset($web['idcard_ocr_secret_key']) ? trim($web['idcard_ocr_secret_key']) : '';
            $apiUrl = isset($web['idcard_ocr_api_url']) ? trim($web['idcard_ocr_api_url']) : '';
            if (empty($secretId) || empty($secretKey)) {
                return ['name' => '', 'idcard' => '', 'error' => '数脉 OCR 密钥未配置'];
            }
            // 生成图片公网 URL，数脉接口优先用 url 参数（避免 base64 过长导致 URL 超限）
            $publicUrl = '';
            try {
                $domain = \think\Request::instance()->domain();
                $publicUrl = $domain . $imgUrl;
            } catch (\Exception $e) {
                $publicUrl = '';
            }
            $r = shumai_character_ocr($imgBase64, $secretId, $secretKey, $apiUrl, $publicUrl);
            if (isset($r['error'])) {
                $logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
                if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                @file_put_contents($logDir . 'ocr_error.log', date('Y-m-d H:i:s') . " shumai ocr: " . $r['error'] . "\n", FILE_APPEND);
                return ['name' => '', 'idcard' => ''];
            }
            return ['name' => $r['name'], 'idcard' => $r['idcard']];
        }

        // 腾讯云官方 OCR
        if ($ocrProvider == 'tencent') {
            $secretId = isset($web['idcard_ocr_secret_id']) ? trim($web['idcard_ocr_secret_id']) : '';
            $secretKey = isset($web['idcard_ocr_secret_key']) ? trim($web['idcard_ocr_secret_key']) : '';
            if (empty($secretId) || empty($secretKey)) {
                return ['name' => '', 'idcard' => '', 'error' => '腾讯云 OCR 密钥未配置'];
            }
            $r = tencent_idcard_ocr($imgBase64, $secretId, $secretKey);
            if (isset($r['error'])) {
                // 识别失败时记录日志但不阻断，前端会让用户手动填写
                $logDir = defined('LOG_PATH') ? LOG_PATH : (PATH . '/runtime/log/');
                if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                @file_put_contents($logDir . 'ocr_error.log', date('Y-m-d H:i:s') . " tencent ocr: " . $r['error'] . "\n", FILE_APPEND);
                return ['name' => '', 'idcard' => ''];
            }
            return ['name' => $r['name'], 'idcard' => $r['idcard']];
        }

        // 阿里云市场 OCR（身份证识别）
        if ($ocrProvider == 'aliyun') {
            $appcode = isset($web['idcard_ocr_appcode']) ? trim($web['idcard_ocr_appcode']) : '';
            if (empty($appcode)) {
                return ['name' => '', 'idcard' => '', 'error' => '阿里云 OCR AppCode 未配置'];
            }
            $res = false;
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://ocridcard.market.alicloudapi.com/idcard');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['image' => $imgBase64, 'side' => 'face']));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: APPCODE ' . $appcode]);
                $res = curl_exec($ch);
                curl_close($ch);
            }
            if ($res) {
                $json = json_decode($res, true);
                $data = $json;
                // 兼容不同阿里云市场商品的返回结构（有的把结果包在 data 里）
                if (isset($json['data']) && is_array($json['data'])) {
                    $data = $json['data'];
                }
                if (!empty($data['name'])) $result['name'] = trim((string)$data['name']);
                if (!empty($data['num'])) $result['idcard'] = trim((string)$data['num']);
                if (!empty($data['idcard'])) $result['idcard'] = trim((string)$data['idcard']);
                if (!empty($data['idNumber'])) $result['idcard'] = trim((string)$data['idNumber']);
                if (!empty($data['idNo'])) $result['idcard'] = trim((string)$data['idNo']);
            }
            return $result;
        }
        return $result;
    } catch (\Throwable $e) {
        return $result;
    }
}
}

if (!function_exists('get_online_count')) {
/**
 * 获取官网在线实时人数（5分钟内有活动的访客）
 */
function get_online_count() {
    try {
        $table = \think\Db::name('visitor_log')->getTable();
        $cutoff = time() - 300;
        return \think\Db::name('visitor_log')
            ->where('visit_time', '>=', $cutoff)
            ->group('ip')
            ->count();
    } catch (\Throwable $e) {
        return 0;
    }
}
}

if (!function_exists('get_online_user_count')) {
/**
 * 获取在线用户数（5分钟内有活动的登录用户）
 */
function get_online_user_count() {
    try {
        $cutoff = time() - 300;
        return \think\Db::name('visitor_log')
            ->where('visit_time', '>=', $cutoff)
            ->where('userid', '>', 0)
            ->group('userid')
            ->count();
    } catch (\Throwable $e) {
        return 0;
    }
}
}

if (!function_exists('get_ip_geo_detail')) {
/**
 * 通过高德地图 IP 定位获取详细地理位置（省/市/经纬度）
 * @param string $ip IP地址
 * @return array ['province','city','lng','lat','region']
 */
function get_ip_geo_detail($ip = '') {
    if (empty($ip)) {
        $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '');
    }
    if (in_array($ip, ['127.0.0.1', 'localhost', '::1']) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return ['province' => '本地', 'city' => '', 'lng' => 0, 'lat' => 0, 'region' => '本地'];
    }
    try {
        $web = web_config();
        $key = isset($web['amap_key']) ? trim($web['amap_key']) : '';
        if (empty($key)) {
            // 无高德key时退回省份定位
            $region = get_ip_region($ip);
            return ['province' => $region, 'city' => '', 'lng' => 0, 'lat' => 0, 'region' => $region];
        }
        $res = http_get('https://restapi.amap.com/v3/ip?key=' . urlencode($key) . '&ip=' . urlencode($ip), 3);
        if ($res) {
            $json = json_decode($res, true);
            if (!empty($json['status']) && $json['status'] == '1') {
                $province = isset($json['province']) ? $json['province'] : '';
                $city = isset($json['city']) ? $json['city'] : '';
                // 直辖市处理
                if (in_array($province, ['北京市', '天津市', '上海市', '重庆市'])) {
                    $city = $province;
                }
                $lng = 0; $lat = 0;
                // 尝试获取经纬度
                if (!empty($json['rectangle'])) {
                    $rect = explode(';', $json['rectangle']);
                    if (count($rect) == 2) {
                        $p1 = explode(',', $rect[0]);
                        $p2 = explode(',', $rect[1]);
                        if (count($p1) == 2 && count($p2) == 2) {
                            $lng = (floatval($p1[0]) + floatval($p2[0])) / 2;
                            $lat = (floatval($p1[1]) + floatval($p2[1])) / 2;
                        }
                    }
                }
                $region = $province ?: get_ip_region($ip);
                return ['province' => $province, 'city' => $city, 'lng' => $lng, 'lat' => $lat, 'region' => $region];
            }
        }
        $region = get_ip_region($ip);
        return ['province' => $region, 'city' => '', 'lng' => 0, 'lat' => 0, 'region' => $region];
    } catch (\Throwable $e) {
        $region = get_ip_region($ip);
        return ['province' => $region, 'city' => '', 'lng' => 0, 'lat' => 0, 'region' => $region];
    }
}
}

if (!function_exists('ensure_user_geo_columns')) {
/**
 * 确保用户表包含地理位置详细字段
 */
function ensure_user_geo_columns() {
    try {
        $table = \think\Db::name('user')->getTable();
        $cols = \think\Db::query("SHOW COLUMNS FROM `{$table}`");
        $names = array_column($cols, 'Field');
        if (!in_array('last_login_city', $names)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `last_login_city` varchar(50) NOT NULL DEFAULT '' COMMENT '最后登录城市'");
        }
        if (!in_array('last_login_lng', $names)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `last_login_lng` decimal(10,6) NOT NULL DEFAULT '0.000000' COMMENT '最后登录经度'");
        }
        if (!in_array('last_login_lat', $names)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `last_login_lat` decimal(10,6) NOT NULL DEFAULT '0.000000' COMMENT '最后登录纬度'");
        }
        if (!in_array('last_login_country', $names)) {
            \think\Db::execute("ALTER TABLE `{$table}` ADD COLUMN `last_login_country` varchar(50) NOT NULL DEFAULT '' COMMENT '最后登录国家'");
        }
    } catch (\Throwable $e) {}
}
}

if (!function_exists('refresh_user_geo')) {
/**
 * 补全用户详细地理位置（省/市/经纬度）
 * @param int $limit 每次处理数量
 */
function refresh_user_geo($limit = 20) {
    ensure_user_geo_columns();
    try {
        $rows = \think\Db::name('user')
            ->where(function($q) {
                $q->where('last_login_city', '=', '')
                  ->whereOr('last_login_city', 'is', null);
            })
            ->where('last_login_ip', 'not in', ['', '0.0.0.0', '127.0.0.1', 'localhost', 'unknown'])
            ->field('id,last_login_ip')
            ->limit(intval($limit))
            ->select();
        foreach ($rows as $row) {
            $geo = get_ip_geo_detail($row['last_login_ip']);
            if (!empty($geo['city']) || !empty($geo['lng'])) {
                \think\Db::name('user')->where('id', $row['id'])->update([
                    'last_login_city' => $geo['city'],
                    'last_login_lng' => $geo['lng'],
                    'last_login_lat' => $geo['lat'],
                    'last_login_country' => '中国',
                    'last_login_region' => $geo['region'],
                ]);
            }
        }
        return count($rows);
    } catch (\Throwable $e) {
        return 0;
    }
}
}
