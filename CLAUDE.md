# CLAUDE.md — Xboard 定制版

## 项目背景

本仓库是 [Xboard](https://github.com/cedar2025/Xboard) 的个人定制 Fork。
自行开发 Admin 前端管理后台，替换原编译混淆的官方 xboard-admin-dist submodule，方便长期 DIY 和功能扩展。

## 仓库关系（均已建立并运行）

```
null404-0/xboard              ← 本仓库，后端 (Laravel 12 + PHP 8.2)
null404-0/xboard-admin        ← 前端源码 (Vue3 + TypeScript + Vite + Naive UI)
null404-0/xboard-admin-dist   ← 前端编译产物，通过 git submodule 引入
```

`.gitmodules` 已指向 `null404-0/xboard-admin-dist`（非上游 cedar2025）。

## 技术栈

- PHP 8.2 + Laravel 12
- 队列：Laravel Horizon
- 运行时：Laravel Octane + Workerman
- 认证：Laravel Sanctum
- 数据库表前缀：`v2_`

## Admin API 规范

- 路由文件：`app/Http/Routes/V2/AdminRoute.php`（权威来源，遇到疑问先查此文件）
- 控制器目录：`app/Http/Controllers/V2/Admin/`
- URL 前缀：`/{secure_path}/`（动态，从 `admin_setting('secure_path')` 读取）
- 中间件：`admin`、`log`
- 响应格式：`response(['data' => ...])`

### 命名规则
- **删除操作**：统一用 `drop`（例：`server/manage/drop`、`notice/drop`）
- **例外**：`user/destroy`、`plugin/delete`
- **查询**：大多数用 `fetch`，少数特殊（`plugin/getPlugins`、`theme/getThemes`、`server/manage/getNodes`）

### Admin API 模块

| 前缀 | 说明 |
|------|------|
| `config` | 系统设置 |
| `plan` | 套餐管理 |
| `server/group` | 节点分组 |
| `server/route` | 节点路由规则 |
| `server/manage` | 节点管理（统一 Server Model） |
| `server/userAssign` | 专属节点用户分配（自定义新增） |
| `order` | 订单管理 |
| `user` | 用户管理 |
| `stat` | 统计数据 |
| `notice` | 公告管理 |
| `ticket` | 工单管理 |
| `coupon` | 优惠券管理 |
| `gift-card` | 礼品卡管理 |
| `knowledge` | 知识库 |
| `payment` | 支付方式 |
| `system` | 系统状态 / 队列 / 审计日志 |
| `theme` | 主题管理 |
| `plugin` | 插件管理 |
| `traffic-reset` | 流量重置 |

## 自定义改动记录

### feat: UserAssign 专属节点用户分配

**新增文件**：
- `database/migrations/2026_03_27_000001_create_v2_server_user_table.php`
- `app/Models/ServerUser.php`
- `app/Http/Controllers/V2/Admin/Server/UserAssignController.php`

**修改文件**：
- `app/Services/ServerService.php`
- `app/Http/Routes/V2/AdminRoute.php`

**功能说明**：
- `v2_server_user` 表：`(server_id, user_id)` 联合主键
- API：`GET /server/userAssign/fetch`、`POST /server/userAssign/save`、`GET /server/userAssign/searchUsers`
- `getNodes` 响应中每个节点附带 `assigned_user_ids` 字段
- `getAvailableServers`：专属分配节点对用户可见（bypass group_ids 检查）

**部署**：首次部署后执行 `php artisan migrate`

## 前端页面状态

详见 `null404-0/xboard-admin` 的 CLAUDE.md，当前概况：

### 已完成（7页）
`/dashboard`、`/system/config`（9个配置分区）、`/system/plugins`、`/system/themes`、`/system/notices`、`/system/payment`、`/server/nodes`

### 待开发（9页，均为 Placeholder.vue 占位）
`/system/knowledge`、`/server/groups`、`/server/routes`、`/order/plans`、`/order/orders`、`/order/coupons`、`/order/gift-cards`、`/users`、`/tickets`

## 部署方案（宝塔 + Docker）

与上游 Xboard 保持一致，使用 `compose.sample.yaml` 作为基础：
- 镜像：`ghcr.io/cedar2025/xboard:new`
- 挂载：`./:/www/`（代码卷，不烧入镜像）
- 无 MySQL 服务（宝塔单独安装 MySQL）
- 服务：web (octane:7001)、horizon、ws-server、redis

### 更新命令
```bash
docker compose pull
docker compose run -it --rm web sh update.sh
docker compose up -d
```

`update.sh` 已修改，包含：
```bash
git submodule update --init --recursive --force
git submodule update --remote public/assets/admin   # 拉取最新前端产物
php artisan xboard:update
```

## CI/CD 流程

```
开发者 push → xboard-admin main
  → GitHub Actions 构建前端
  → push dist 到 xboard-admin-dist main
  → VPS 执行 update.sh → git submodule update --remote → 自动拉取最新前端
```

## 开发约定

- 迁移文件命名：`YYYY_MM_DD_HHMMSS_描述.php`
- 新 Model 放 `app/Models/`，表名使用 `v2_` 前缀
- Admin V2 控制器放 `app/Http/Controllers/V2/Admin/`
- 不动 `app/Http/Routes/V1/`（V1 兼容层保留）
