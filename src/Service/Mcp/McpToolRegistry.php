<?php

namespace App\Service\Mcp;

use App\Entity\Environment;
use App\Entity\FlowGroup;
use App\Entity\FlowGroupRun;
use App\Entity\FlowRun;
use App\Entity\FlowStep;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Message\RunFlowGroupMessage;
use App\Message\RunFlowMessage;
use App\Repository\ApiRequestRepository;
use App\Repository\DbConnectionRepository;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowGroupRepository;
use App\Repository\FlowGroupRunRepository;
use App\Repository\FlowRunRepository;
use App\Repository\FlowStepRepository;
use App\Repository\TestFlowRepository;
use App\Service\Db\DbQueryRunner;
use App\Service\FlowExpressionParser;
use App\Service\FlowVariableScanner;
use App\Service\FlowRunner;
use App\Service\FlowRunReporter;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Defines and executes the MCP tools exposed to Claude. Everything is scoped to
 * the workspace of the authenticating API token, so Claude can only ever read or
 * change that workspace's data.
 */
class McpToolRegistry
{
    public function __construct(
        private readonly \App\Repository\ApiCollectionRepository $collections,
        private readonly ApiRequestRepository $requests,
        private readonly EnvironmentRepository $environments,
        private readonly DbConnectionRepository $dbConnections,
        private readonly TestFlowRepository $flows,
        private readonly FlowStepRepository $steps,
        private readonly FlowRunRepository $runs,
        private readonly FlowGroupRepository $groups,
        private readonly FlowGroupRunRepository $groupRuns,
        private readonly FlowRunner $runner,
        private readonly \App\Service\RunDiagnostics $diag,
        private readonly \App\Repository\DataFactoryRepository $dataFactories,
        private readonly \App\Service\DynamicVariableGenerator $dynamic,
        private readonly DbQueryRunner $dbQuery,
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
        private readonly FlowExpressionParser $parser,
        private readonly FlowVariableScanner $varScanner,
        private readonly FlowRunReporter $reporter,
        private readonly MessageBusInterface $bus,
        private readonly \App\Repository\ScheduleRepository $schedules,
        private readonly \App\Service\ScheduleCompiler $scheduleCompiler,
    ) {
    }

    /**
     * Tool definitions for tools/list (name, description, JSON Schema).
     *
     * @return array<int, array<string, mixed>>
     */
    public function definitions(): array
    {
        $strArray = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            ['name' => 'whoami', 'description' => 'Return the merchant and workspace this token is bound to, with resource counts. Every operation is limited to this merchant/workspace.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'list_collections', 'description' => 'List the collections in the workspace with their request counts.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'search_requests', 'description' => 'Search requests by name or URL; returns ids to use when adding flow steps.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Text to search for (empty = all)']]]],
            ['name' => 'list_environments', 'description' => 'List environments and their variable names (secret values are masked).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'list_db_connections', 'description' => 'List the database connections (no credentials are returned).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'list_data_factories', 'description' => 'List the workspace data factories (manageable {{$generator}} tokens) AND the built-in {{$guid}}/{{$randomEmail}}… generators, each with a sample value.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'create_data_factory', 'description' => 'Create a data factory — used as {{$name}}, producing a fresh value on every use. kind: oneOf {values:[...]} · template {template:"...{{$guid}}..."} · intRange {min,max} · pattern {pattern:"#### #### #### ####"} (#=digit A=A-Z a=a-z *=letter or digit).',
                'inputSchema' => ['type' => 'object', 'required' => ['name', 'kind', 'config'], 'properties' => [
                    'name' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['oneOf', 'template', 'intRange', 'pattern']],
                    'config' => ['type' => 'object'], 'description' => ['type' => 'string'],
                ]]],
            ['name' => 'delete_data_factory', 'description' => 'Delete a data factory by name.',
                'inputSchema' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]]],
            ['name' => 'db_schema', 'description' => 'Introspect the schema of a DB connection (SQL: tables; with table, its column names and types). Use it to see which tables and fields exist before writing a DB step. For Mongo/Redis use db_query instead.',
                'inputSchema' => ['type' => 'object', 'required' => ['connection'], 'properties' => [
                    'connection' => ['type' => 'string', 'description' => 'DB connection name or id'],
                    'table' => ['type' => 'string', 'description' => 'If given, returns that table\'s columns; if empty, returns the table list'],
                ]]],
            ['name' => 'db_query', 'description' => 'Run a READ-ONLY ad-hoc query on a DB connection and return the rows (SQL: SELECT/WITH/SHOW/EXPLAIN only; writes are blocked). Use it to see real values before writing accurate assertions. Result: {rowCount, rows[]}.',
                'inputSchema' => ['type' => 'object', 'required' => ['connection', 'query'], 'properties' => [
                    'connection' => ['type' => 'string', 'description' => 'DB connection name or id'],
                    'query' => ['type' => 'string', 'description' => 'Read-only query. {{variable}} placeholders are supported.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum number of rows to return (default 50)'],
                ]]],
            ['name' => 'list_flows', 'description' => 'List the test flows with their step counts.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'create_flow', 'description' => 'Create a new test flow; returns flowId.',
                'inputSchema' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'environmentName' => ['type' => 'string', 'description' => 'Default environment name'],
                    'stopOnFailure' => ['type' => 'boolean'],
                ]]],
            ['name' => 'update_flow', 'description' => 'Update a flow: rename, description, stopOnFailure, default environment. Only the fields you pass are changed.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'environmentName' => ['type' => 'string', 'description' => 'Default environment name'],
                    'stopOnFailure' => ['type' => 'boolean'],
                ]]],
            ['name' => 'update_step', 'description' => 'Update a step: its name, query/connection for a DB step, and its run-if CONDITION. Pass condition {left, op, right} to make the step run only when the condition holds (branching); pass null to drop the condition. op: eq/ne/contains/matches/gt/lt/ge/le/exists/empty/notEmpty. To edit an HTTP request, use set_step_request.',
                'inputSchema' => ['type' => 'object', 'required' => ['stepId'], 'properties' => [
                    'stepId' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'query' => ['type' => 'string', 'description' => 'DB step only: supports {{variable}}'],
                    'connection' => ['type' => 'string', 'description' => 'DB step only: connection name or id'],
                    'condition' => ['type' => ['object', 'null'], 'description' => '{left, op, right} — e.g. {"left":"{{provider}}","op":"eq","right":"Yuno"}. null = remove the condition.', 'properties' => [
                        'left' => ['type' => 'string'], 'op' => ['type' => 'string'], 'right' => ['type' => 'string'],
                    ]],
                    'loop' => ['type' => ['object', 'null'], 'description' => 'forEach loop {over, as}: over is an expression that resolves to an array ({{pkgs}}), as is the item variable. The step repeats for every item ({{as}}, or {{as.field}} for objects, plus {{as_index}}). null = remove the loop.', 'properties' => [
                        'over' => ['type' => 'string'], 'as' => ['type' => 'string'],
                    ]],
                ]]],
            ['name' => 'add_http_step', 'description' => 'Append an HTTP request step to a flow. extractions: ["var = json.path"], assertions: ["status == 200", "data.id exists"].',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'requestId'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'requestId' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'extractions' => $strArray,
                    'assertions' => $strArray,
                ]]],
            ['name' => 'add_db_step', 'description' => 'Append a DB verification step to a flow. query supports {{variable}}. Example assertions: ["rowCount == 1", "rows.0.status == active"].',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'connection', 'query'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'connection' => ['type' => 'string', 'description' => 'DB connection name or id'],
                    'query' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'extractions' => $strArray,
                    'assertions' => $strArray,
                ]]],
            ['name' => 'add_call_step', 'description' => 'Append a SUB-FLOW call step to a flow: the other flow\'s steps run inline in this run, sharing the same variable context (by reference — always its current version). Circular calls are blocked automatically at run time. Use it to define a repeated block once (login, start subscription) and call it from every flow.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'calledFlow'], 'properties' => [
                    'flowId' => ['type' => 'string', 'description' => 'Id of the parent flow the step is added to'],
                    'calledFlow' => ['type' => 'string', 'description' => 'Id or name of the sub-flow to call'],
                    'name' => ['type' => 'string'],
                ]]],
            ['name' => 'create_flow_from_collection', 'description' => 'Build a flow with ordered HTTP steps from a collection\'s requests in one call. Without requestIds, every request in the collection is added in order; with requestIds, only those requests, in the given order.',
                'inputSchema' => ['type' => 'object', 'required' => ['collectionId', 'name'], 'properties' => [
                    'collectionId' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'requestIds' => array_merge($strArray, ['description' => 'Ids of the requests to add, in order. Empty = every request in the collection.']),
                    'environmentName' => ['type' => 'string'],
                    'stopOnFailure' => ['type' => 'boolean'],
                ]]],
            ['name' => 'add_setvar_step', 'description' => 'Append a variable-assignment step to a flow. assignments: ["orderId = {{data.id}}", "label = literal"]. Values resolve {{variable}} and {{$randomEmail}} tokens.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'assignments'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'assignments' => $strArray,
                    'name' => ['type' => 'string'],
                ]]],
            ['name' => 'add_delay_step', 'description' => 'Append a wait step to a flow (delay between asynchronous operations).',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'ms'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'ms' => ['type' => 'integer', 'description' => 'Wait duration (ms, max 60000)'],
                    'name' => ['type' => 'string'],
                ]]],
            ['name' => 'get_flow', 'description' => 'Return a flow in detail: all its steps, extractions/assertions, retry settings and the external variables it expects.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId'], 'properties' => ['flowId' => ['type' => 'string']]]],
            ['name' => 'get_flow_variables', 'description' => 'Return the variables a flow expects to be supplied FROM OUTSIDE (collected from the {{variable}} tokens used in its steps, excluding the ones the flow produces itself and the dynamic ones). For each one, reports whether an environment provides it and with what value. Call it before run_flow/run_suite to know which values to pass.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId'], 'properties' => ['flowId' => ['type' => 'string']]]],
            ['name' => 'run_flow', 'description' => 'Run a flow SYNCHRONOUSLY, wait for it to finish and return the step-by-step result including assertion outcomes. Use for short flows.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'environmentName' => ['type' => 'string'],
                    'variables' => ['type' => 'object', 'description' => 'One-off variables {name: value}'],
                ]]],
            ['name' => 'run_flow_async', 'description' => 'Start a flow IN THE BACKGROUND and return runId immediately (without waiting). Use for long or polling flows. Track progress with list_runs or get_run.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'environmentName' => ['type' => 'string'],
                    'variables' => ['type' => 'object'],
                ]]],
            ['name' => 'list_runs', 'description' => 'List recent runs (status, passed steps, duration, date). With flowId, only that flow\'s runs; without it, the whole workspace.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum records (default 10)'],
                ]]],
            ['name' => 'get_run', 'description' => 'Return a run in detail.',
                'inputSchema' => ['type' => 'object', 'required' => ['runId'], 'properties' => ['runId' => ['type' => 'string']]]],
            ['name' => 'reset_contract_baseline', 'description' => 'Reset a step\'s contract baseline (its recorded response shape); it is captured again on the next successful run. Use it to clear a drift warning after an intentional API change.',
                'inputSchema' => ['type' => 'object', 'required' => ['stepId'], 'properties' => ['stepId' => ['type' => 'string']]]],
            ['name' => 'diagnose_run', 'description' => 'Return raw evidence to DIAGNOSE a failed run: for every failing step, the request sent, the ACTUAL response body, which assertion mismatched with its expected vs actual value, the extracted variables and the error. Pass runId, or pass flowId to take that flow\'s latest run. Interpret the result and explain why it broke; where needed, propose a fix via update_step/set_step_request/set_step_checks and check DB state with db_query.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'runId' => ['type' => 'string'],
                    'flowId' => ['type' => 'string', 'description' => 'Without runId, this flow\'s latest run is diagnosed'],
                ]]],
            ['name' => 'delete_flow', 'description' => 'Delete a flow and its entire run history.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId'], 'properties' => ['flowId' => ['type' => 'string']]]],
            ['name' => 'delete_step', 'description' => 'Delete a flow step.',
                'inputSchema' => ['type' => 'object', 'required' => ['stepId'], 'properties' => ['stepId' => ['type' => 'string']]]],

            // ---- request building & wiring (chain requests without the panel) ----
            ['name' => 'add_request_step', 'description' => 'Append an HTTP request step built FROM SCRATCH to a flow (no collection needed). headers/params: [{"name":"x","value":"y"}]. {{variable}} tokens work in the body and in every field. Use extractions to pull values out of the response for later steps, and assertions to verify it. Returns stepId.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'method', 'url'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'method' => ['type' => 'string', 'description' => 'GET|POST|PUT|PATCH|DELETE'],
                    'url' => ['type' => 'string', 'description' => 'Variables may be used, e.g. {{API_URL}}/...'],
                    'headers' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'params' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'bodyMode' => ['type' => 'string', 'description' => 'none|raw|json|form'],
                    'body' => ['type' => 'string'],
                    'auth' => ['type' => 'object', 'description' => 'e.g. {type:bearer, token:"{{token}}"}'],
                    'extractions' => $strArray,
                    'assertions' => $strArray,
                ]]],
            ['name' => 'set_step_request', 'description' => 'Update a step\'s own (flow-specific) request fields — this is how you chain steps: put a previous step\'s output into the body, URL or headers as {{var}}. Only the fields you pass are changed.',
                'inputSchema' => ['type' => 'object', 'required' => ['stepId'], 'properties' => [
                    'stepId' => ['type' => 'string'],
                    'method' => ['type' => 'string'], 'url' => ['type' => 'string'],
                    'headers' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'params' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'bodyMode' => ['type' => 'string'], 'body' => ['type' => 'string'],
                    'auth' => ['type' => 'object'],
                ]]],
            ['name' => 'add_extraction', 'description' => 'Extract a value from a step\'s response (var <- json path) so later steps can use it as {{var}} — this is how requests are chained. Example: var="token", path="result.profile.lastTransactionId".',
                'inputSchema' => ['type' => 'object', 'required' => ['stepId', 'var', 'path'], 'properties' => [
                    'stepId' => ['type' => 'string'], 'var' => ['type' => 'string'], 'path' => ['type' => 'string'],
                ]]],
            ['name' => 'set_step_checks', 'description' => 'Set a step\'s extractions, assertions and/or JSON schema validation (anything you omit is left unchanged). schema: a JSON Schema object (type/properties/required/items) — the step fails when the response does not match; pass null or an empty value to remove the schema.',
                'inputSchema' => ['type' => 'object', 'required' => ['stepId'], 'properties' => [
                    'stepId' => ['type' => 'string'], 'extractions' => $strArray, 'assertions' => $strArray,
                    'schema' => ['type' => ['object', 'null'], 'description' => 'JSON Schema object; null = remove'],
                ]]],
            ['name' => 'set_flow_order', 'description' => 'Set the execution order of the steps (and the canvas edges). stepIds: step ids in the order they should run.',
                'inputSchema' => ['type' => 'object', 'required' => ['flowId', 'stepIds'], 'properties' => [
                    'flowId' => ['type' => 'string'], 'stepIds' => $strArray,
                ]]],

            // ---- suites (run many flows together) ----
            ['name' => 'list_suites', 'description' => 'List the suites (flow groups), the flows inside them and their last run status.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'create_suite', 'description' => 'Create a new suite (flow group); returns suiteId.',
                'inputSchema' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'description' => ['type' => 'string']]]],
            ['name' => 'update_suite', 'description' => 'Update a suite: rename and/or change its description. Only the field you pass is changed.',
                'inputSchema' => ['type' => 'object', 'required' => ['suiteId'], 'properties' => ['suiteId' => ['type' => 'string'], 'name' => ['type' => 'string'], 'description' => ['type' => 'string']]]],
            ['name' => 'add_flow_to_suite', 'description' => 'Add a flow to a suite (at the end). A flow may belong to several suites; this does not affect its other memberships.',
                'inputSchema' => ['type' => 'object', 'required' => ['suiteId', 'flowId'], 'properties' => ['suiteId' => ['type' => 'string'], 'flowId' => ['type' => 'string']]]],
            ['name' => 'remove_flow_from_suite', 'description' => 'Remove a flow from the given suite (the flow itself and its other suite memberships are kept).',
                'inputSchema' => ['type' => 'object', 'required' => ['suiteId', 'flowId'], 'properties' => ['suiteId' => ['type' => 'string'], 'flowId' => ['type' => 'string']]]],
            ['name' => 'run_suite', 'description' => 'Run every flow in the suite IN THE BACKGROUND, one after another; returns batchId. Track the status with get_suite_run.',
                'inputSchema' => ['type' => 'object', 'required' => ['suiteId'], 'properties' => ['suiteId' => ['type' => 'string'], 'environmentName' => ['type' => 'string']]]],
            ['name' => 'get_suite_run', 'description' => 'Return the status of a suite run (passed/failed/running per flow).',
                'inputSchema' => ['type' => 'object', 'required' => ['suiteId', 'batchId'], 'properties' => ['suiteId' => ['type' => 'string'], 'batchId' => ['type' => 'string']]]],
            ['name' => 'delete_suite', 'description' => 'Delete a suite (flow group). The flows inside it are not deleted, they just leave the group.',
                'inputSchema' => ['type' => 'object', 'required' => ['suiteId'], 'properties' => ['suiteId' => ['type' => 'string']]]],

            ['name' => 'list_schedules', 'description' => 'List the workspace\'s schedules: what each one runs (a flow or a suite), its timing rules in plain words, whether it is active, its timezone, and the next and last run.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'create_schedule', 'description' => 'Schedule a flow or a suite to run by itself. Give EITHER flowId OR suiteId. A schedule holds a LIST of rules, so "Mondays every hour and Tuesdays every two hours" is one schedule with two rules. The smallest unit is a minute. Times are read in the schedule\'s timezone.',
                'inputSchema' => ['type' => 'object', 'required' => ['rules'], 'properties' => [
                    'flowId' => ['type' => 'string'],
                    'suiteId' => ['type' => 'string'],
                    'name' => ['type' => 'string', 'description' => 'Defaults to the target\'s name'],
                    'timezone' => ['type' => 'string', 'description' => 'IANA name, e.g. Europe/Istanbul (default)'],
                    'environmentName' => ['type' => 'string', 'description' => 'Override the target\'s own environment'],
                    'enabled' => ['type' => 'boolean'],
                    'rules' => ['type' => 'array', 'description' => 'Timing rules; the schedule fires when ANY rule matches.', 'items' => ['type' => 'object', 'properties' => [
                    'mode' => ['type' => 'string', 'enum' => ['at', 'every', 'cron'], 'description' => 'at = fixed times of day · every = a repeating interval · cron = a raw 5-field expression'],
                    'at' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'mode=at: times as HH:MM, e.g. ["09:00","18:30"]'],
                    'n' => ['type' => 'integer', 'description' => 'mode=every: the interval, e.g. 2'],
                    'unit' => ['type' => 'string', 'enum' => ['minute', 'hour'], 'description' => 'mode=every: minute is the smallest unit the scheduler ticks at'],
                    'from' => ['type' => 'string', 'description' => 'mode=every, optional: only inside this window, HH:MM'],
                    'to' => ['type' => 'string', 'description' => 'mode=every, optional: window end, HH:MM'],
                    'expr' => ['type' => 'string', 'description' => 'mode=cron: e.g. */5 * * * *'],
                    'days' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Days of the week, 1=Mon … 7=Sun; omit or empty for every day'],
                    'monthDays' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Days of the month 1-31; omit or empty for every day'],
                ]]],
                ]]],
            ['name' => 'update_schedule', 'description' => 'Change a schedule. Anything you omit is left alone; pass rules to replace the whole rule list.',
                'inputSchema' => ['type' => 'object', 'required' => ['scheduleId'], 'properties' => [
                    'scheduleId' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'enabled' => ['type' => 'boolean'],
                    'timezone' => ['type' => 'string'],
                    'environmentName' => ['type' => 'string'],
                    'flowId' => ['type' => 'string'],
                    'suiteId' => ['type' => 'string'],
                    'rules' => ['type' => 'array', 'description' => 'Timing rules; the schedule fires when ANY rule matches.', 'items' => ['type' => 'object', 'properties' => [
                    'mode' => ['type' => 'string', 'enum' => ['at', 'every', 'cron'], 'description' => 'at = fixed times of day · every = a repeating interval · cron = a raw 5-field expression'],
                    'at' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'mode=at: times as HH:MM, e.g. ["09:00","18:30"]'],
                    'n' => ['type' => 'integer', 'description' => 'mode=every: the interval, e.g. 2'],
                    'unit' => ['type' => 'string', 'enum' => ['minute', 'hour'], 'description' => 'mode=every: minute is the smallest unit the scheduler ticks at'],
                    'from' => ['type' => 'string', 'description' => 'mode=every, optional: only inside this window, HH:MM'],
                    'to' => ['type' => 'string', 'description' => 'mode=every, optional: window end, HH:MM'],
                    'expr' => ['type' => 'string', 'description' => 'mode=cron: e.g. */5 * * * *'],
                    'days' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Days of the week, 1=Mon … 7=Sun; omit or empty for every day'],
                    'monthDays' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Days of the month 1-31; omit or empty for every day'],
                ]]],
                ]]],
            ['name' => 'delete_schedule', 'description' => 'Delete a schedule. What it pointed at (the flow or suite) is not touched.',
                'inputSchema' => ['type' => 'object', 'required' => ['scheduleId'], 'properties' => ['scheduleId' => ['type' => 'string']]]],
        ];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed> Structured result
     */
    public function call(string $name, array $args, Workspace $ws): array
    {
        return match ($name) {
            'whoami' => $this->whoami($ws),
            'list_collections' => $this->listCollections($ws),
            'search_requests' => $this->searchRequests($ws, (string) ($args['query'] ?? '')),
            'list_environments' => $this->listEnvironments($ws),
            'list_db_connections' => $this->listDbConnections($ws),
            'list_data_factories' => $this->listDataFactories($ws),
            'create_data_factory' => $this->createDataFactory($ws, $args),
            'delete_data_factory' => $this->deleteDataFactory($ws, $args),
            'db_schema' => $this->dbSchema($ws, $args),
            'db_query' => $this->dbQueryTool($ws, $args),
            'list_flows' => $this->listFlows($ws),
            'create_flow' => $this->createFlow($ws, $args),
            'update_flow' => $this->updateFlow($ws, $args),
            'update_step' => $this->updateStep($ws, $args),
            'create_flow_from_collection' => $this->createFlowFromCollection($ws, $args),
            'add_http_step' => $this->addHttpStep($ws, $args),
            'add_db_step' => $this->addDbStep($ws, $args),
            'add_call_step' => $this->addCallStep($ws, $args),
            'add_setvar_step' => $this->addSetvarStep($ws, $args),
            'add_delay_step' => $this->addDelayStep($ws, $args),
            'get_flow' => $this->getFlow($ws, $args),
            'get_flow_variables' => $this->getFlowVariables($ws, $args),
            'run_flow' => $this->runFlow($ws, $args),
            'run_flow_async' => $this->runFlowAsync($ws, $args),
            'list_runs' => $this->listRuns($ws, $args),
            'get_run' => $this->getRun($ws, $args),
            'diagnose_run' => $this->diagnoseRun($ws, $args),
            'reset_contract_baseline' => $this->resetContractBaseline($ws, $args),
            'delete_flow' => $this->deleteFlow($ws, $args),
            'delete_step' => $this->deleteStep($ws, $args),
            'add_request_step' => $this->addRequestStep($ws, $args),
            'set_step_request' => $this->setStepRequest($ws, $args),
            'add_extraction' => $this->addExtraction($ws, $args),
            'set_step_checks' => $this->setStepChecks($ws, $args),
            'set_flow_order' => $this->setFlowOrder($ws, $args),
            'list_suites' => $this->listSuites($ws),
            'create_suite' => $this->createSuite($ws, $args),
            'update_suite' => $this->updateSuite($ws, $args),
            'add_flow_to_suite' => $this->addFlowToSuite($ws, $args),
            'remove_flow_from_suite' => $this->removeFlowFromSuite($ws, $args),
            'run_suite' => $this->runSuite($ws, $args),
            'get_suite_run' => $this->getSuiteRun($ws, $args),
            'delete_suite' => $this->deleteSuite($ws, $args),
            'list_schedules' => $this->listSchedules($ws),
            'create_schedule' => $this->createSchedule($ws, $args),
            'update_schedule' => $this->updateSchedule($ws, $args),
            'delete_schedule' => $this->deleteSchedule($ws, $args),
            default => throw new \InvalidArgumentException("Unknown tool: $name"),
        };
    }

    private function listCollections(Workspace $ws): array
    {
        $out = [];
        foreach ($this->collections->findByWorkspace($ws) as $c) {
            $out[] = ['id' => (string) $c->getId(), 'name' => $c->getName(), 'requests' => $c->getRequests()->count()];
        }

        return ['collections' => $out];
    }

    private function searchRequests(Workspace $ws, string $query): array
    {
        $query = mb_strtolower(trim($query));
        $out = [];
        foreach ($this->requests->findByWorkspace($ws) as $r) {
            $hay = mb_strtolower($r->getName() . ' ' . $r->getUrl() . ' ' . $r->getMethod());
            if ('' === $query || str_contains($hay, $query)) {
                $out[] = [
                    'id' => (string) $r->getId(),
                    'name' => $r->getName(),
                    'method' => $r->getMethod(),
                    'url' => $r->getUrl(),
                    'collection' => $r->getCollection()->getName(),
                ];
            }
        }

        return ['requests' => $out];
    }

    private function listEnvironments(Workspace $ws): array
    {
        $out = [];
        foreach ($this->environments->findByWorkspace($ws) as $e) {
            $vars = [];
            foreach ($e->getVariables() as $v) {
                $vars[] = ['name' => $v->getName(), 'value' => $v->isSecret() ? '••• (secret)' : $v->getValue()];
            }
            $out[] = ['id' => (string) $e->getId(), 'name' => $e->getName(), 'variables' => $vars];
        }

        return ['environments' => $out];
    }

    private function listDbConnections(Workspace $ws): array
    {
        $out = [];
        foreach ($this->dbConnections->findByWorkspace($ws) as $c) {
            $out[] = ['id' => (string) $c->getId(), 'name' => $c->getName(), 'type' => $c->getType(), 'host' => $c->getHost()];
        }

        return ['dbConnections' => $out];
    }

    private function listFlows(Workspace $ws): array
    {
        $out = [];
        foreach ($this->flows->findByWorkspace($ws) as $f) {
            $last = $f->getRuns()->first();
            $out[] = [
                'id' => (string) $f->getId(),
                'name' => $f->getName(),
                'steps' => $f->getSteps()->count(),
                'lastStatus' => $last ? $last->getStatus() : null,
            ];
        }

        return ['flows' => $out];
    }

    private function createFlow(Workspace $ws, array $args): array
    {
        if (empty($args['name'])) {
            throw new \InvalidArgumentException('name is required.');
        }

        $flow = new TestFlow();
        $flow->setWorkspace($ws);
        $flow->setName((string) $args['name']);
        $flow->setDescription(isset($args['description']) ? (string) $args['description'] : null);
        $flow->setStopOnFailure((bool) ($args['stopOnFailure'] ?? true));
        if (!empty($args['environmentName'])) {
            $flow->setDefaultEnvironment($this->findEnvironmentByName($ws, (string) $args['environmentName']));
        }
        $this->flows->save($flow);

        return ['flowId' => (string) $flow->getId(), 'name' => $flow->getName()];
    }

    private function updateFlow(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        if (isset($args['name'])) {
            $name = trim((string) $args['name']);
            if ('' === $name) {
                throw new \InvalidArgumentException('name cannot be empty.');
            }
            $flow->setName($name);
        }
        if (\array_key_exists('description', $args)) {
            $flow->setDescription(null === $args['description'] ? null : (string) $args['description']);
        }
        if (\array_key_exists('stopOnFailure', $args)) {
            $flow->setStopOnFailure((bool) $args['stopOnFailure']);
        }
        if (\array_key_exists('environmentName', $args)) {
            $flow->setDefaultEnvironment(
                empty($args['environmentName']) ? null : $this->findEnvironmentByName($ws, (string) $args['environmentName'])
            );
        }
        $this->flows->save($flow);

        return ['ok' => true, 'flowId' => (string) $flow->getId(), 'name' => $flow->getName()];
    }

    private function updateStep(Workspace $ws, array $args): array
    {
        $step = $this->requireStep($ws, (string) ($args['stepId'] ?? ''));
        if (isset($args['name'])) {
            $name = trim((string) $args['name']);
            if ('' === $name) {
                throw new \InvalidArgumentException('name cannot be empty.');
            }
            $step->setName($name);
        }
        if (isset($args['query']) || isset($args['connection'])) {
            if (FlowStep::TYPE_DB !== $step->getType()) {
                throw new \InvalidArgumentException('query/connection are only valid on a DB step.');
            }
            if (isset($args['query'])) {
                $step->setQuery((string) $args['query']);
            }
            if (isset($args['connection'])) {
                $step->setDbConnection($this->findConnection($ws, (string) $args['connection']));
            }
        }
        if (\array_key_exists('condition', $args)) {
            $step->setCondition($this->normalizeCondition($args['condition']));
        }
        if (\array_key_exists('loop', $args)) {
            $step->setLoop($this->normalizeLoop($args['loop']));
        }
        $this->steps->save($step);

        return ['ok' => true, 'stepId' => (string) $step->getId(), 'name' => $step->getName(), 'condition' => $step->getCondition(), 'loop' => $step->getLoop()];
    }

    /**
     * @return array{over: string, as: string}|null
     */
    private function normalizeLoop(mixed $l): ?array
    {
        if (!\is_array($l)) {
            return null;
        }
        $over = trim((string) ($l['over'] ?? ''));
        if ('' === $over) {
            return null;
        }

        return ['over' => $over, 'as' => trim((string) ($l['as'] ?? 'item')) ?: 'item'];
    }

    /**
     * @return array{left: string, op: string, right: string}|null
     */
    private function normalizeCondition(mixed $c): ?array
    {
        if (!\is_array($c)) {
            return null;
        }
        $left = trim((string) ($c['left'] ?? ''));
        if ('' === $left) {
            return null;
        }
        $op = (string) ($c['op'] ?? 'eq');
        $allowed = ['eq', 'ne', 'contains', 'matches', 'gt', 'lt', 'ge', 'le', 'exists', 'empty', 'notEmpty'];

        return [
            'left' => $left,
            'op' => \in_array($op, $allowed, true) ? $op : 'eq',
            'right' => (string) ($c['right'] ?? ''),
        ];
    }

    private function addHttpStep(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $request = $this->requests->find((string) ($args['requestId'] ?? ''));
        if (null === $request || $request->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Request not found.');
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_HTTP);
        $step->setApiRequest($request);
        $step->copyRequestFrom($request);
        $step->setName((string) ($args['name'] ?? $request->getName()));
        $step->setPosition($this->nextPosition($flow));
        $step->setExtractions($this->parser->parseExtractions($this->joinLines($args['extractions'] ?? [])));
        $step->setAssertions($this->parser->parseAssertions($this->joinLines($args['assertions'] ?? [])));
        $this->steps->save($step);

        return ['stepId' => (string) $step->getId(), 'position' => $step->getPosition()];
    }

    private function addDbStep(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $connection = $this->findConnection($ws, (string) ($args['connection'] ?? ''));

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_DB);
        $step->setDbConnection($connection);
        $step->setQuery((string) ($args['query'] ?? ''));
        $step->setName((string) ($args['name'] ?? 'DB: ' . $connection->getName()));
        $step->setPosition($this->nextPosition($flow));
        $step->setExtractions($this->parser->parseExtractions($this->joinLines($args['extractions'] ?? [])));
        $step->setAssertions($this->parser->parseAssertions($this->joinLines($args['assertions'] ?? [])));
        $this->steps->save($step);

        return ['stepId' => (string) $step->getId(), 'position' => $step->getPosition()];
    }

    private function addCallStep(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $called = $this->resolveFlow($ws, (string) ($args['calledFlow'] ?? ''));
        if ($called->getId()?->toRfc4122() === $flow->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('A flow cannot call itself.');
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_CALL);
        $step->setCalledFlow($called);
        $step->setName(trim((string) ($args['name'] ?? '')) ?: '↳ ' . $called->getName());
        $step->setPosition($this->nextPosition($flow));
        $this->steps->save($step);

        return ['stepId' => (string) $step->getId(), 'position' => $step->getPosition(), 'calls' => $called->getName()];
    }

    private function runFlow(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        if ($flow->getSteps()->isEmpty()) {
            throw new \InvalidArgumentException('Flow has no steps.');
        }

        $environment = $flow->getDefaultEnvironment();
        if (!empty($args['environmentName'])) {
            $environment = $this->findEnvironmentByName($ws, (string) $args['environmentName']);
        }

        $run = $this->runner->run($flow, $environment, 'mcp', $this->scalarVars($args['variables'] ?? []), $this->actor());

        return $this->reporter->toArray($run);
    }

    /** The user the MCP bearer token belongs to — the actor behind MCP-triggered runs. */
    private function actor(): ?\App\Entity\User
    {
        $user = $this->security->getUser();

        return $user instanceof \App\Entity\User ? $user : null;
    }

    private function runFlowAsync(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        if ($flow->getSteps()->isEmpty()) {
            throw new \InvalidArgumentException('Flow has no steps.');
        }

        $environment = $flow->getDefaultEnvironment();
        if (!empty($args['environmentName'])) {
            $environment = $this->findEnvironmentByName($ws, (string) $args['environmentName']);
        }

        $run = $this->runner->createRun($flow, $environment, 'mcp', null, 0, [], $this->actor());
        $this->bus->dispatch(new RunFlowMessage(
            (string) $run->getId(),
            (string) $flow->getId(),
            $environment ? (string) $environment->getId() : null,
            $this->scalarVars($args['variables'] ?? []),
        ));

        return [
            'runId' => (string) $run->getId(),
            'status' => $run->getStatus(),
            'totalSteps' => $run->getTotalSteps(),
            'hint' => 'Started in the background. Use get_run or list_runs to follow the progress.',
        ];
    }

    private function listRuns(Workspace $ws, array $args): array
    {
        $limit = max(1, min(50, (int) ($args['limit'] ?? 10)));
        if (!empty($args['flowId'])) {
            $flow = $this->requireFlow($ws, (string) $args['flowId']);
            $list = $this->runs->recentForFlow($flow, $limit);
        } else {
            $list = $this->runs->recentForWorkspace($ws, $limit);
        }

        $out = [];
        foreach ($list as $r) {
            $out[] = [
                'runId' => (string) $r->getId(),
                'flow' => $r->getFlow()->getName(),
                'status' => $r->getStatus(),
                'passed' => $r->getPassedSteps(),
                'total' => $r->getTotalSteps(),
                'durationMs' => $r->getDurationMs(),
                'trigger' => $r->getTrigger(),
                'createdAt' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return ['runs' => $out];
    }

    private function getRun(Workspace $ws, array $args): array
    {
        $run = $this->runs->find((string) ($args['runId'] ?? ''));
        if (null === $run || $run->getFlow()->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Run not found.');
        }

        return $this->reporter->toArray($run);
    }

    private function diagnoseRun(Workspace $ws, array $args): array
    {
        $run = null;
        $runId = (string) ($args['runId'] ?? '');
        if ('' !== $runId) {
            $found = Uuid::isValid($runId) ? $this->runs->find($runId) : null;
            if (null === $found || $found->getFlow()->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
                throw new \InvalidArgumentException('Run not found.');
            }
            $run = $found;
        } elseif (!empty($args['flowId'])) {
            $flow = $this->requireFlow($ws, (string) $args['flowId']);
            $recent = $this->runs->recentForFlow($flow, 1);
            $run = $recent[0] ?? null;
            if (null === $run) {
                throw new \InvalidArgumentException('This flow has no runs yet.');
            }
        } else {
            throw new \InvalidArgumentException('runId or flowId is required.');
        }

        $evidence = $this->diag->evidence($run);
        $evidence['guidance'] = [] === $evidence['failingSteps']
            ? 'No step failed in this run (status: ' . $run->getStatus() . ').'
            : 'For every failingStep, inspect responseBody and failedAssertions.actual/expected; explain why it broke and, where needed, propose a fix via update_step (condition), set_step_request (request) or set_step_checks. If DB verification is needed, check the current state with db_query.';

        return $evidence;
    }

    private function resetContractBaseline(Workspace $ws, array $args): array
    {
        $step = $this->requireStep($ws, (string) ($args['stepId'] ?? ''));
        $step->setResponseShape(null);
        $step->setContractBaselineAt(null);
        $this->steps->save($step);

        return ['ok' => true, 'stepId' => (string) $step->getId(), 'note' => 'Baseline reset; it will be captured again on the next successful run.'];
    }

    // ---------------------------------------------------------- schedules

    /** @return array<string, mixed> */
    private function listSchedules(Workspace $ws): array
    {
        $out = [];
        foreach ($this->schedules->findByWorkspace($ws) as $s) {
            $next = $s->isEnabled() ? $this->scheduleCompiler->nextRun($s) : null;
            $out[] = [
                'id' => (string) $s->getId(),
                'name' => $s->getName(),
                'enabled' => $s->isEnabled(),
                'target' => $s->isSuite() ? 'suite' : 'flow',
                'targetId' => (string) ($s->getFlowGroup()?->getId() ?? $s->getFlow()?->getId()),
                'targetName' => $s->getTargetName(),
                'timezone' => $s->getTimezone(),
                'environment' => $s->getEnvironment()?->getName(),
                'rules' => $this->scheduleCompiler->describe($s),
                'cron' => $this->scheduleCompiler->scheduleCrons($s),
                'nextRunAt' => $next?->format(\DATE_ATOM),
                'lastRunAt' => $s->getLastRunAt()?->format(\DATE_ATOM),
            ];
        }

        return ['schedules' => $out];
    }

    /** @return array<string, mixed> */
    private function createSchedule(Workspace $ws, array $args): array
    {
        $schedule = new \App\Entity\Schedule();
        $schedule->setWorkspace($ws);

        $error = $this->applyScheduleTarget($ws, $schedule, $args);
        if (null !== $error) {
            return ['error' => $error];
        }
        if (!$schedule->hasTarget()) {
            return ['error' => 'Give either flowId or suiteId.'];
        }

        $rules = $this->normaliseRuleArgs($args['rules'] ?? []);
        if (!$rules) {
            return ['error' => 'rules must contain at least one usable rule.'];
        }
        $schedule->setRules($rules);

        $this->applyScheduleFields($ws, $schedule, $args);
        if ('' === $schedule->getName()) {
            $schedule->setName($schedule->getTargetName());
        }
        $this->schedules->save($schedule);

        return ['ok' => true, 'id' => (string) $schedule->getId()] + $this->scheduleSummary($schedule);
    }

    /** @return array<string, mixed> */
    private function updateSchedule(Workspace $ws, array $args): array
    {
        $schedule = $this->findSchedule($ws, (string) ($args['scheduleId'] ?? ''));
        if (null === $schedule) {
            return ['error' => 'Schedule not found in this workspace.'];
        }

        $error = $this->applyScheduleTarget($ws, $schedule, $args);
        if (null !== $error) {
            return ['error' => $error];
        }

        if (\array_key_exists('rules', $args)) {
            $rules = $this->normaliseRuleArgs($args['rules']);
            if (!$rules) {
                return ['error' => 'rules must contain at least one usable rule.'];
            }
            $schedule->setRules($rules);
        }

        $this->applyScheduleFields($ws, $schedule, $args);
        $this->schedules->save($schedule);

        return ['ok' => true] + $this->scheduleSummary($schedule);
    }

    /** @return array<string, mixed> */
    private function deleteSchedule(Workspace $ws, array $args): array
    {
        $schedule = $this->findSchedule($ws, (string) ($args['scheduleId'] ?? ''));
        if (null === $schedule) {
            return ['error' => 'Schedule not found in this workspace.'];
        }

        $name = $schedule->getName();
        $this->schedules->remove($schedule);

        return ['ok' => true, 'deleted' => $name];
    }

    private function findSchedule(Workspace $ws, string $id): ?\App\Entity\Schedule
    {
        if ('' === $id) {
            return null;
        }
        $schedule = $this->schedules->find($id);

        return $schedule && $schedule->getWorkspace()->getId()?->toRfc4122() === $ws->getId()?->toRfc4122() ? $schedule : null;
    }

    /** Returns an error string, or null when the target is unchanged or valid. */
    private function applyScheduleTarget(Workspace $ws, \App\Entity\Schedule $schedule, array $args): ?string
    {
        if (!empty($args['suiteId'])) {
            $group = $this->groups->find((string) $args['suiteId']);
            if (!$group || $group->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
                return 'Suite not found in this workspace.';
            }
            $schedule->setFlowGroup($group);

            return null;
        }

        if (!empty($args['flowId'])) {
            $flow = $this->flows->find((string) $args['flowId']);
            if (!$flow || $flow->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
                return 'Flow not found in this workspace.';
            }
            $schedule->setFlow($flow);
        }

        return null;
    }

    private function applyScheduleFields(Workspace $ws, \App\Entity\Schedule $schedule, array $args): void
    {
        if (isset($args['name']) && '' !== trim((string) $args['name'])) {
            $schedule->setName(trim((string) $args['name']));
        }
        if (\array_key_exists('enabled', $args)) {
            $schedule->setEnabled((bool) $args['enabled']);
        }
        if (!empty($args['timezone']) && \in_array((string) $args['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $schedule->setTimezone((string) $args['timezone']);
        }
        if (\array_key_exists('environmentName', $args)) {
            $name = trim((string) $args['environmentName']);
            $env = null;
            foreach ($this->environments->findByWorkspace($ws) as $candidate) {
                if (strcasecmp($candidate->getName(), $name) === 0) {
                    $env = $candidate;
                    break;
                }
            }
            $schedule->setEnvironment($env);
        }
    }

    /**
     * @param  mixed                       $raw
     * @return list<array<string, mixed>>
     */
    private function normaliseRuleArgs(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $rule = $this->scheduleCompiler->normaliseRule($entry);
            if (null !== $rule) {
                $out[] = $rule;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function scheduleSummary(\App\Entity\Schedule $schedule): array
    {
        $next = $schedule->isEnabled() ? $this->scheduleCompiler->nextRun($schedule) : null;

        return [
            'name' => $schedule->getName(),
            'target' => $schedule->getTargetName(),
            'timezone' => $schedule->getTimezone(),
            'rules' => $this->scheduleCompiler->describe($schedule),
            'cron' => $this->scheduleCompiler->scheduleCrons($schedule),
            'nextRunAt' => $next?->format(\DATE_ATOM),
        ];
    }

    private function whoami(Workspace $ws): array
    {
        $merchant = $ws->getMerchant();

        return [
            'merchant' => ['id' => (string) $merchant->getId(), 'name' => $merchant->getName()],
            'workspace' => ['id' => (string) $ws->getId(), 'name' => $ws->getName(), 'slug' => $ws->getSlug()],
            'counts' => [
                'collections' => \count($this->collections->findByWorkspace($ws)),
                'flows' => \count($this->flows->findByWorkspace($ws)),
                'environments' => \count($this->environments->findByWorkspace($ws)),
                'dbConnections' => \count($this->dbConnections->findByWorkspace($ws)),
                'schedules' => \count($this->schedules->findByWorkspace($ws)),
            ],
            'scope' => 'Every tool is limited to this workspace of this merchant; no other merchant or workspace data is reachable.',
        ];
    }

    private function createFlowFromCollection(Workspace $ws, array $args): array
    {
        if (empty($args['collectionId']) || empty($args['name'])) {
            throw new \InvalidArgumentException('collectionId and name are required.');
        }

        $collection = $this->collections->find((string) $args['collectionId']);
        if (null === $collection || $collection->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Collection not found.');
        }

        // Resolve the requests to include: explicit ordered ids, or all requests in collection order.
        $byId = [];
        foreach ($collection->getRequests() as $r) {
            $byId[(string) $r->getId()] = $r;
        }
        $requests = [];
        if (!empty($args['requestIds']) && \is_array($args['requestIds'])) {
            foreach ($args['requestIds'] as $rid) {
                $rid = (string) $rid;
                if (!isset($byId[$rid])) {
                    throw new \InvalidArgumentException("Request is not in this collection: $rid");
                }
                $requests[] = $byId[$rid];
            }
        } else {
            $requests = array_values($byId);
        }
        if ([] === $requests) {
            throw new \InvalidArgumentException('The collection has no requests to add.');
        }

        $flow = new TestFlow();
        $flow->setWorkspace($ws);
        $flow->setName((string) $args['name']);
        $flow->setDescription(isset($args['description']) ? (string) $args['description'] : null);
        $flow->setStopOnFailure((bool) ($args['stopOnFailure'] ?? true));
        if (!empty($args['environmentName'])) {
            $flow->setDefaultEnvironment($this->findEnvironmentByName($ws, (string) $args['environmentName']));
        }
        $this->flows->save($flow);

        $pos = 0;
        $added = [];
        foreach ($requests as $request) {
            $step = new FlowStep();
            $step->setFlow($flow);
            $step->setType(FlowStep::TYPE_HTTP);
            $step->setApiRequest($request);
            $step->copyRequestFrom($request);
            $step->setName($request->getName());
            $step->setPosition($pos++);
            $this->steps->save($step, false);
            $added[] = ['name' => $request->getName(), 'method' => $request->getMethod()];
        }
        $this->flows->save($flow); // flush the queued steps in one go

        return [
            'flowId' => (string) $flow->getId(),
            'name' => $flow->getName(),
            'steps' => \count($added),
            'addedFrom' => $collection->getName(),
            'hint' => 'The steps were added as HTTP requests. Optionally add DB verification with add_db_step, inspect the flow with get_flow, and run it with run_flow.',
        ];
    }

    private function addSetvarStep(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $assignments = $this->joinLines($args['assignments'] ?? []);
        if ('' === trim($assignments)) {
            throw new \InvalidArgumentException('assignments cannot be empty.');
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_SETVAR);
        $step->setQuery($assignments);
        $step->setName((string) ($args['name'] ?? 'Set variables'));
        $step->setPosition($this->nextPosition($flow));
        $this->steps->save($step);

        return ['stepId' => (string) $step->getId(), 'position' => $step->getPosition()];
    }

    private function addDelayStep(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $ms = max(0, min(60000, (int) ($args['ms'] ?? 0)));

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_DELAY);
        $step->setQuery((string) $ms);
        $step->setName((string) ($args['name'] ?? 'Wait ' . $ms . ' ms'));
        $step->setPosition($this->nextPosition($flow));
        $this->steps->save($step);

        return ['stepId' => (string) $step->getId(), 'position' => $step->getPosition(), 'delayMs' => $ms];
    }

    private function getFlow(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));

        $steps = [];
        foreach ($flow->getSteps() as $s) {
            $entry = [
                'stepId' => (string) $s->getId(),
                'position' => $s->getPosition(),
                'type' => $s->getType(),
                'name' => $s->getName(),
            ];
            if ($s->isDb()) {
                $entry['connection'] = $s->getDbConnection()?->getName();
                $entry['query'] = $s->getQuery();
            } elseif ($s->getApiRequest()) {
                $entry['request'] = $s->getApiRequest()->getMethod() . ' ' . $s->getApiRequest()->getUrl();
            } else {
                $entry['query'] = $s->getQuery();
            }
            if ($s->isCall()) {
                $entry['calls'] = $s->getCalledFlow()?->getName();
            }
            if (!$s->isSetvar() && !$s->isDelay() && !$s->isCall()) {
                $entry['extractions'] = $s->getExtractions();
                $entry['assertions'] = $s->getAssertions();
                if ($s->isRetryEnabled()) {
                    $entry['retry'] = ['max' => $s->getRetryMax(), 'delayMs' => $s->getRetryDelayMs()];
                }
            }
            if ($s->hasCondition()) {
                $entry['condition'] = $s->getCondition();
            }
            if ($s->hasLoop()) {
                $entry['loop'] = $s->getLoop();
            }
            $steps[] = $entry;
        }

        return [
            'id' => (string) $flow->getId(),
            'name' => $flow->getName(),
            'description' => $flow->getDescription(),
            'defaultEnvironment' => $flow->getDefaultEnvironment()?->getName(),
            'stopOnFailure' => $flow->isStopOnFailure(),
            'steps' => $steps,
            'variables' => $this->varScanner->externalVariables($flow),
        ];
    }

    private function getFlowVariables(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $vars = $this->varScanner->externalVariables($flow);
        $mustSupply = array_values(array_filter($vars, static fn (array $v): bool => !$v['fromEnv']));

        return [
            'flow' => $flow->getName(),
            'defaultEnvironment' => $flow->getDefaultEnvironment()?->getName(),
            'variables' => $vars,
            'mustSupply' => array_map(static fn (array $v): string => $v['name'], $mustSupply),
            'hint' => [] === $mustSupply
                ? 'Every variable comes from the environment; run_flow works without any extra variables.'
                : 'Pass the variables listed in mustSupply through the variables argument of run_flow.',
        ];
    }

    private function deleteFlow(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $name = $flow->getName();
        $this->flows->remove($flow);

        return ['deleted' => true, 'flow' => $name];
    }

    private function deleteStep(Workspace $ws, array $args): array
    {
        $step = $this->steps->find((string) ($args['stepId'] ?? ''));
        if (null === $step || $step->getFlow()->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Step not found.');
        }
        $this->steps->remove($step);

        return ['deleted' => true];
    }

    // ---- request building & wiring ----

    private function addRequestStep(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_HTTP);
        $step->setName((string) ($args['name'] ?? (strtoupper((string) ($args['method'] ?? 'GET')) . ' request')));
        $step->setPosition($this->nextPosition($flow));
        $step->setReqMethod(strtoupper((string) ($args['method'] ?? 'GET')));
        $step->setReqUrl((string) ($args['url'] ?? ''));
        $step->setReqHeaders($this->pairs($args['headers'] ?? []));
        $step->setReqParams($this->pairs($args['params'] ?? []));
        $mode = (string) ($args['bodyMode'] ?? (isset($args['body']) ? 'json' : 'none'));
        $step->setReqBodyMode(\in_array($mode, ['none', 'raw', 'json', 'form'], true) ? $mode : 'none');
        $step->setReqBody(isset($args['body']) ? (string) $args['body'] : null);
        $step->setReqAuth($this->sanitizeAuth($args['auth'] ?? []));
        $step->setExtractions($this->parser->parseExtractions($this->joinLines($args['extractions'] ?? [])));
        $step->setAssertions($this->parser->parseAssertions($this->joinLines($args['assertions'] ?? [])));
        $this->steps->save($step);

        return ['stepId' => (string) $step->getId(), 'position' => $step->getPosition()];
    }

    private function setStepRequest(Workspace $ws, array $args): array
    {
        $step = $this->requireStep($ws, (string) ($args['stepId'] ?? ''));
        if (FlowStep::TYPE_HTTP !== $step->getType()) {
            throw new \InvalidArgumentException('Only an HTTP step\'s request can be edited.');
        }
        if (isset($args['method'])) {
            $step->setReqMethod(strtoupper((string) $args['method']));
        }
        if (isset($args['url'])) {
            $step->setReqUrl((string) $args['url']);
        }
        if (isset($args['headers'])) {
            $step->setReqHeaders($this->pairs($args['headers']));
        }
        if (isset($args['params'])) {
            $step->setReqParams($this->pairs($args['params']));
        }
        if (isset($args['bodyMode'])) {
            $mode = (string) $args['bodyMode'];
            $step->setReqBodyMode(\in_array($mode, ['none', 'raw', 'json', 'form'], true) ? $mode : 'none');
        }
        if (\array_key_exists('body', $args)) {
            $step->setReqBody(null === $args['body'] ? null : (string) $args['body']);
        }
        if (isset($args['auth'])) {
            $step->setReqAuth($this->sanitizeAuth($args['auth']));
        }
        $this->steps->save($step);

        return ['ok' => true, 'stepId' => (string) $step->getId()];
    }

    private function addExtraction(Workspace $ws, array $args): array
    {
        $step = $this->requireStep($ws, (string) ($args['stepId'] ?? ''));
        $var = trim((string) ($args['var'] ?? ''));
        $path = trim((string) ($args['path'] ?? ''));
        if ('' === $var || '' === $path) {
            throw new \InvalidArgumentException('var and path are required.');
        }
        $ex = array_values(array_filter($step->getExtractions(), static fn (array $e): bool => ($e['var'] ?? null) !== $var));
        $ex[] = ['var' => $var, 'path' => $path];
        $step->setExtractions($ex);
        $this->steps->save($step);

        return ['ok' => true, 'extractions' => $step->getExtractions()];
    }

    private function setStepChecks(Workspace $ws, array $args): array
    {
        $step = $this->requireStep($ws, (string) ($args['stepId'] ?? ''));
        if (isset($args['extractions'])) {
            $step->setExtractions($this->parser->parseExtractions($this->joinLines($args['extractions'])));
        }
        if (isset($args['assertions']) || \array_key_exists('schema', $args)) {
            // Rebuild assertions, keeping the schema assertion separate from the text DSL.
            $asserts = isset($args['assertions'])
                ? $this->parser->parseAssertions($this->joinLines($args['assertions']))
                : array_values(array_filter($step->getAssertions(), static fn (array $a): bool => 'schema' !== ($a['kind'] ?? '')));

            if (\array_key_exists('schema', $args)) {
                $s = $args['schema'];
                $json = \is_array($s) ? (string) json_encode($s) : (\is_string($s) ? $s : '');
                if ('' !== trim($json) && \is_array(json_decode($json, true))) {
                    $asserts[] = ['kind' => 'schema', 'schema' => $json];
                }
            } else {
                foreach ($step->getAssertions() as $a) {
                    if ('schema' === ($a['kind'] ?? '')) {
                        $asserts[] = $a;
                        break;
                    }
                }
            }
            $step->setAssertions($asserts);
        }
        $this->steps->save($step);

        return ['ok' => true, 'extractions' => $step->getExtractions(), 'assertions' => $step->getAssertions()];
    }

    private function setFlowOrder(Workspace $ws, array $args): array
    {
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $byId = [];
        foreach ($flow->getSteps() as $s) {
            $byId[(string) $s->getId()] = $s;
        }
        $order = [];
        $pos = 0;
        $edges = [];
        $prev = null;
        foreach ((array) ($args['stepIds'] ?? []) as $sid) {
            $sid = (string) $sid;
            if (!isset($byId[$sid])) {
                throw new \InvalidArgumentException("Step is not in this flow: $sid");
            }
            $byId[$sid]->setPosition($pos++);
            $order[] = $sid;
            if (null !== $prev) {
                $edges[] = [$prev, $sid];
            }
            $prev = $sid;
        }
        $flow->setCanvasEdges($edges);
        $this->flows->save($flow);

        return ['ok' => true, 'order' => $order];
    }

    // ---- suites (flow groups) ----

    private function listSuites(Workspace $ws): array
    {
        $out = [];
        foreach ($this->groups->findByWorkspace($ws) as $g) {
            $flows = [];
            foreach ($g->getFlows() as $f) {
                $flows[] = ['id' => (string) $f->getId(), 'name' => $f->getName()];
            }
            $recent = $this->groupRuns->recentForGroup($g, 1);
            $last = $recent[0] ?? null;
            $out[] = [
                'id' => (string) $g->getId(),
                'name' => $g->getName(),
                'flows' => $flows,
                'lastRun' => null === $last ? null : ['batchId' => $last->getBatchId(), 'status' => $last->getStatus(), 'at' => $last->getCreatedAt()->format(\DateTimeInterface::ATOM)],
            ];
        }

        return ['suites' => $out];
    }

    private function createSuite(Workspace $ws, array $args): array
    {
        if (empty($args['name'])) {
            throw new \InvalidArgumentException('name is required.');
        }
        $group = new FlowGroup();
        $group->setWorkspace($ws);
        $group->setName((string) $args['name']);
        $group->setDescription(isset($args['description']) ? (string) $args['description'] : null);
        $this->groups->save($group);

        return ['suiteId' => (string) $group->getId(), 'name' => $group->getName()];
    }

    private function updateSuite(Workspace $ws, array $args): array
    {
        $suite = $this->requireSuite($ws, (string) ($args['suiteId'] ?? ''));
        if (isset($args['name'])) {
            $name = trim((string) $args['name']);
            if ('' === $name) {
                throw new \InvalidArgumentException('name cannot be empty.');
            }
            $suite->setName($name);
        }
        if (\array_key_exists('description', $args)) {
            $suite->setDescription(null === $args['description'] ? null : (string) $args['description']);
        }
        $this->groups->save($suite);

        return ['ok' => true, 'suiteId' => (string) $suite->getId(), 'name' => $suite->getName()];
    }

    private function addFlowToSuite(Workspace $ws, array $args): array
    {
        $suite = $this->requireSuite($ws, (string) ($args['suiteId'] ?? ''));
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $suite->addFlow($flow); // a flow may belong to many suites
        $this->groups->save($suite);

        return ['ok' => true, 'suite' => $suite->getName(), 'flowCount' => $suite->getFlows()->count()];
    }

    private function removeFlowFromSuite(Workspace $ws, array $args): array
    {
        $suite = $this->requireSuite($ws, (string) ($args['suiteId'] ?? ''));
        $flow = $this->requireFlow($ws, (string) ($args['flowId'] ?? ''));
        $suite->removeFlow($flow);
        $this->groups->save($suite);

        return ['ok' => true, 'suite' => $suite->getName(), 'flowCount' => $suite->getFlows()->count()];
    }

    private function runSuite(Workspace $ws, array $args): array
    {
        $suite = $this->requireSuite($ws, (string) ($args['suiteId'] ?? ''));
        if ($suite->getFlows()->isEmpty()) {
            throw new \InvalidArgumentException('The suite has no flows.');
        }
        $envId = null;
        if (!empty($args['environmentName'])) {
            $env = $this->findEnvironmentByName($ws, (string) $args['environmentName']);
            $envId = $env ? (string) $env->getId() : null;
        }

        $batchId = Uuid::v4()->toRfc4122();
        $groupRun = new FlowGroupRun();
        $groupRun->setFlowGroup($suite);
        $groupRun->setBatchId($batchId);
        $groupRun->setTotal($suite->getFlows()->count());
        $this->groupRuns->save($groupRun);

        $this->bus->dispatch(new RunFlowGroupMessage((string) $suite->getId(), $batchId, $envId));

        return ['batchId' => $batchId, 'status' => 'running', 'total' => $suite->getFlows()->count(),
            'hint' => 'Started in the background. Track the status with get_suite_run.'];
    }

    private function getSuiteRun(Workspace $ws, array $args): array
    {
        $suite = $this->requireSuite($ws, (string) ($args['suiteId'] ?? ''));
        $batchId = (string) ($args['batchId'] ?? '');
        $groupRun = $this->groupRuns->findOneByBatch($batchId);
        $flows = [];
        $done = 0;
        foreach ($this->runs->findByBatch($batchId) as $r) {
            if (!$suite->hasFlow($r->getFlow())) {
                continue;
            }
            if ('running' !== $r->getStatus()) {
                ++$done;
            }
            $flows[] = [
                'flow' => $r->getFlow()->getName(),
                'status' => $r->getStatus(),
                'passed' => $r->getPassedSteps(),
                'total' => $r->getTotalSteps(),
                'runId' => (string) $r->getId(),
            ];
        }

        return [
            'suite' => $suite->getName(),
            'status' => null === $groupRun ? 'unknown' : $groupRun->getStatus(),
            'total' => null === $groupRun ? \count($flows) : $groupRun->getTotal(),
            'done' => $done,
            'flows' => $flows,
        ];
    }

    private function deleteSuite(Workspace $ws, array $args): array
    {
        $suite = $this->requireSuite($ws, (string) ($args['suiteId'] ?? ''));
        $name = $suite->getName();
        // FK: flows are SET NULL (preserved), group runs CASCADE.
        $this->groups->remove($suite);

        return ['deleted' => true, 'suite' => $name];
    }

    // ---- data factories ----

    private function listDataFactories(Workspace $ws): array
    {
        $out = [];
        foreach ($this->dataFactories->findByWorkspace($ws) as $f) {
            $this->dynamic->setFactories([$f->getName() => ['kind' => $f->getKind(), 'config' => $f->getConfig()]]);
            $out[] = [
                'name' => $f->getName(),
                'token' => '{{$' . $f->getName() . '}}',
                'kind' => $f->getKind(),
                'config' => $f->getConfig(),
                'sample' => $this->dynamic->generate('$' . $f->getName()),
            ];
        }
        $this->dynamic->setFactories([]);

        return ['dataFactories' => $out, 'builtins' => $this->dynamic->builtins()];
    }

    private function createDataFactory(Workspace $ws, array $args): array
    {
        $name = preg_replace('/[^\w.\-]/', '', trim((string) ($args['name'] ?? ''))) ?? '';
        if ('' === $name) {
            throw new \InvalidArgumentException('A valid name is required (letters, digits, _ or .).');
        }
        $kind = (string) ($args['kind'] ?? '');
        if (!\in_array($kind, \App\Entity\DataFactory::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid kind. Use oneOf/template/intRange/pattern.');
        }

        $factory = new \App\Entity\DataFactory();
        $factory->setWorkspace($ws);
        $factory->setName($name);
        $factory->setKind($kind);
        $factory->setConfig(\is_array($args['config'] ?? null) ? $args['config'] : []);
        $factory->setDescription(isset($args['description']) ? (string) $args['description'] : null);

        try {
            $this->dataFactories->save($factory);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('A data factory with this name already exists: ' . $name);
        }

        $this->dynamic->setFactories([$name => ['kind' => $kind, 'config' => $factory->getConfig()]]);

        return ['ok' => true, 'name' => $name, 'token' => '{{$' . $name . '}}', 'sample' => $this->dynamic->generate('$' . $name)];
    }

    private function deleteDataFactory(Workspace $ws, array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));
        foreach ($this->dataFactories->findByWorkspace($ws) as $f) {
            if ($f->getName() === $name) {
                $this->dataFactories->remove($f);

                return ['deleted' => true, 'name' => $name];
            }
        }
        throw new \InvalidArgumentException('Data factory not found: ' . $name);
    }

    // ---- db introspection ----

    private function dbSchema(Workspace $ws, array $args): array
    {
        $conn = $this->findConnection($ws, (string) ($args['connection'] ?? ''));
        $type = $conn->getType();
        if (!\in_array($type, [\App\Entity\DbConnection::TYPE_POSTGRES, \App\Entity\DbConnection::TYPE_MYSQL], true)) {
            return ['connection' => $conn->getName(), 'type' => $type,
                'note' => 'Schema introspection is only available for SQL (postgres/mysql). For Mongo/Redis, fetch sample data with db_query.'];
        }

        $table = trim((string) ($args['table'] ?? ''));
        $isMysql = \App\Entity\DbConnection::TYPE_MYSQL === $type;

        if ('' === $table) {
            // List tables.
            $query = $isMysql
                ? 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
                : "SELECT table_name FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog','information_schema') ORDER BY table_name";
            $res = $this->dbQuery->run($conn, $query, []);
            if (!$res->ok) {
                throw new \InvalidArgumentException('Could not read the schema: ' . (string) $res->error);
            }
            $tables = array_map(static fn (array $r): string => (string) array_values($r)[0], $res->data['rows'] ?? []);

            return ['connection' => $conn->getName(), 'type' => $type, 'tables' => $tables,
                'hint' => 'Call again with the table parameter to see a table\'s columns.'];
        }

        // List columns of a table. Quote-strip to keep the literal safe.
        $safe = str_replace("'", '', $table);
        $schemaFilter = $isMysql ? 'AND table_schema = DATABASE()' : "AND table_schema NOT IN ('pg_catalog','information_schema')";
        $query = "SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = '$safe' $schemaFilter ORDER BY ordinal_position";
        $res = $this->dbQuery->run($conn, $query, []);
        if (!$res->ok) {
            throw new \InvalidArgumentException('Could not read the columns: ' . (string) $res->error);
        }
        $columns = array_map(static fn (array $r): array => [
            'name' => (string) ($r['column_name'] ?? ''),
            'type' => (string) ($r['data_type'] ?? ''),
            'nullable' => 'YES' === ($r['is_nullable'] ?? null),
        ], $res->data['rows'] ?? []);

        return ['connection' => $conn->getName(), 'type' => $type, 'table' => $table, 'columns' => $columns,
            'hint' => 'Assertion for add_db_step: "rows.0.' . ($columns[0]['name'] ?? 'column') . ' == ..."'];
    }

    private function dbQueryTool(Workspace $ws, array $args): array
    {
        $conn = $this->findConnection($ws, (string) ($args['connection'] ?? ''));
        $query = (string) ($args['query'] ?? '');
        if ('' === trim($query)) {
            throw new \InvalidArgumentException('query is required.');
        }
        // Read-only guard for SQL connections: reject any write/DDL statement.
        if (\in_array($conn->getType(), [\App\Entity\DbConnection::TYPE_POSTGRES, \App\Entity\DbConnection::TYPE_MYSQL], true)
            && preg_match('/\b(insert|update|delete|drop|alter|truncate|create|grant|revoke|replace|merge|call|do|set)\b/i', $query)) {
            throw new \InvalidArgumentException('db_query is read-only: SELECT/WITH/SHOW/EXPLAIN only. Use a flow DB step for writes.');
        }

        $res = $this->dbQuery->run($conn, $query, []);
        if (!$res->ok) {
            throw new \InvalidArgumentException('Query error: ' . (string) $res->error);
        }
        $limit = max(1, (int) ($args['limit'] ?? 50));
        $data = \is_array($res->data) ? $res->data : [];

        // SQL: {rowCount, rows[]}. Mongo: {count, documents[]}. Redis: {command, value, exists}.
        if (isset($data['rows'])) {
            $rows = (array) $data['rows'];

            return ['connection' => $conn->getName(), 'rowCount' => $data['rowCount'] ?? \count($rows),
                'rows' => array_slice($rows, 0, $limit), 'truncated' => \count($rows) > $limit];
        }
        if (isset($data['documents'])) {
            $docs = (array) $data['documents'];

            return ['connection' => $conn->getName(), 'count' => $data['count'] ?? \count($docs),
                'documents' => array_slice($docs, 0, $limit), 'truncated' => \count($docs) > $limit];
        }

        return ['connection' => $conn->getName()] + $data;
    }

    // ---- helpers ----

    /**
     * Normalises a {name: value} arg into a flat array<string,string> for FlowRunner.
     *
     * @return array<string, string>
     */
    private function scalarVars(mixed $vars): array
    {
        if (!\is_array($vars)) {
            return [];
        }
        $out = [];
        foreach ($vars as $k => $v) {
            $out[(string) $k] = \is_scalar($v) ? (string) $v : (string) json_encode($v);
        }

        return $out;
    }

    private function requireStep(Workspace $ws, string $id): FlowStep
    {
        $step = $this->steps->find($id);
        if (null === $step || $step->getFlow()->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Step not found.');
        }

        return $step;
    }

    private function requireSuite(Workspace $ws, string $id): FlowGroup
    {
        $suite = $this->groups->find($id);
        if (null === $suite || $suite->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Suite not found.');
        }

        return $suite;
    }

    /**
     * @param mixed $input array of {name,value} OR {name: value} object
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function pairs(mixed $input): array
    {
        $out = [];
        if (\is_array($input)) {
            foreach ($input as $k => $row) {
                if (\is_array($row)) {
                    $name = trim((string) ($row['name'] ?? ''));
                    if ('' !== $name) {
                        $out[] = ['name' => $name, 'value' => (string) ($row['value'] ?? '')];
                    }
                } elseif (\is_string($k) && \is_scalar($row)) {
                    $out[] = ['name' => $k, 'value' => (string) $row];
                }
            }
        }

        return $out;
    }

    /**
     * @param mixed $auth
     *
     * @return array<string, string>
     */
    private function sanitizeAuth(mixed $auth): array
    {
        if (!\is_array($auth)) {
            return [];
        }
        $out = [];
        foreach (['type', 'token', 'username', 'password', 'key', 'value', 'addTo'] as $f) {
            if (isset($auth[$f]) && \is_scalar($auth[$f])) {
                $out[$f] = (string) $auth[$f];
            }
        }

        return $out;
    }

    private function requireFlow(Workspace $ws, string $id): TestFlow
    {
        $flow = $this->flows->find($id);
        if (null === $flow || $flow->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('Flow not found.');
        }

        return $flow;
    }

    /**
     * Resolves a flow by id or (case-insensitive) name, scoped to the workspace.
     */
    private function resolveFlow(Workspace $ws, string $ref): TestFlow
    {
        $ref = trim($ref);
        if ('' === $ref) {
            throw new \InvalidArgumentException('calledFlow is required.');
        }
        if (Uuid::isValid($ref)) {
            $flow = $this->flows->find($ref);
            if (null !== $flow && $flow->getWorkspace()->getId()?->toRfc4122() === $ws->getId()?->toRfc4122()) {
                return $flow;
            }
        }
        foreach ($this->flows->findByWorkspace($ws) as $f) {
            if (0 === strcasecmp($f->getName(), $ref)) {
                return $f;
            }
        }
        throw new \InvalidArgumentException('Flow to call not found: ' . $ref);
    }

    private function findEnvironmentByName(Workspace $ws, string $name): ?Environment
    {
        foreach ($this->environments->findByWorkspace($ws) as $e) {
            if ($e->getName() === $name) {
                return $e;
            }
        }

        return null;
    }

    private function findConnection(Workspace $ws, string $ref): \App\Entity\DbConnection
    {
        // $ref may be a UUID or a connection name; only look up by id when it is a valid UUID.
        $conn = \Symfony\Component\Uid\Uuid::isValid($ref) ? $this->dbConnections->find($ref) : null;
        if (null === $conn) {
            foreach ($this->dbConnections->findByWorkspace($ws) as $c) {
                if ($c->getName() === $ref) {
                    $conn = $c;
                    break;
                }
            }
        }
        if (null === $conn || $conn->getWorkspace()->getId()?->toRfc4122() !== $ws->getId()?->toRfc4122()) {
            throw new \InvalidArgumentException('DB connection not found: ' . $ref);
        }

        return $conn;
    }

    private function nextPosition(TestFlow $flow): int
    {
        $max = -1;
        foreach ($flow->getSteps() as $s) {
            $max = max($max, $s->getPosition());
        }

        return $max + 1;
    }

    /**
     * @param mixed $lines string[] or string
     */
    private function joinLines(mixed $lines): string
    {
        if (\is_array($lines)) {
            return implode("\n", array_map('strval', $lines));
        }

        return (string) $lines;
    }
}
