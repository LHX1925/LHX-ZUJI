<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\Route;
//定义404
Route::miss('index/index/null');

return [
//安装向导（route_complete_match=true 下带尾斜杠需单独注册，否则会走 Route::miss 变成 404）
"/install"=>"install/index/index",
"/install/"=>"install/index/index",
"/install/index"=>"install/index/index",
"/install/index/"=>"install/index/index",
"/install/index/index"=>"install/index/index",
"/install/index/index/"=>"install/index/index",
"/install/index/step2"=>"install/index/step2",
"/install/index/step2/"=>"install/index/step2",
"/install/index/step3"=>"install/index/step3",
"/install/index/step3/"=>"install/index/step3",
"/install/index/step4"=>"install/index/step4",
"/install/index/step4/"=>"install/index/step4",
"/install/index/done"=>"install/index/done",
"/install/index/done/"=>"install/index/done",
"/install/license"=>"install/index/license",
"/install/license/"=>"install/index/license",
"/install/index/license"=>"install/index/license",
"/install/index/license/"=>"install/index/license",

//前台
"/"=>"index/index/index",
"/index"=>"index/index/index",
"/login"=>"index/index/login",
"/register"=>"index/index/register",
//扫码登录
"/qrlogin/create"=>"index/index/qrloginCreate",
"/qrlogin/status"=>"index/index/qrloginStatus",
"/qrlogin/confirm"=>"index/index/qrloginConfirm",
"/cart/[:id]"=>"index/index/cart",
"/user"=>"index/user/index",
"/user/index"=>"index/user/index",
"/user/password"=>"index/user/password",
"/user/information"=>"index/user/information",
"/user/theme"=>"index/user/theme",
"/user/settheme"=>"index/user/settheme",
"/user/logout"=>"index/user/logout",
"/product/[:id]"=>"index/index/product",
"/user/pay"=>"index/user/pay",
"/user/order/[:id]"=>"index/user/order",
"/user/return/:id"=>"index/user/return",
"/index/notify/:id"=>"index/index/notify",
"/announcement/[:id]"=>"index/index/announcement",
"/announcements"=>"index/index/announcement",
"/user/payrecord"=>"index/user/payrecord",
"/user/realname"=>"index/user/realname",
"/user/cdkey"=>"index/user/cdkey",
"/user/announcements"=>"index/user/announcements",
"/user/announcementRead"=>"index/user/announcementRead",
"/verify_email"=>"index/index/verifyEmail",
"/user/cart"=>"index/user/cart",
"/user/cartAdd"=>"index/user/cartAdd",
"/user/cartCheckout"=>"index/user/cartCheckout",
"/user/cartSettle"=>"index/user/cartSettle",
"/user/cartDel"=>"index/user/cartDel",
"/user/cartPay/:payid"=>"index/user/cartPay",
"/user/buyNow"=>"index/user/buyNow",
"/cron"=>"index/index/cron",
"/pwreset"=>"index/index/pwreset",
"/help"=>"index/index/help",
"/user/submitticket"=>"index/user/submitticket",
"/user/supportticket/[:id]"=>"index/user/supportticket",
"/user/mail"=>"index/user/mail",
"/user/transfer"=>"index/user/transfer",
"/user/transferMarket"=>"index/user/transferMarket",
"/user/transferHost"=>"index/user/transferHost",
"/user/transferBuy"=>"index/user/transferBuy",
"/user/transferContact"=>"index/user/transferContact",
"/user/transferCancel"=>"index/user/transferCancel",
"/user/transferDetail"=>"index/user/transferDetail",
"/user/transferSendCode"=>"index/user/transferSendCode",
"/user/transferSendMsg"=>"index/user/transferSendMsg",
"/user/transferGetMsgs"=>"index/user/transferGetMsgs",
"/user/transferUnreadCount"=>"index/user/transferUnreadCount",
"/user/qqGroupVerify"=>"index/user/qqGroupVerify",
"/user/transaction"=>"index/user/transaction",
"/user/aff"=>"index/user/aff",
"/user/transferrecord"=>"index/user/transferrecord",
"/aff/:upper"=>"index/index/aff",
"/sq"=>"index/sq/sq",


//后台
"/admin"=>"admin/index/index",
"/admin/login"=>"admin/login/index",
"/admin/index"=>"admin/index/index",
"/admin/info"=>"admin/index/info",
"/admin/password"=>"admin/index/password",
"/admin/logout"=>"admin/index/logout",
"/admin/set"=>"admin/index/set",
"/admin/user/[:id]/[:orderid]"=>"admin/index/user",
"/admin/ticket/[:id]"=>"admin/index/ticket",
"/admin/classification/[:id]"=>"admin/index/classification",
"/admin/server/[:id]"=>"admin/index/server",
"/admin/product/[:id]"=>"admin/index/product",
"/admin/announcement/[:id]"=>"admin/index/announcements",
"/admin/aff"=>"admin/index/aff",
"/admin/affsy"=>"admin/index/affsy",
"/admin/pay"=>"admin/index/pay",
"/admin/transferrecord"=>"admin/index/transferrecord",
"/admin/transferHostRecord"=>"admin/index/transferHostRecord",
"/admin/transaction"=>"admin/index/transaction",
"/admin/templateset"=>"admin/index/templateset",
"/admin/order/[:id]"=>"admin/index/order",
"/admin/realnameReview"=>"admin/index/realnameReview",
"/admin/test_realname_api"=>"admin/index/testRealnameApi",
"/admin/test_live2d_api"=>"admin/index/testLive2dApi",
"/admin/test_email"=>"admin/index/test_email",
"/admin/gt_test"=>"admin/index/gt_test",
"/admin/pays/[:id]"=>"admin/index/pays",
"/admin/sq/[:id]"=>"admin/index/sq",
"/admin/cdkey"=>"admin/index/cdkey",
"/admin/announcements/[:id]"=>"admin/index/announcements",
"/admin/mailPush/[:id]"=>"admin/index/mailPush",
"/admin/loginLog"=>"admin/index/loginLog",
"/admin/opLog"=>"admin/index/opLog",
"/admin/violation/[:id]"=>"admin/index/violation",
"/admin/emailAudit"=>"index/index/emailAudit",
"/admin/bg_upload"=>"admin/index/bg_upload",
"/admin/bg_multi_upload"=>"admin/index/bg_multi_upload",
"/admin/bg_reset"=>"admin/index/bg_reset",
"/admin/logo_upload"=>"admin/index/logo_upload",
"/admin/admin_manager"=>"admin/admin_manager/index",
"/admin/admin_manager/add"=>"admin/admin_manager/add",
"/admin/admin_manager/edit/[:id]"=>"admin/admin_manager/edit",
"/admin/admin_manager/delete"=>"admin/admin_manager/delete",
"/admin/admin_manager/roles"=>"admin/admin_manager/roles",
"/admin/admin_manager/add_role"=>"admin/admin_manager/addRole",
"/admin/admin_manager/edit_role/[:id]"=>"admin/admin_manager/editRole",
"/admin/admin_manager/delete_role"=>"admin/admin_manager/deleteRole",

// 后台主机管理
"/admin/admin_host"=>"admin/admin_host/index",
"/admin/admin_host/operate"=>"admin/admin_host/operate",
"/admin/admin_host/suspendAll"=>"admin/admin_host/suspendAll",
"/admin/admin_host/unsuspendAll"=>"admin/admin_host/unsuspendAll",
"/admin/admin_host/deleteAll"=>"admin/admin_host/deleteAll",
// Docker 容器开通管理
"/admin/docker"=>"admin/admin_host/docker",
"/admin/docker/operate"=>"admin/admin_host/dockerOperate",
"/admin/docker/create"=>"admin/admin_host/dockerCreate",

// 后台IP封禁管理
"/admin/ip_ban"=>"admin/ip_ban/index",
"/admin/ip_ban/unban"=>"admin/ip_ban/unban",
"/admin/ip_ban/ban"=>"admin/ip_ban/ban",

// 后台开发者API管理
"/admin/api"=>"admin/api/index",
"/admin/api/saveSettings"=>"admin/api/saveSettings",
"/admin/api/disableUser"=>"admin/api/disableUser",
"/admin/api/toggleKey"=>"admin/api/toggleKey",
"/admin/api/unban"=>"admin/api/unban",

// 后台地图数据接口
"/admin/chinamapjson"=>"admin/index/chinaMapJson",
"/admin/ajaxmapdata"=>"admin/index/ajaxMapData",
"/admin/ajaxOnlineCount"=>"admin/index/ajaxOnlineCount",

//积分签到和积分商城
"/user/checkin"=>"index/user/checkin",
"/user/pointsShop"=>"index/user/pointsShop",
"/user/pointsExchange"=>"index/user/pointsExchange",

//后台积分商城和会员等级
"/admin/index/pointsProducts/[:id]"=>"admin/index/pointsProducts",
"/admin/index/membershipLevels/[:id]"=>"admin/index/membershipLevels",

// 后台全局实时搜索接口
"/admin/index/search"=>"admin/index/search",

//后台访客统计
"/admin/visitorStats"=>"admin/index/visitorStats",
"/admin/userAccessStats"=>"admin/index/userAccessStats",
"/admin/unverifiedUsers"=>"admin/index/unverifiedUsers",

//滑块验证码
"/captcha/generate"=>"index/captcha/generate",
"/captcha/verify"=>"index/captcha/verify",
// 极验行为验证 GT3
"/captcha/gtregister"=>"index/captcha/gtregister",
"/captcha/gtvalidate"=>"index/captcha/gtvalidate",
// 极验行为验证 GT4（第四代）
"/captcha/gt4validate"=>"index/captcha/gt4validate",
// 兼容原版滑动验证码路由
"/index/slide_captcha/create"=>"index/captcha/generate",
"/index/slide_captcha/verify"=>"index/captcha/verify",

//排行榜
"/rankings"=>"index/index/rankings",

//Live2D AI聊天
"/live2d/chat"=>"index/live2d/chat",
//Live2D 手机端纹理压缩（服务器端降采样并缓存）
"/live2d/texture"=>"index/live2d/texture",

//看板娘点歌 / 个人背景音乐（GD音乐台 API 代理）
"/music/search"=>"index/music/search",
"/music/url"=>"index/music/url",
"/music/pic"=>"index/music/pic",
"/music/lyric"=>"index/music/lyric",
"/music/setting"=>"index/music/setting",
"/music/save"=>"index/music/save",
"/music/position"=>"index/music/position",
"/music/playlist"=>"index/music/playlist",

//聚合登录
"/oauth/login/:type"=>"index/oauth/login",
"/oauth/callback"=>"index/oauth/callback",
"/oauth/bind"=>"index/oauth/bind",
"/oauth/userBind/:type"=>"index/oauth/userBind",
"/oauth/unbind/:type"=>"index/oauth/unbind",

// 梦娜宝塔违规通知对接
"/api/violation_notify"=>"index/user/violationNotify",
"/api/host_status_proxy"=>"index/user/hostStatusProxy",

// 用户开发者中心
"/user/developer"=>"index/user/developer",
"/user/developerCreateKey"=>"index/user/developerCreateKey",
"/user/developerDeleteKey"=>"index/user/developerDeleteKey",
"/user/developerToggleKey"=>"index/user/developerToggleKey",

// 开放 API（开发者密钥调用，注意：需置于 /api/xxx 精确路由之后）
"/api/[:action]"=>"index/open/index",

// ===== 活动中心 + 每日抽奖 =====
"/user/activity"=>"index/activity/index",
"/user/quiz/[:id]"=>"index/activity/quiz",
"/user/quizSubmit"=>"index/activity/quizSubmit",
"/user/lottery"=>"index/activity/lottery",
"/user/lottery_records"=>"index/activity/records",
"/user/lottery_public_records"=>"index/activity/publicRecords",
"/user/quiz_rank"=>"index/activity/quizRank",

// ===== 域名商城 =====
"/domain"=>"index/domain/index",
"/domain/mine"=>"index/domain/mine",
"/domain/add"=>"index/domain/add",
"/domain/detail/[:id]"=>"index/domain/detail",
"/domain/buy/[:id]"=>"index/domain/buy",
"/domain/del"=>"index/domain/del",

// ===== 数据大屏 =====
"/datav"=>"index/datav/index",
"/datav/data"=>"index/datav/data",

// ===== 后台：活动中心/抽奖/域名商城管理 =====
"/admin/activity/quiz"=>"admin/admin_activity/quiz",
"/admin/activity/questions"=>"admin/admin_activity/questions",
"/admin/activity/lottery"=>"admin/admin_activity/lottery",
"/admin/activity/domain"=>"admin/admin_activity/domain",

// ===== 后台：数据大屏 =====
"/admin/datav"=>"admin/datav/index",
"/admin/datav/data"=>"admin/datav/data",

// ===== 服务器状态（宝塔面板 API 对接） =====
"/server/status"=>"index/btstatus/index",
"/admin/bt_server"=>"admin/bt_server/index",
"/admin/bt_server/save"=>"admin/bt_server/save",
"/admin/bt_server/del"=>"admin/bt_server/del",
"/admin/bt_server/test"=>"admin/bt_server/test",
"/admin/bt_server/refresh"=>"admin/bt_server/refresh",
"/admin/bt_server/status"=>"admin/bt_server/status",

// ===== 系统重要记录 =====
"/admin/sys_record"=>"admin/sys_record/index",
"/admin/sys_record/detail"=>"admin/sys_record/detail",
"/admin/sys_record/setting"=>"admin/sys_record/setting",
"/admin/sys_record/clear"=>"admin/sys_record/clear",
"/admin/sys_record/snapshot"=>"admin/sys_record/snapshot",
];
