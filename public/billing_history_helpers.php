<?php
declare(strict_types=1);

function billingHistoryLoadGrouped(string $file): array
{
    $raw = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (!is_array($raw)) $raw = [];
    $migrated = false;
    $grouped = billingHistoryNormalizeGrouped($raw, $migrated);
    if ($migrated) {
        billingHistorySaveGrouped($file, $grouped);
    }
    return $grouped;
}

function billingHistorySaveGrouped(string $file, array $grouped): bool
{
    foreach ($grouped as $key => $items) {
        if (!is_array($items) || count($items) === 0) {
            unset($grouped[$key]);
        }
    }
    ksort($grouped, SORT_NATURAL);
    return false !== file_put_contents($file, json_encode($grouped, JSON_PRETTY_PRINT));
}

function billingHistoryNormalizeGrouped(array $raw, bool &$migrated): array
{
    $migrated = false;
    if (!billingHistoryIsAssoc($raw)) {
        $migrated = true;
        return billingHistoryGroupFromList($raw);
    }
    $grouped = [];
    $needsSave = false;
    foreach ($raw as $monthKey => $items) {
        if (!is_array($items)) {
            $needsSave = true;
            continue;
        }
        foreach ($items as $row) {
            if (!is_array($row)) {
                $needsSave = true;
                continue;
            }
            $month = billingHistoryResolveMonth($row, (string) $monthKey);
            if ($month === '') {
                $needsSave = true;
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}$/', (string) $monthKey) || $month !== (string) $monthKey) {
                $needsSave = true;
            }
            $row['month'] = $month;
            if (!isset($grouped[$month])) $grouped[$month] = [];
            $grouped[$month][] = $row;
        }
    }
    ksort($grouped, SORT_NATURAL);
    $migrated = $needsSave;
    return $grouped;
}

function billingHistoryGroupFromList(array $list): array
{
    $grouped = [];
    foreach ($list as $row) {
        if (!is_array($row)) continue;
        $month = billingHistoryResolveMonth($row, '');
        if ($month === '') continue;
        $row['month'] = $month;
        if (!isset($grouped[$month])) $grouped[$month] = [];
        $grouped[$month][] = $row;
    }
    ksort($grouped, SORT_NATURAL);
    return $grouped;
}

function billingHistoryFlattenGrouped(array $grouped): array
{
    $flat = [];
    foreach ($grouped as $monthKey => $items) {
        if (!is_array($items)) continue;
        foreach ($items as $row) {
            if (!is_array($row)) continue;
            if (empty($row['month'])) {
                $row['month'] = (string) $monthKey;
            }
            $flat[] = $row;
        }
    }
    return $flat;
}

function billingHistoryResolveMonth(array $row, string $fallback): string
{
    $month = (string) ($row['month'] ?? '');
    if (preg_match('/^\d{4}-\d{2}$/', $month)) {
        return $month;
    }
    if ($fallback !== '' && preg_match('/^\d{4}-\d{2}$/', $fallback)) {
        return $fallback;
    }
    $y = (string) ($row['payment_year'] ?? '');
    $m = (string) ($row['payment_month'] ?? '');
    if (preg_match('/^\d{4}$/', $y) && preg_match('/^\d{1,2}$/', $m)) {
        return $y . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
    }
    $paidAt = (string) ($row['paid_at'] ?? '');
    if (preg_match('/^\d{4}-\d{2}/', $paidAt, $match)) {
        return $match[0];
    }
    return '';
}

function billingHistoryIsAssoc(array $arr): bool
{
    if ($arr === []) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
}
