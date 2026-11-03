<?php

namespace App\Permissions;

/**
 * Something an MCP access token may be allowed to do. Capabilities are independent: none implies another.
 */
enum Capability: string
{
    case View = 'view';
    case ViewLogs = 'view_logs';
    case Deploy = 'deploy';
    case RestartServices = 'restart_services';
    case ManageWorkers = 'manage_workers';
    case ViewEnv = 'view_env';
    case EditEnv = 'edit_env';
    case RunCommands = 'run_commands';
    case ConfigureWorkers = 'configure_workers';
    case ConfigureDeployments = 'configure_deployments';
    case RebootServer = 'reboot_server';
    case MaintenanceMode = 'maintenance_mode';

    public function tier(): Tier
    {
        return match ($this) {
            self::View, self::ViewLogs => Tier::Read,
            self::Deploy, self::RestartServices, self::ManageWorkers, self::MaintenanceMode => Tier::Action,
            self::ViewEnv, self::EditEnv, self::RunCommands, self::ConfigureWorkers,
            self::ConfigureDeployments, self::RebootServer => Tier::Risky,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'View',
            self::ViewLogs => 'View logs',
            self::Deploy => 'Deploy',
            self::RestartServices => 'Restart services',
            self::ManageWorkers => 'Manage workers',
            self::ViewEnv => 'View .env',
            self::EditEnv => 'Edit .env',
            self::RunCommands => 'Run commands',
            self::ConfigureWorkers => 'Configure workers',
            self::ConfigureDeployments => 'Configure deployments',
            self::RebootServer => 'Reboot server',
            self::MaintenanceMode => 'Maintenance mode',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::View => 'Browse organizations, servers and sites, and check deployment status.',
            self::ViewLogs => 'Read site, deployment and worker logs.',
            self::Deploy => 'Trigger deployments.',
            self::RestartServices => 'Restart services such as PHP, Nginx and databases.',
            self::ManageWorkers => 'Restart, start and stop existing queue workers.',
            self::ViewEnv => 'Read the .env file, including secrets.',
            self::EditEnv => 'Change the .env file.',
            self::RunCommands => 'Run arbitrary commands in a site directory.',
            self::ConfigureWorkers => 'Create, change and delete background processes (runs arbitrary commands, optionally as root).',
            self::ConfigureDeployments => 'Change the deployment script and quick deploy.',
            self::RebootServer => 'Reboot the whole server.',
            self::MaintenanceMode => 'Put a site into and out of maintenance mode.',
        };
    }

    public function defaultAllowed(): bool
    {
        return $this === self::View || $this === self::ViewLogs;
    }

    /** Server-wide operations cannot be granted per site. */
    public function allowsSiteScope(): bool
    {
        return $this !== self::RestartServices && $this !== self::RebootServer;
    }

    /** @return list<string> Forge API token scopes this capability needs. */
    public function forgeScopes(): array
    {
        return match ($this) {
            self::View, self::ViewEnv => ['server:view'],
            self::ViewLogs => ['server:manage-logs', 'site:manage-deploys', 'server:create-daemons'],
            self::Deploy, self::ConfigureDeployments => ['site:manage-deploys'],
            self::RestartServices, self::RebootServer => ['server:manage-services'],
            self::ManageWorkers => ['server:create-daemons'],
            self::EditEnv => ['site:manage-environment'],
            self::RunCommands, self::MaintenanceMode => ['site:manage-commands'],
            self::ConfigureWorkers => ['server:create-daemons', 'server:delete-daemons'],
        };
    }

    /** @return array<string, list<self>> Forge token scope => capabilities that need it, in declaration order. */
    public static function requiredForgeScopes(): array
    {
        $scopes = [];

        foreach (self::cases() as $capability) {
            foreach ($capability->forgeScopes() as $scope) {
                $scopes[$scope][] = $capability;
            }
        }

        return $scopes;
    }
}
