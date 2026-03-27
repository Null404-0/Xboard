# CLAUDE.md — Xboard 定制版

## 项目背景

本仓库是 [Xboard](https://github.com/cedar2025/Xboard) 的个人定制 Fork。
目标是**自行开发 Admin 前端管理后台**，替换原编译混淆的官方 xboard-admin-dist submodule，方便长期 DIY 和功能扩展。

## 仓库关系

```
null404-0/xboard              ← 本仓库，后端 (Laravel 12 + PHP 8.2)
null404-0/xboard-admin        ← 前端源码 (待建)
null404-0/xboard-admin-dist   ← 前端编译产物，通过 git submodule 引入 (待建)
```

`.gitmodules` 中将官方 submodule URL 替换为 `null404-0/xboard-admin-dist`，
`init.sh` 无需修改，`git submodule update --init --recursive --force` 自动拉取。

## 技术栈

- PHP 8.2 + Laravel 12
- 队列：Laravel Horizon
- 运行时：Laravel Octane + Workerman
- 认证：Laravel Sanctum
- 数据库表前缀：`v2_`

## Admin API 规范

- 路由文件：`app/Http/Routes/V2/AdminRoute.php`
- 控制器目录：`app/Http/Controllers/V2/Admin/`
- URL 前缀：`/{secure_path}/`（动态，从 `admin_setting('secure_path')` 读取）
- 中间件：`admin`、`log`
- 响应格式：`response(['data' => ...])`

### 现有 Admin API 模块

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

**分支**：`claude/audit-admin-frontend-zmqCQ`

**新增文件**：
- `database/migrations/2026_03_27_000001_create_v2_server_user_table.php`
- `app/Models/ServerUser.php`
- `app/Http/Controllers/V2/Admin/Server/UserAssignController.php`

**修改文件**：
- `app/Services/ServerService.php`
- `app/Http/Routes/V2/AdminRoute.php`

**功能说明**：
- `v2_server_user` 表：`(server_id, user_id)` 联合主键，无需 `server_type`（统一 Server Model）
- API：`GET /server/userAssign/fetch`、`POST /server/userAssign/save`、`GET /server/userAssign/searchUsers`
- `getAllServers` 响应中每个节点附带 `assigned_user_ids` 字段
- `getAvailableServers`：专属分配节点对用户可见（bypass group_ids 检查）
- `getAvailableUsers`：节点下发用户列表包含专属分配用户

**部署**：执行 `php artisan migrate` 后生效

## 开发约定

- 功能分支：`claude/audit-admin-frontend-zmqCQ`
- 迁移文件命名：`YYYY_MM_DD_HHMMSS_描述.php`
- 新 Model 放 `app/Models/`，表名使用 `v2_` 前缀
- Admin V2 控制器放 `app/Http/Controllers/V2/Admin/`
- 不动 `app/Http/Routes/V1/`（V1 兼容层保留）

## 下一步计划

- [ ] 新建 `null404-0/xboard-admin` 前端源码仓库
- [ ] 新建 `null404-0/xboard-admin-dist` 编译产物仓库
- [ ] 修改 `.gitmodules` 指向自己的 dist 仓库
- [ ] 前端技术选型并搭建脚手架（Vue3 / React + TypeScript + UI 框架）
- [ ] 配置 GitHub Actions 自动编译并推送到 dist 仓库
