<?php
/**
 * 看板娘点歌 / 背景音乐
 * ------------------------------------------------------------
 * 音乐数据来自 GD音乐台：https://music-api.gdstudio.xyz/api.php
 *  · 搜索  types=search&source=netease&name=关键词&count=20&pages=1
 *  · 播放  types=url&source=netease&id=歌曲ID&br=320
 *  · 封面  types=pic&source=netease&id=pic_id&size=300
 *  · 歌词  types=lyric&source=netease&id=lyric_id
 *  频率限制：5 分钟内不超过 50 次 → 服务端统一做文件缓存
 */

if (!function_exists('ensure_user_music_table')) {
    function ensure_user_music_table() {
        static $done = false;
        if ($done) return true;
        try {
            $prefix = \think\Db::getConfig('prefix');
            $prefix = is_string($prefix) ? $prefix : '';
            $table  = "{$prefix}user_music";
            \think\Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `userid` int(11) NOT NULL DEFAULT 0 COMMENT '用户ID',
                `enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否开启个人背景音乐',
                `song_id` varchar(120) NOT NULL DEFAULT '' COMMENT '歌曲ID',
                `source` varchar(20) NOT NULL DEFAULT 'netease' COMMENT '音乐源',
                `name` varchar(255) NOT NULL DEFAULT '' COMMENT '歌曲名',
                `artist` varchar(255) NOT NULL DEFAULT '' COMMENT '歌手',
                `album` varchar(255) NOT NULL DEFAULT '' COMMENT '专辑',
                `pic_id` varchar(120) NOT NULL DEFAULT '' COMMENT '封面ID',
                `pic_url` varchar(500) NOT NULL DEFAULT '' COMMENT '封面直链',
                `lyric_id` varchar(120) NOT NULL DEFAULT '' COMMENT '歌词ID',
                `br` varchar(10) NOT NULL DEFAULT '320' COMMENT '音质',
                `volume` decimal(4,2) NOT NULL DEFAULT 0.60 COMMENT '音量0-1',
                `position` decimal(10,2) NOT NULL DEFAULT 0 COMMENT '上次播放位置（秒）',
                `duration` decimal(10,2) NOT NULL DEFAULT 0 COMMENT '总时长（秒）',
                `loop_mode` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0列表循环 1单曲循环 2随机',
                `playlist` text COMMENT '我的歌单JSON',
                `update_time` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user` (`userid`),
                KEY `idx_update` (`update_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户背景音乐设置'");
            $done = true;
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('music_cache_dir')) {
    function music_cache_dir() {
        $dir = defined('LOG_PATH') ? dirname(LOG_PATH) . '/music_cache/' : (PATH . 'runtime/music_cache/');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }
}

if (!function_exists('music_api_request')) {
    /**
     * 请求 GD 音乐 API（带文件缓存）
     * @param array $params 例如 ['types'=>'search','source'=>'netease','name'=>'周杰伦']
     * @param int   $ttl    缓存秒数，0 表示不缓存
     * @return array|null
     */
    function music_api_request($params, $ttl = 600) {
        try {
            $query = http_build_query($params);
            $url   = 'https://music-api.gdstudio.xyz/api.php?' . $query;

            $file = null;
            if ($ttl > 0) {
                $dir = music_cache_dir();
                if ($dir) {
                    $file = $dir . md5($query) . '.json';
                    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
                        $cached = @json_decode((string)@file_get_contents($file), true);
                        if (is_array($cached)) return $cached;
                    }
                }
            }

            $body = '';
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
                    CURLOPT_REFERER        => 'https://music.gdstudio.xyz/',
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                $body = curl_exec($ch);
                curl_close($ch);
            } else {
                $ctx  = stream_context_create([
                    'http' => [
                        'timeout' => 10,
                        'header'  => "User-Agent: Mozilla/5.0\r\nReferer: https://music.gdstudio.xyz/\r\n",
                    ],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $body = @file_get_contents($url, false, $ctx);
            }
            if (!$body) return null;

            $data = @json_decode($body, true);
            if (!is_array($data)) return null;

            if ($file) @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }
}

if (!function_exists('user_music_row')) {
    /**
     * 读取用户音乐设置（不存在则创建空记录）
     */
    function user_music_row($userid) {
        $userid = intval($userid);
        if (!$userid) return null;
        try {
            ensure_user_music_table();
            $row = \think\Db::name('user_music')->where('userid', $userid)->find();
            if (!$row) {
                \think\Db::name('user_music')->insert([
                    'userid'      => $userid,
                    'enabled'     => 0,
                    'source'      => 'netease',
                    'br'          => '320',
                    'volume'      => 0.6,
                    'update_time' => time(),
                ]);
                $row = \think\Db::name('user_music')->where('userid', $userid)->find();
            }
            return $row ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }
}

if (!function_exists('user_music_playlist')) {
    /**
     * 解析用户歌单
     */
    function user_music_playlist($row) {
        if (!$row || empty($row['playlist'])) return [];
        $arr = @json_decode($row['playlist'], true);
        return is_array($arr) ? $arr : [];
    }
}
