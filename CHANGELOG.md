# CHANGELOG

## v1.3.5 — Docker 容器开通整合进「产品套餐购买配置」
> 将 Docker 容器主机开通从「产品控制台自助按钮」扩展为「购买套餐时勾选的附加项」，用户在下单页即可一并选择并付费，与主机订单一同结算/开通。

### 新增功能
- **购买页 Docker 开关**：在 `/cart` 购买配置页「购买配置」区新增「开通 Docker 容器」卡片，仅当「全局开关 + 当前套餐 docker_enabled + 线路插件(mnbt._DockerOpen)」三者满足时显示。
- **价格叠加**：勾选后整单金额实时叠加全局统一价 `docker_price`（不受会员折扣影响），明细中单独列出「Docker 容器开通」。
- **下单即开通**：随主机订单生成 `docker_order`（state=1 已支付待开通），与主机的余额/在线支付一并结算；不支持积分支付（勾选后自动隐藏积分选项并回退余额）。
- **按模式开通**：主机实际开通成功后（`settleItems` 立即分支 / `cartSettle` 立即分支 / `process_pending_host_orders` / 后台手动开通 `createhost`）按 `docker_mode` 自动或人工开通该主机名下的 Docker。

### 数据表 / 配置变更（运行时自动迁移）
- `dd_shopping_cart` 新增列 `docker` tinyint(1) 默认 0（购买时是否勾选开通 Docker）。

### 关键实现文件
- `app/common.php`：新增 `docker_create_for_purchase($hostOrderId, $payway)`（幂等生成待开通记录）与 `docker_provision_pending_for_order($hostOrderId)`（按 mode 自动/人工开通）；`process_pending_host_orders()` 主机开通成功后触发后者。
- `app/index/controller/Index.php`：`cart()` 传入全局 Docker 配置，给 PLANS 加 `docker_enabled`、SERVERS 加 `docker_supported`。
- `app/index/controller/User.php`：`buyNow()` / `cartAdd()` 读取并校验 `docker` 入参、叠加价格、落库；`settleItems()` / `cartSettle()` 创建主机订单后生成 docker_order。
- `app/admin/controller/Index.php` `createhost`：主机开通成功后触发 `docker_provision_pending_for_order()`。
- `app/index/view/default/index/cart.html`：Docker 开关卡片、价格叠加、概要行、提交 `docker=1`。

### 校验
- php-parser：common.php / index/{User,Index}.php / admin/Index.php 全部通过。
- 自写 JS 校验（剥离 TP 模板标签后 vm.Script）：cart.html 全部通过。

---

— Docker 容器开通（产品控制台自助开通）
> 对接控制面板「LHX主机面板 V2 / mnbt」，用户可在产品控制台一键开通 Docker 容器（需支付、支持自动/手动开通）。

### 新增功能
- **产品控制台「Docker 容器开通」按钮**：满足「全局开关 + 产品开关 + 服务器插件为 mnbt」三条件时显示入口。
- **支付开通费**：后台配置全局统一价 `docker_price`；支持余额支付与在线支付（复用 epay/f2fpay 充值→结算链路）。
- **自动 / 手动开通**：`docker_mode=auto` 直接调面板 API 开通；`docker_mode=manual` 生成待办记录，管理员后台一键开通。
- **自助关闭**：`docker_allow_close` 开启时，用户可在控制台关闭 Docker（保留已填容器规格）。
- **后台管理页 `/admin/docker`**：开通记录列表（已开通/待开通/失败统计）、手动开通、关闭、删除；另提供「后台直接为指定主机开通」。

### 数据表 / 配置变更（首次访问自动迁移，无需手工 SQL）
- `dd_web` 新增列：`docker_enabled`、`docker_price`、`docker_mode`、`docker_pay_balance`、`docker_pay_online`、`docker_allow_close`、`docker_intro`。
- `dd_cart` 新增列：`docker_enabled`（产品级开关）。
- 新建表 `dd_docker_order`（开通记录，含 `opened_at`）。

### 关键实现文件
- `app/common.php`：`docker_order` 表与各类 helper（`docker_is_enabled/docker_price/docker_mode/docker_pay_ways/docker_latest_for_host/docker_provision/docker_shutdown/docker_state_text/docker_settle_pending`）。
- `plugins/host/mnbt/mnbt.php`：`mnbt_DockerOpen/Close/Status`（调用面板 `?gn=docker`）。
- `app/index/controller/User.php`：`order()` 新增 `docker_pay/docker_status/docker_close` act。
- `app/index/view/default/user/panel.html`：开通按钮 + 弹窗 + 轮询 JS。
- `app/admin/controller/AdminHost.php` + `app/route.php` + `app/admin/view/default/docker_manager.html` + `header.html`：后台管理。
- `app/admin/controller/Index.php` + `app/admin/view/default/{set,products}.html`：后台设置与产品开关。

### 防二次扣费
- 已开通/处理中/待支付状态再次点击均被拦截。
- 关闭(3)或失败(4)后重新开通：若上一笔已付费或本身免费，**复用旧记录免二次扣费**；新增 `opened_at` 展示开通时间。

### 校验
- `check_php.js`（php-parser）: common.php / index/{User,Index}.php / admin/{Index,AdminHost}.php / mnbt.php / route.php 全部通过。
- `check_js.js`: panel.html / set.html / products.html / docker_manager.html / header.html 全部通过。

---
## v1.3.7 — 系统救援工具箱 / 登录入口 / 卡密升级 等
详见提交记录与历史维护日志。

## v1.3.6 — 安装向导 UI 重构（6 步竖向步骤 + 蓝白主题）
详见提交记录与历史维护日志。
