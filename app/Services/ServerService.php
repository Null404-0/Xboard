<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerRoute;
use App\Models\ServerUser;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Support\Collection;

class ServerService
{
    /**
     * 获取所有服务器列表（附带专属分配用户 ID）
     */
    public static function getAllServers(): Collection
    {
        $servers = Server::orderBy('sort', 'ASC')
            ->get()
            ->append([
                'last_check_at',
                'last_push_at',
                'online',
                'is_online',
                'available_status',
                'cache_key',
                'load_status',
                'metrics',
                'online_conn'
            ]);

        // 批量附加专属用户分配信息，避免 N+1
        $assignMap = ServerUser::get(['server_id', 'user_id'])
            ->groupBy('server_id')
            ->map(fn($rows) => $rows->pluck('user_id')->toArray())
            ->toArray();

        return $servers->map(function ($server) use ($assignMap) {
            $server->assigned_user_ids = $assignMap[$server->id] ?? [];
            return $server;
        });
    }

    /**
     * 获取指定用户可用的服务器列表
     * 包含：所属分组的节点 + 专属分配给该用户的节点
     */
    public static function getAvailableServers(User $user): array
    {
        // 一次性查出该用户的专属分配节点 ID，避免 N+1
        $assignedServerIds = ServerUser::where('user_id', $user->id)
            ->pluck('server_id')
            ->toArray();

        $servers = Server::where('show', true)
            ->where(function ($query) use ($user, $assignedServerIds) {
                $query->whereJsonContains('group_ids', (string) $user->group_id);
                if (!empty($assignedServerIds)) {
                    $query->orWhereIn('id', $assignedServerIds);
                }
            })
            ->orderBy('sort', 'ASC')
            ->get()
            ->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'cache_key', 'server_key']);

        $servers = collect($servers)->map(function ($server) use ($user) {
            if (str_contains($server->port, '-')) {
                $port = $server->port;
                $server->port = (int) Helper::randomPort($port);
                $server->ports = $port;
            } else {
                $server->port = (int) $server->port;
            }
            $server->password = $server->generateServerPassword($user);
            $server->rate = $server->getCurrentRate();
            return $server;
        })->toArray();

        return $servers;
    }

    /**
     * 根据节点获取可用用户列表
     * 包含：节点分组内的用户 + 专属分配给该节点的用户
     */
    public static function getAvailableUsers(Server $node)
    {
        // 查出专属分配给该节点的用户 ID
        $assignedUserIds = ServerUser::where('server_id', $node->id)
            ->pluck('user_id')
            ->toArray();

        $users = User::toBase()
            ->where(function ($query) use ($node, $assignedUserIds) {
                $query->whereIn('group_id', $node->group_ids ?? []);
                if (!empty($assignedUserIds)) {
                    $query->orWhereIn('id', $assignedUserIds);
                }
            })
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit'
            ])
            ->get();

        return HookManager::filter('server.users.get', $users, $node);
    }

    // 获取路由规则
    public static function getRoutes(array $routeIds)
    {
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])->whereIn('id', $routeIds)->get();
        return $routes;
    }

    /**
     * Update node metrics and load status
     */
    public static function updateMetrics(Server $node, array $metrics): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);

        $metricsData = [
            'uptime' => (int) ($metrics['uptime'] ?? 0),
            'goroutines' => (int) ($metrics['goroutines'] ?? 0),
            'active_connections' => (int) ($metrics['active_connections'] ?? 0),
            'total_connections' => (int) ($metrics['total_connections'] ?? 0),
            'total_users' => (int) ($metrics['total_users'] ?? 0),
            'active_users' => (int) ($metrics['active_users'] ?? 0),
            'inbound_speed' => (int) ($metrics['inbound_speed'] ?? 0),
            'outbound_speed' => (int) ($metrics['outbound_speed'] ?? 0),
            'cpu_per_core' => $metrics['cpu_per_core'] ?? [],
            'load' => $metrics['load'] ?? [],
            'speed_limiter' => $metrics['speed_limiter'] ?? [],
            'gc' => $metrics['gc'] ?? [],
            'api' => $metrics['api'] ?? [],
            'ws' => $metrics['ws'] ?? [],
            'limits' => $metrics['limits'] ?? [],
            'updated_at' => now()->timestamp,
            'kernel_status' => (bool) ($metrics['kernel_status'] ?? false),
        ];

        \Illuminate\Support\Facades\Cache::put(
            \App\Utils\CacheKey::get('SERVER_' . $nodeType . '_METRICS', $nodeId),
            $metricsData,
            $cacheTime
        );
    }

    public static function buildNodeConfig(Server $node): array
    {
        $nodeType = $node->type;
        $protocolSettings = $node->protocol_settings;
        $serverPort = $node->server_port;
        $host = $node->host;

        $baseConfig = [
            'protocol' => $nodeType,
            'listen_ip' => '0.0.0.0',
            'server_port' => (int) $serverPort,
            'network' => data_get($protocolSettings, 'network'),
            'networkSettings' => data_get($protocolSettings, 'network_settings') ?: null,
        ];

        $response = match ($nodeType) {
            'shadowsocks' => [
                ...$baseConfig,
                'cipher' => $protocolSettings['cipher'],
                'plugin' => $protocolSettings['plugin'],
                'plugin_opts' => $protocolSettings['plugin_opts'],
                'server_key' => match ($protocolSettings['cipher']) {
                        '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
                        '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
                        default => null,
                    },
            ],
            'vmess' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'trojan' => [
                ...$baseConfig,
                'host' => $host,
                'server_name' => $protocolSettings['server_name'],
                'multiplex' => data_get($protocolSettings, 'multiplex'),
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => null,
                    },
            ],
            'vless' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'flow' => $protocolSettings['flow'],
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'hysteria' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'version' => (int) $protocolSettings['version'],
                'host' => $host,
                'server_name' => $protocolSettings['tls']['server_name'],
                'up_mbps' => (int) $protocolSettings['bandwidth']['up'],
                'down_mbps' => (int) $protocolSettings['bandwidth']['down'],
                ...match ((int) $protocolSettings['version']) {
                        1 => ['obfs' => $protocolSettings['obfs']['password'] ?? null],
                        2 => [
                            'obfs' => $protocolSettings['obfs']['open'] ? $protocolSettings['obfs']['type'] : null,
                            'obfs-password' => $protocolSettings['obfs']['password'] ?? null,
                        ],
                        default => [],
                    },
            ],
            'tuic' => [
                ...$baseConfig,
                'version' => (int) $protocolSettings['version'],
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'congestion_control' => $protocolSettings['congestion_control'],
                'tls_settings' => data_get($protocolSettings, 'tls_settings'),
                'auth_timeout' => '3s',
                'zero_rtt_handshake' => false,
                'heartbeat' => '3s',
            ],
            'anytls' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'padding_scheme' => $protocolSettings['padding_scheme'],
            ],
            'socks' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
            ],
            'naive' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
            ],
            'http' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
            ],
            'mieru' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'transport' => data_get($protocolSettings, 'transport', 'TCP'),
                'traffic_pattern' => $protocolSettings['traffic_pattern'],
            ],
            default => [],
        };

        $response = array_filter(
            $response,
            static fn ($value) => $value !== null
        );

        if (!empty($node['route_ids'])) {
            $response['routes'] = self::getRoutes($node['route_ids']);
        }

        if (!empty($node['custom_outbounds'])) {
            $response['custom_outbounds'] = $node['custom_outbounds'];
        }

        if (!empty($node['custom_routes'])) {
            $response['custom_routes'] = $node['custom_routes'];
        }

        if (!empty($node['cert_config']) && data_get($node['cert_config'],'cert_mode') !== 'none' ) {
            $response['cert_config'] = $node['cert_config'];
        }

        return $response;
    }

    /**
     * 根据协议类型和标识获取服务器
     */
    public static function getServer($serverId, ?string $serverType)
    {
        return Server::query()
            ->when($serverType, function ($query) use ($serverType) {
                $query->where('type', Server::normalizeType($serverType));
            })
            ->where(function ($query) use ($serverId) {
                $query->where('code', $serverId)
                    ->orWhere('id', $serverId);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$serverId])
            ->first();
    }
}
