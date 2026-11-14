<?php

namespace Fava\Domain;

/**
 * Access to the form definition and the scoring parameters currently in force.
 */
class FormDef
{
    /** @var array|null */
    private static $def = null;

    public static function get(): array
    {
        if (self::$def === null) {
            self::$def = require APP_ROOT . '/app/form_definition.php';
        }
        return self::$def;
    }

    /** Scoring parameters = defaults from the definition file, overridden by the admin's settings. */
    public static function currentConfig(): array
    {
        $cfg = self::get()['defaults'];
        $saved = Settings::get('scoring', null);
        if (is_array($saved)) {
            $cfg = self::mergeConfig($cfg, $saved);
        }
        return $cfg;
    }

    /** Only known keys are taken over, so a damaged setting can never break the calculation. */
    public static function mergeConfig(array $cfg, array $saved): array
    {
        foreach (['A', 'B', 'C'] as $t) {
            if (isset($saved['tiers'][$t]['ceiling']) && is_numeric($saved['tiers'][$t]['ceiling']) && $saved['tiers'][$t]['ceiling'] > 0) {
                $cfg['tiers'][$t]['ceiling'] = (float) $saved['tiers'][$t]['ceiling'];
            }
            if (!empty($saved['tiers'][$t]['label']) && is_string($saved['tiers'][$t]['label'])) {
                $cfg['tiers'][$t]['label'] = $saved['tiers'][$t]['label'];
            }
        }
        foreach (['reject_below', 'conditional_below'] as $k) {
            if (isset($saved['thresholds'][$k]) && is_numeric($saved['thresholds'][$k])) {
                $cfg['thresholds'][$k] = (float) $saved['thresholds'][$k];
            }
        }
        if (isset($saved['weights']) && is_array($saved['weights'])) {
            $w = [];
            foreach (array_keys($cfg['weights']) as $dim) {
                if (!isset($saved['weights'][$dim]) || !is_numeric($saved['weights'][$dim])) {
                    $w = null;
                    break;
                }
                $w[$dim] = (float) $saved['weights'][$dim];
            }
            if ($w !== null && abs(array_sum($w) - 1.0) < 0.0001) {
                $cfg['weights'] = $w;
            }
        }
        foreach (['base', 'step'] as $k) {
            if (isset($saved['risk'][$k]) && is_numeric($saved['risk'][$k])) {
                $cfg['risk'][$k] = (float) $saved['risk'][$k];
            }
        }
        if (isset($saved['cap_amount'])) {
            $cfg['cap_amount'] = (bool) $saved['cap_amount'];
        }
        if (isset($saved['payment']) && is_array($saved['payment']) && count($saved['payment']) === 3
            && abs(array_sum($saved['payment']) - 100) < 0.0001) {
            $cfg['payment'] = array_map('floatval', array_values($saved['payment']));
        }
        if (isset($saved['criteria']) && is_array($saved['criteria'])) {
            $crit = [];
            foreach ($saved['criteria'] as $dim => $ids) {
                if (!isset($cfg['weights'][$dim]) || !is_array($ids)) {
                    continue;
                }
                $ids = array_values(array_filter($ids, 'is_string'));
                if ($ids) {
                    $crit[$dim] = $ids;
                }
            }
            if ($crit) {
                $cfg['criteria'] = $crit;
            }
        }
        return $cfg;
    }

    /** Criterion ids per dimension; stored with a frozen config so later edits cannot change an assessment. */
    public static function criteriaMap(): array
    {
        $out = [];
        foreach (self::get()['dimensions'] as $dim => $d) {
            $out[$dim] = array_keys($d['criteria']);
        }
        return $out;
    }

    public static function roleDims(string $role): array
    {
        return self::get()['roles'][$role]['dims'];
    }

    /** @return string[] criterion ids the role has to score */
    public static function roleCriteria(string $role): array
    {
        $ids = [];
        foreach (self::roleDims($role) as $dim) {
            foreach (array_keys(self::get()['dimensions'][$dim]['criteria']) as $id) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public static function decision(?string $key): array
    {
        $d = self::get()['decisions'];
        return $d[$key] ?? ['label' => '—', 'title' => '—', 'color' => 'gray'];
    }

    /** Everything the browser needs to run the same calculation as the server. */
    public static function clientConfig(string $role, array $cfg): array
    {
        $def = self::get();
        $dims = [];
        foreach ($def['roles'][$role]['dims'] as $dim) {
            $dims[$dim] = [
                'label' => $def['dimensions'][$dim]['label'],
                'criteria' => array_keys($def['dimensions'][$dim]['criteria']),
            ];
        }
        $decisions = [];
        foreach ($def['decisions'] as $k => $d) {
            $decisions[$k] = $d;
        }
        return [
            'role' => $role,
            'dims' => $dims,
            'criteria' => $cfg['criteria'] ?? [],
            'weights' => $cfg['weights'],
            'thresholds' => $cfg['thresholds'],
            'tiers' => $cfg['tiers'],
            'risk' => $cfg['risk'],
            'cap_amount' => (bool) $cfg['cap_amount'],
            'payment' => $cfg['payment'],
            'decisions' => $decisions,
        ];
    }
}
