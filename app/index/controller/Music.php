<?php
namespace app\index\controller;

use think\Controller;
use think\Db;
use think\Request;

/**
 * 看板娘点歌 / 个人背景音乐
 * 音乐源：GD音乐台 https://music-api.gdstudio.xyz/api.php
 */
class Music extends Controller
{
    protected $userid = 0;

    public function _initialize() {
        header('Content-Type: application/json; charset=utf-8');
        $this->userid = intval(session('userid'));
    }

    protected function ok($data = [], $msg = 'success') {
        return json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    protected function fail($msg, $extra = []) {
        return json(array_merge(['code' => 1, 'msg' => $msg], $extra));
    }

    protected function needLogin() {
        if (!$this->userid) {
            return $this->fail('请先登录后再使用点歌功能哦~', ['need_login' => 1]);
        }
        return null;
    }

    /**
     * 搜索歌曲
     * GET /music/search?name=关键词&source=netease&count=20&pages=1
     */
    public function search() {
        $name   = trim((string) input('name', ''));
        $source = trim((string) input('source', 'netease'));
        $count  = intval(input('count', 20));
        $pages  = intval(input('pages', 1));
        if ($name === '') return $this->fail('请输入要搜索的歌名或歌手');
        if ($count < 1) $count = 20;
        if ($count > 50) $count = 50;
        if ($pages < 1) $pages = 1;
        if (!preg_match('/^[a-z_]+$/i', $source)) $source = 'netease';

        $data = music_api_request([
            'types'  => 'search',
            'source' => $source,
            'name'   => $name,
            'count'  => $count,
            'pages'  => $pages,
        ], 600);

        if ($data === null) return $this->fail('音乐接口暂时不可用，请稍后再试');

        $list = [];
        if (isset($data['data']) && is_array($data['data'])) {
            $raw = $data['data'];
        } elseif (is_array($data)) {
            $raw = $data;
        } else {
            $raw = [];
        }
        $i = 0;
        foreach ($raw as $item) {
            if (!is_array($item)) continue;
            $i++;
            $artist = isset($item['artist']) ? $item['artist'] : '';
            if (is_array($artist)) $artist = implode(' / ', array_filter(array_map('strval', $artist)));
            $list[] = [
                'no'       => $i,
                'id'       => isset($item['id']) ? (string)$item['id'] : '',
                'name'     => isset($item['name']) ? (string)$item['name'] : '',
                'artist'   => (string)$artist,
                'album'    => isset($item['album']) ? (string)$item['album'] : '',
                'pic_id'   => isset($item['pic_id']) ? (string)$item['pic_id'] : '',
                'lyric_id' => isset($item['lyric_id']) ? (string)$item['lyric_id'] : '',
                'source'   => isset($item['source']) ? (string)$item['source'] : $source,
            ];
        }
        if (empty($list)) return $this->fail('没有找到相关歌曲，换个关键词试试~');

        return $this->ok([
            'keyword' => $name,
            'source'  => $source,
            'page'    => $pages,
            'list'    => $list,
        ]);
    }

    /**
     * 获取播放地址
     * GET /music/url?id=xxx&source=netease&br=320
     */
    public function url() {
        $id     = trim((string) input('id', ''));
        $source = trim((string) input('source', 'netease'));
        $br     = trim((string) input('br', '320'));
        if ($id === '') return $this->fail('缺少歌曲ID');
        if (!preg_match('/^[a-z_]+$/i', $source)) $source = 'netease';
        if (!in_array($br, ['128', '192', '320', '740', '999'], true)) $br = '320';

        $data = music_api_request([
            'types'  => 'url',
            'source' => $source,
            'id'     => $id,
            'br'     => $br,
        ], 1800);

        if ($data === null) return $this->fail('获取播放地址失败');
        $url = isset($data['url']) ? (string)$data['url'] : '';
        if ($url === '') return $this->fail('这首歌曲暂无可用音源，换一首试试~');

        return $this->ok([
            'url' => $url,
            'br'  => isset($data['br']) ? $data['br'] : $br,
            'size'=> isset($data['size']) ? $data['size'] : 0,
        ]);
    }

    /**
     * 获取封面
     */
    public function pic() {
        $id     = trim((string) input('id', ''));
        $source = trim((string) input('source', 'netease'));
        $size   = intval(input('size', 300));
        if ($id === '') return $this->fail('缺少封面ID');
        if (!in_array($size, [300, 500], true)) $size = 300;
        $data = music_api_request([
            'types'  => 'pic',
            'source' => $source,
            'id'     => $id,
            'size'   => $size,
        ], 86400);
        if ($data === null || empty($data['url'])) return $this->fail('封面获取失败');
        return $this->ok(['url' => (string)$data['url']]);
    }

    /**
     * 获取歌词
     */
    public function lyric() {
        $id     = trim((string) input('id', ''));
        $source = trim((string) input('source', 'netease'));
        if ($id === '') return $this->fail('缺少歌词ID');
        $data = music_api_request([
            'types'  => 'lyric',
            'source' => $source,
            'id'     => $id,
        ], 86400);
        if ($data === null) return $this->fail('歌词获取失败');
        return $this->ok([
            'lyric'  => isset($data['lyric']) ? $data['lyric'] : '',
            'tlyric' => isset($data['tlyric']) ? $data['tlyric'] : '',
        ]);
    }

    /**
     * 读取个人音乐设置（含背景音乐与歌单）
     */
    public function setting() {
        $login = $this->needLogin();
        if ($login) return $login;
        $row = user_music_row($this->userid);
        if (!$row) return $this->fail('读取失败');
        $song = null;
        if (!empty($row['song_id'])) {
            $song = [
                'id'       => $row['song_id'],
                'source'   => $row['source'],
                'name'     => $row['name'],
                'artist'   => $row['artist'],
                'album'    => $row['album'],
                'pic_id'   => $row['pic_id'],
                'pic_url'  => $row['pic_url'],
                'lyric_id' => $row['lyric_id'],
                'br'       => $row['br'],
                'position' => floatval($row['position']),
                'duration' => floatval($row['duration']),
            ];
        }
        return $this->ok([
            'logged'     => 1,
            'enabled'    => intval($row['enabled']),
            'volume'     => floatval($row['volume']),
            'loop_mode'  => intval($row['loop_mode']),
            'song'       => $song,
            'playlist'   => user_music_playlist($row),
            'updated_at' => intval($row['update_time']),
        ]);
    }

    /**
     * 保存设置（背景音乐开关 / 当前歌曲 / 音量 / 循环模式）
     */
    public function save() {
        $login = $this->needLogin();
        if ($login) return $login;
        $row = user_music_row($this->userid);
        if (!$row) return $this->fail('读取失败');

        $data = ['update_time' => time()];

        if (input('?enabled')) {
            $data['enabled'] = intval(input('enabled')) ? 1 : 0;
        }
        if (input('?volume')) {
            $v = floatval(input('volume'));
            $data['volume'] = max(0, min(1, $v));
        }
        if (input('?loop_mode')) {
            $data['loop_mode'] = intval(input('loop_mode'));
            if (!in_array($data['loop_mode'], [0, 1, 2], true)) $data['loop_mode'] = 0;
        }

        // 保存歌曲信息（字段名用 song_* 前缀，避免与开关混淆）
        $sid = trim((string) input('song_id', ''));
        if ($sid !== '') {
            $data['song_id']  = mb_substr($sid, 0, 120);
            $data['source']   = mb_substr(trim((string) input('source', 'netease')), 0, 20);
            $data['name']     = mb_substr(trim((string) input('name', '')), 0, 255);
            $data['artist']   = mb_substr(trim((string) input('artist', '')), 0, 255);
            $data['album']    = mb_substr(trim((string) input('album', '')), 0, 255);
            $data['pic_id']   = mb_substr(trim((string) input('pic_id', '')), 0, 120);
            $data['pic_url']  = mb_substr(trim((string) input('pic_url', '')), 0, 500);
            $data['lyric_id'] = mb_substr(trim((string) input('lyric_id', '')), 0, 120);
            $br = trim((string) input('br', '320'));
            $data['br'] = in_array($br, ['128', '192', '320', '740', '999'], true) ? $br : '320';
            if (input('?duration')) $data['duration'] = floatval(input('duration'));
            if (input('?position')) $data['position'] = floatval(input('position'));
        }

        try {
            Db::name('user_music')->where('userid', $this->userid)->update($data);
        } catch (\Exception $e) {
            return $this->fail('保存失败：' . $e->getMessage());
        }
        return $this->ok(['saved' => 1], '已保存');
    }

    /**
     * 上报播放进度（用于刷新后续播）
     */
    public function position() {
        $login = $this->needLogin();
        if ($login) return $login;
        $pos = floatval(input('position', 0));
        if ($pos < 0) $pos = 0;
        try {
            $upd = ['position' => $pos, 'update_time' => time()];
            if (input('?duration')) $upd['duration'] = floatval(input('duration'));
            Db::name('user_music')->where('userid', $this->userid)->update($upd);
        } catch (\Exception $e) {
            return $this->fail('保存失败');
        }
        return $this->ok(['ok' => 1]);
    }

    /**
     * 我的歌单：读取 / 添加 / 删除
     * GET  → 列表
     * POST → act=add|remove|clear
     */
    public function playlist() {
        $login = $this->needLogin();
        if ($login) return $login;
        $row = user_music_row($this->userid);
        if (!$row) return $this->fail('读取失败');

        if (!Request::instance()->isPost()) {
            return $this->ok(['list' => user_music_playlist($row)]);
        }

        $act = trim((string) input('act', 'add'));
        $list = user_music_playlist($row);

        if ($act === 'clear') {
            $list = [];
        } elseif ($act === 'remove') {
            $id = trim((string) input('id', ''));
            $newList = [];
            foreach ($list as $it) {
                if (isset($it['id']) && (string)$it['id'] === $id) continue;
                $newList[] = $it;
            }
            $list = $newList;
        } else {
            $song = [
                'id'       => mb_substr(trim((string) input('id', '')), 0, 120),
                'source'   => mb_substr(trim((string) input('source', 'netease')), 0, 20),
                'name'     => mb_substr(trim((string) input('name', '')), 0, 255),
                'artist'   => mb_substr(trim((string) input('artist', '')), 0, 255),
                'album'    => mb_substr(trim((string) input('album', '')), 0, 255),
                'pic_id'   => mb_substr(trim((string) input('pic_id', '')), 0, 120),
                'pic_url'  => mb_substr(trim((string) input('pic_url', '')), 0, 500),
                'lyric_id' => mb_substr(trim((string) input('lyric_id', '')), 0, 120),
            ];
            if ($song['id'] === '' || $song['name'] === '') return $this->fail('歌曲信息不完整');
            // 去重
            foreach ($list as $it) {
                if (isset($it['id']) && (string)$it['id'] === $song['id']) {
                    return $this->ok(['list' => $list], '这首歌已经在歌单里啦');
                }
            }
            $list[] = $song;
            if (count($list) > 100) $list = array_slice($list, -100);
        }

        try {
            Db::name('user_music')->where('userid', $this->userid)->update([
                'playlist'    => json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'update_time' => time(),
            ]);
        } catch (\Exception $e) {
            return $this->fail('保存失败');
        }
        return $this->ok(['list' => array_values($list)], '已更新歌单');
    }
}
