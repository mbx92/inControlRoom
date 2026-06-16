<?php

namespace App\Http\Controllers;

use App\Models\AlertRule;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AlertRuleController extends Controller
{
    public function index(): Response
    {
        $rules = AlertRule::query()
            ->with('site')
            ->orderBy('rule_key')
            ->orderBy('site_id')
            ->get()
            ->map(fn (AlertRule $rule) => $this->presentRule($rule));

        return Inertia::render('Settings/AlertRules/Index', [
            'rules' => $rules,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Settings/AlertRules/Create', [
            'sites' => $this->siteOptions(),
            'availableRules' => AlertRule::RULES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateRule($request);
        $attributes = $this->ruleAttributes($validated);

        AlertRule::create($attributes);

        return redirect()->route('alert-rules.index')
            ->with('success', 'Alert rule created successfully.');
    }

    public function edit(AlertRule $alertRule): Response
    {
        $alertRule->load('site');

        return Inertia::render('Settings/AlertRules/Edit', [
            'rule' => $this->presentRule($alertRule, includeConfig: true),
            'sites' => $this->siteOptions(),
            'availableRules' => AlertRule::RULES,
        ]);
    }

    public function update(Request $request, AlertRule $alertRule)
    {
        $validated = $this->validateRule($request, $alertRule->id);
        $attributes = $this->ruleAttributes($validated);

        $alertRule->update($attributes);

        return redirect()->route('alert-rules.index')
            ->with('success', 'Alert rule updated successfully.');
    }

    private function validateRule(Request $request, ?int $ruleId = null): array
    {
        $validated = $request->validate([
            'site_id' => ['nullable', 'string', 'exists:sites,id'],
            'name' => ['required', 'string', 'max:255'],
            'rule_key' => [
                'required',
                'string',
                Rule::in(array_keys(AlertRule::RULES)),
                Rule::unique('alert_rules')
                    ->where(fn ($query) => $query->where('site_id', $request->input('site_id') ?: null))
                    ->ignore($ruleId),
            ],
            'warning_threshold' => ['nullable', 'numeric', 'min:0'],
            'critical_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->assertThresholdConsistency($validated);

        return $validated;
    }

    private function assertThresholdConsistency(array $validated): void
    {
        $thresholdRules = [
            AlertRule::RULE_PROXMOX_GUEST_CPU_USAGE,
            AlertRule::RULE_PROXMOX_GUEST_MEMORY_USAGE,
            AlertRule::RULE_PROXMOX_GUEST_DISK_USAGE,
        ];

        if (! in_array($validated['rule_key'], $thresholdRules, true)) {
            return;
        }

        if (! array_key_exists('warning_threshold', $validated) || ! array_key_exists('critical_threshold', $validated)) {
            abort(422, 'Threshold rules require warning and critical thresholds.');
        }

        if ((float) $validated['critical_threshold'] < (float) $validated['warning_threshold']) {
            abort(422, 'Critical threshold must be greater than or equal to warning threshold.');
        }
    }

    private function ruleAttributes(array $validated): array
    {
        $presets = [
            AlertRule::RULE_INTEGRATION_HEALTH_FAILURE => [
                'metric_key' => null,
                'default_severity' => 'critical',
                'config' => [],
            ],
            AlertRule::RULE_PROXMOX_GUEST_STOPPED => [
                'metric_key' => 'guest.status',
                'default_severity' => 'critical',
                'config' => ['expected_status' => 'running'],
            ],
            AlertRule::RULE_PROXMOX_GUEST_CPU_USAGE => [
                'metric_key' => 'guest.cpu_usage_percent',
                'default_severity' => null,
                'config' => [],
            ],
            AlertRule::RULE_PROXMOX_GUEST_MEMORY_USAGE => [
                'metric_key' => 'guest.memory_usage_percent',
                'default_severity' => null,
                'config' => [],
            ],
            AlertRule::RULE_PROXMOX_GUEST_DISK_USAGE => [
                'metric_key' => 'guest.disk_usage_percent',
                'default_severity' => null,
                'config' => [],
            ],
        ][$validated['rule_key']];

        $thresholdRules = [
            AlertRule::RULE_PROXMOX_GUEST_CPU_USAGE,
            AlertRule::RULE_PROXMOX_GUEST_MEMORY_USAGE,
            AlertRule::RULE_PROXMOX_GUEST_DISK_USAGE,
        ];

        return [
            'site_id' => $validated['site_id'] ?: null,
            'name' => $validated['name'],
            'rule_key' => $validated['rule_key'],
            'metric_key' => $presets['metric_key'],
            'default_severity' => $presets['default_severity'],
            'warning_threshold' => in_array($validated['rule_key'], $thresholdRules, true)
                ? (float) $validated['warning_threshold']
                : null,
            'critical_threshold' => in_array($validated['rule_key'], $thresholdRules, true)
                ? (float) $validated['critical_threshold']
                : null,
            'is_active' => $validated['is_active'] ?? true,
            'config' => $presets['config'],
        ];
    }

    private function presentRule(AlertRule $rule, bool $includeConfig = false): array
    {
        return [
            'id' => $rule->id,
            'site_id' => $rule->site_id,
            'name' => $rule->name,
            'rule_key' => $rule->rule_key,
            'scope_label' => $rule->site?->name ?? 'Global default',
            'warning_threshold' => $rule->warning_threshold,
            'critical_threshold' => $rule->critical_threshold,
            'default_severity' => $rule->default_severity,
            'is_active' => $rule->is_active,
            'config' => $includeConfig ? ($rule->config ?? []) : null,
        ];
    }

    private function siteOptions(): array
    {
        return Site::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'code' => $site->code,
            ])
            ->all();
    }
}
