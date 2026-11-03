<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Forge\ForgeException;
use App\Mcp\Support\Poller;
use App\Mcp\Support\ToolContext;
use App\Mcp\Tools\Concerns\FormatsCommandResult;
use App\Permissions\Capability;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

#[Description('Run a shell command in the directory of a Forge site and wait briefly for the result. DANGEROUS: executes arbitrary code on a production server, so run only commands the user asked for. Returns exit code and output, or a hint to call get_command_result if it is still running.')]
#[IsDestructive]
class RunSiteCommand extends ForgeTool
{
    use FormatsCommandResult;

    private const int MAX_COMMAND_LENGTH = 2000;

    /** Allowed skew between our clock and Forge's created_at when matching the new command. */
    private const int CLOCK_SKEW_SECONDS = 5;

    protected string $name = 'run_site_command';

    protected function capability(): Capability
    {
        return Capability::RunCommands;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'organization' => $schema->string()->description('Organization slug. May be omitted when only one is available.'),
            'server_id' => $schema->integer()->description('Forge server id the site lives on. Defaults to the configured server if omitted.'),
            'site_id' => $schema->integer()->description('Forge site id (from list_sites). Defaults to the configured site if omitted.'),
            'command' => $schema->string()->required()->description('Shell command to run in the site directory (max 2000 characters).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $request->validate([
            'command' => ['required', 'string', 'max:'.self::MAX_COMMAND_LENGTH, function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && str_contains($value, "\0")) {
                    $fail('The command must not contain NUL bytes.');
                } elseif (is_string($value) && preg_match('/[\r\n]/', $value) === 1) {
                    $fail('command must be a single line; join multiple commands with && or ;.');
                }
            }],
        ]);

        $org = $this->resolveOrganization($request);
        $serverId = $this->resolveServerId($request, $org);
        $siteId = $this->resolveSiteId($request, $org, $serverId);
        $command = (string) $request->get('command');

        return $this->guarded(
            $request,
            fn (ToolContext $ctx) => $this->siteTarget($ctx, $org, $serverId, $siteId),
            function (ToolContext $ctx) use ($org, $serverId, $siteId, $command) {
                // Ids already present before we queue anything: a new command is one that is not in here.
                $known = array_map(fn (array $c) => (int) ($c['id'] ?? 0), $ctx->forge->commands($org, $serverId, $siteId, 5));
                $since = Carbon::now()->subSeconds(self::CLOCK_SKEW_SECONDS);

                $ctx->forge->runCommand($org, $serverId, $siteId, $command);
                $ctx->summary = "Ran a command on site {$siteId}.";

                // From here on the command is queued: every failure must say so (no blind re-run).
                try {
                    $commandId = $this->findCommandId($ctx, $org, $serverId, $siteId, $command, $since, $known);
                } catch (ForgeException $e) {
                    $ctx->summary = "Ran a command on site {$siteId}: queued, id lookup failed";

                    return "Command was queued, but looking up its id failed: {$e->agentMessage()}. Check the site's commands in Forge before running it again.";
                } catch (Throwable) {
                    $ctx->summary = "Ran a command on site {$siteId}: queued, id lookup failed";

                    return "Command was queued, but looking up its id failed unexpectedly. Check the site's commands in Forge before running it again.";
                }

                if ($commandId === null) {
                    return "Command queued, but its id could not be determined. Check the site's commands in Forge.";
                }

                try {
                    $poll = Poller::until(
                        fn () => $ctx->forge->command($org, $serverId, $siteId, $commandId),
                        fn (array $c) => $this->commandIsDone($c),
                    );

                    return $this->commandReport($ctx, $org, $serverId, $siteId, $commandId, $poll['result'], null);
                } catch (ForgeException $e) {
                    $ctx->summary = "Ran a command on site {$siteId}: queued, result check failed";

                    return "Command {$commandId} was queued, but checking its result failed: {$e->agentMessage()}. Call get_command_result with command_id {$commandId}.";
                } catch (Throwable) {
                    $ctx->summary = "Ran a command on site {$siteId}: queued, result check failed";

                    return "Command {$commandId} was queued, but checking its result failed unexpectedly. Call get_command_result with command_id {$commandId}.";
                }
            },
        );
    }

    /**
     * The new command: not in the pre-queue snapshot, same text, created within the window. Forge may
     * list it a moment late, so one retry after a short sleep.
     *
     * @param  list<int>  $known
     */
    private function findCommandId(ToolContext $ctx, string $org, int $serverId, int $siteId, string $command, Carbon $since, array $known): ?int
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($attempt > 0) {
                Sleep::for(1)->second();
            }

            $id = $this->matchNewCommand($ctx->forge->commands($org, $serverId, $siteId, 5), $command, $since, $known);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $records  newest first
     * @param  list<int>  $known
     */
    private function matchNewCommand(array $records, string $command, Carbon $since, array $known): ?int
    {
        foreach ($records as $record) {
            $id = is_numeric($record['id'] ?? null) ? (int) $record['id'] : 0;

            if ($id === 0 || in_array($id, $known, true) || ($record['command'] ?? null) !== $command) {
                continue;
            }

            try {
                $created = Carbon::parse((string) ($record['created_at'] ?? ''));
            } catch (Throwable) {
                continue;
            }

            if ($created->greaterThanOrEqualTo($since)) {
                return $id;
            }
        }

        return null;
    }
}
