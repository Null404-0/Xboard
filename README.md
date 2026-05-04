# Xboard（个人定制版）

<div align="center">

[![Telegram](https://img.shields.io/badge/Telegram-Channel-blue)](https://t.me/XboardOfficial)
![PHP](https://img.shields.io/badge/PHP-8.2+-green.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## 📖 项目简介

本仓库是 [cedar2025/Xboard](https://github.com/cedar2025/Xboard) 的**个人定制 Fork**，自行开发 Admin 前端管理后台，替换原编译混淆的官方 `xboard-admin-dist`，方便长期 DIY 与功能扩展。

Xboard 本体是一个基于 Laravel 12 的现代化面板系统，专注于提供简洁高效的使用体验。

## 🗂️ 仓库关系

本项目由 3 个仓库协同工作，已全部建立并运行：

```
Null404-0/Xboard              ← 本仓库（后端 Laravel 12 + PHP 8.2）
Null404-0/xboard-admin        ← 前端源码（Vue3 + TypeScript + Vite + Naive UI）
Null404-0/xboard-admin-dist   ← 前端编译产物，通过 git submodule 引入到 public/assets/admin
```

`.gitmodules` 已指向 `Null404-0/xboard-admin-dist`（**非**上游 cedar2025）。

### 开发部署流程图

```
开发者 push xboard-admin
        │
        ▼
GitHub Actions 编译前端
        │
        ▼
push dist 到 xboard-admin-dist:main
        │
        ▼
VPS 执行 update.sh
  └─ git submodule update --remote public/assets/admin
        │
        ▼
用户访问后台立即看到新版
```

## ✨ 主要特性

- 🚀 基于 Laravel 12 + Octane（Workerman 运行时），高性能 HTTP 处理
- 🎨 自研 Admin 前端（Vue3 + TypeScript + Naive UI，深色主题）
- 📦 Horizon 队列管理 + Sanctum 认证
- 🐳 开箱即用的 Docker 部署方案
- 🔌 插件 / 主题热加载，便于 DIY

## 🛠️ 技术栈

| 组件 | 版本 / 选型 |
|---|---|
| 后端框架 | Laravel 12 |
| 运行时 | Laravel Octane + Workerman |
| PHP | 8.2+ |
| 数据库 | MySQL 5.7+ / 8.0（表前缀 `v2_`） |
| 缓存 / 队列 | Redis 7（Unix socket 共享） |
| 队列管理 | Laravel Horizon |
| 认证 | Laravel Sanctum |
| Admin 前端 | Vue 3 + TypeScript + Vite + Naive UI |
| 部署 | Docker Compose + 宝塔（推荐） |

---

# 🚀 部署指南：宝塔 + Docker（推荐）

本套部署方案基于 `compose.sample.yaml`：

- **MySQL 由宝塔单独安装**（不在 Compose 内），便于备份和图形化管理
- **Redis 在 Compose 内**通过 Unix socket（`/data/redis.sock`）共享给 web / horizon / ws-server
- 全部容器使用 `network_mode: host`，因此容器内 `127.0.0.1:3306` 直连宝塔的 MySQL
- web 服务监听 `0.0.0.0:7001`，由宝塔 Nginx 反向代理出域名 + SSL

## 📋 前置条件

- 一台 VPS（Linux x86_64 / arm64 均可）
- 已安装 [宝塔面板](https://www.bt.cn/)（建议 9.0+）
- 一个解析到 VPS 的域名（用于 SSL）

## 步骤 1：在宝塔安装 Docker

宝塔面板 → **软件商店** → 搜索 `Docker 管理器` → 安装。

安装后验证：

```bash
docker --version
docker compose version
```

确认 `docker compose`（v2 插件形式）可用。如果只装了 `docker-compose`（v1，带连字符），把后文命令中的 `docker compose` 替换成 `docker-compose` 即可。

## 步骤 2：在宝塔安装 MySQL 并创建数据库

宝塔面板 → **软件商店** → 安装 **MySQL 8.0**（或 5.7）。

然后到 **数据库** 菜单：

- 点 **添加数据库**
- 数据库名 / 用户名：`xboard`
- 密码：自行生成强密码并保存好
- 字符集：`utf8mb4`
- 访问权限：本地服务器（`127.0.0.1`，重要）

## 步骤 3：克隆代码

```bash
mkdir -p /www/wwwroot/xboard
cd /www/wwwroot/xboard

git clone -b master --recurse-submodules \
  https://github.com/Null404-0/Xboard.git .
```

> **重要**：必须加 `--recurse-submodules`，否则 `public/assets/admin` 目录会是空的，登录后台会 404。如果忘记加，可后续执行：
>
> ```bash
> git submodule update --init --recursive --force
> ```

## 步骤 4：准备配置文件

```bash
cp .env.example .env
cp compose.sample.yaml compose.yaml
```

编辑 `.env`：

```bash
vi .env
```

至少修改以下字段（其他保持默认即可）：

```env
APP_NAME=YourSiteName
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your.domain

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=xboard
DB_USERNAME=xboard
DB_PASSWORD=步骤2里宝塔生成的强密码

# Redis 走 Unix socket，无需改
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

## 步骤 5：首次初始化（建表 + 创建管理员）

```bash
cd /www/wwwroot/xboard
docker compose run -it --rm \
  -e ENABLE_SQLITE=false \
  -e ENABLE_REDIS=true \
  web sh init.sh
```

`init.sh` 会做四件事：

1. 下载 Composer 并安装 PHP 依赖
2. 拉取 / 更新 git 子模块（即 `public/assets/admin`）
3. 执行 `php artisan xboard:install` —— **这一步会交互式询问管理员邮箱、密码**，请记录
4. 修正文件权限

> 安装结束后控制台会打印 **后台访问路径** `secure_path`（一段随机字符串），形如 `https://your.domain/{secure_path}`，请务必保存。

如果遗忘了 `secure_path`，可随时查询：

```bash
docker compose run --rm web php artisan tinker --execute="echo admin_setting('secure_path');"
```

## 步骤 6：启动所有服务

```bash
docker compose up -d
docker compose ps
```

应看到 4 个容器都在 `running`：

| 服务 | 作用 |
|---|---|
| `web`       | Octane HTTP 服务，监听 7001 |
| `horizon`   | Laravel Horizon 队列守护进程 |
| `ws-server` | WebSocket 推送（节点上线提醒等） |
| `redis`     | 内部缓存与队列后端 |

查看启动日志：

```bash
docker compose logs -f web
```

出现 `Server running on http://0.0.0.0:7001` 即正常。

## 步骤 7：宝塔反向代理 + SSL

宝塔面板 → **网站** → **添加站点**：

- 域名：`your.domain`
- PHP 版本：**纯静态**（不走宝塔的 PHP，所有请求由 Octane 处理）
- 数据库 / FTP：均不创建

站点创建后，进入 **设置 → 反向代理**：

- 名称：`xboard`
- 目标 URL：`http://127.0.0.1:7001`
- 发送域名：`$host`
- 启用代理：✅

再进入 **设置 → SSL**：

- 选 **Let's Encrypt** → 申请并强制 HTTPS

## 步骤 8：验证部署

依次访问：

1. `https://your.domain/` —— 应看到用户前台或 404（取决于是否装了用户主题）
2. `https://your.domain/{secure_path}` —— 应看到 **自定义 Vue Admin 登录页**（深色主题，左上角是站点名）
3. 登录后访问 `/#/system/status` —— 检查：
   - **调度任务** 状态为「运行中」
   - **Horizon** 状态为「运行中」
   - **每分钟处理量** > 0（如果 horizon 容器跑起来了，几秒后就会有数据）

如果第 2 步是空白页或 404，说明 submodule 没拉到位。在 VPS 上：

```bash
cd /www/wwwroot/xboard
ls public/assets/admin   # 必须存在 index.html、assets/、manifest.json
```

如果是空目录，跑：

```bash
git submodule update --init --recursive --force
git submodule update --remote public/assets/admin
docker compose restart web
```

---

# 🔄 日常更新

## 后端更新（拉最新 master 代码）

```bash
cd /www/wwwroot/xboard
docker compose pull                                  # 拉最新镜像
docker compose run -it --rm web sh update.sh        # 更新代码 + 子模块 + 数据库
docker compose up -d                                 # 重启服务
```

`update.sh` 实际做了：

```bash
git fetch --all && git reset --hard origin/master && git pull origin master
php composer.phar update
git submodule update --init --recursive --force
git submodule update --remote public/assets/admin   # ← 拉最新前端编译产物
php artisan xboard:update                            # ← 跑迁移、清缓存
```

## 前端更新（自动）

你**不需要手动操作**前端更新。每次 push 到 `xboard-admin/main`，GitHub Actions 会：

1. 用 Node 24 跑 `npm ci && npm run build`
2. 把 `dist/` 强制覆盖 `xboard-admin-dist/main`
3. VPS 下次跑 `update.sh` 时，`git submodule update --remote` 会拉到新版

CI 用到的 Secret：`DIST_DEPLOY_TOKEN`（Classic PAT，需 `repo` scope），配置在：

```
https://github.com/Null404-0/xboard-admin/settings/secrets/actions
```

如果哪天 token 过期，CI 会在 push 步骤报 `Authentication failed`，进去更新即可。

## 修改后台路径后

修改 `secure_path` 后必须重启容器，让 Octane 重新加载配置：

```bash
docker compose restart
```

---

# 🔧 常见问题

### Q1: 后台是空白页 / 404

99% 是 submodule 没拉到位。见步骤 8 末尾。

### Q2: 改了 `.env` 没生效

Octane 常驻内存，需要：

```bash
docker compose restart web horizon
```

### Q3: MySQL 连不上（容器内 `Connection refused`）

确认：

- compose 里使用的是 `network_mode: host`（默认就是）
- 宝塔 MySQL 监听 `127.0.0.1:3306`（不是 `0.0.0.0`）
- `.env` 里 `DB_HOST=127.0.0.1` 而不是 `mysql` 或容器名
- 宝塔 MySQL 用户的「访问权限」选了 `本地服务器`

排查命令：

```bash
docker compose run --rm web sh -c \
  "mysql -h 127.0.0.1 -u xboard -p'your_pass' -e 'SHOW DATABASES;'"
```

### Q4: Horizon 显示「异常」

看 horizon 容器日志：

```bash
docker compose logs -f horizon
```

常见原因：Redis socket 文件权限不对。重建 redis 卷：

```bash
docker compose down
docker volume rm xboard_redis-data
docker compose up -d
```

### Q5: 我想改 web 端口（7001 → 别的）

编辑 `compose.yaml`：

```yaml
web:
  command: php artisan octane:start --port=8080 --host=0.0.0.0
```

然后宝塔反向代理目标改成 `http://127.0.0.1:8080`，重启 web 容器。

### Q6: ARM 架构（aarch64）能跑吗

可以。镜像 `ghcr.io/cedar2025/xboard:new` 支持 multi-arch，Dockerfile 已经针对 ARM64 调低了 PHP 扩展编译优化级别。

---

# 🛠️ 自定义改动记录

本 fork 相对上游 `cedar2025/Xboard` 的非平凡改动：

## feat: UserAssign 专属节点用户分配

**新增文件**：

- `database/migrations/2026_03_27_000001_create_v2_server_user_table.php`
- `app/Models/ServerUser.php`
- `app/Http/Controllers/V2/Admin/Server/UserAssignController.php`

**修改文件**：

- `app/Services/ServerService.php`
- `app/Http/Routes/V2/AdminRoute.php`

**功能**：

- `v2_server_user` 表：`(server_id, user_id)` 联合主键
- API：`GET /server/userAssign/fetch`、`POST /server/userAssign/save`、`GET /server/userAssign/searchUsers`
- `getNodes` 响应中每个节点附带 `assigned_user_ids` 字段
- `getAvailableServers`：专属分配节点对用户可见（绕过 group_ids 检查）

**首次部署后需要执行**：

```bash
docker compose run --rm web php artisan migrate
```

---

# 📁 开发约定

- 数据库迁移文件命名：`YYYY_MM_DD_HHMMSS_描述.php`
- 新 Model 放 `app/Models/`，表名使用 `v2_` 前缀
- Admin V2 控制器放 `app/Http/Controllers/V2/Admin/`
- 不动 `app/Http/Routes/V1/`（V1 兼容层保留）
- Admin API 路由权威来源：`app/Http/Routes/V2/AdminRoute.php`
- 所有 Admin API 删除操作统一用 `drop`（例外：`user/destroy`、`plugin/delete`）

详细前端开发约定请见 `Null404-0/xboard-admin` 的 `CLAUDE.md`。

---

# 📷 预览

![Admin Preview](./docs/images/admin.png)

![User Preview](./docs/images/user.png)

---

# ⚠️ 免责声明

本项目仅供学习与交流使用。使用本项目所产生的一切后果由使用者自行承担。

# 📈 Star 历史（上游）

[![Stargazers over time](https://starchart.cc/cedar2025/Xboard.svg)](https://starchart.cc/cedar2025/Xboard)
