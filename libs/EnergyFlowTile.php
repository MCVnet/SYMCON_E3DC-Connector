<?php

declare(strict_types=1);

namespace E3DCConnector;

final class EnergyFlowTile
{
    public static function render(array $power, ?float $autarky, ?float $soc, ?array $meter, string $status, string $theme): string
    {
        $theme = match ($theme) {
            'light', 'auto' => $theme,
            default => 'dark'
        };
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
        $format = static function (int|float|null $watts): string {
            if ($watts === null) {
                return '–';
            }
            $watts = abs($watts);
            return $watts >= 1000 ? number_format($watts / 1000, 2, ',', '.') . ' kW' : round($watts) . ' W';
        };
        $node = static function (int $x, int $y, string $label, ?float $value, string $icon, string $color, string $detail = '') use ($escape, $format): string {
            $fit = in_array($label, ['Verbrauch gesamt', 'Hausverbrauch'], true) || strlen($label) >= 16
                ? ' textLength="104" lengthAdjust="spacingAndGlyphs"' : '';
            $label = $escape($label);
            $detail = $escape($detail);
            $reading = $format($value);
            return <<<SVG
<g transform="translate($x $y)">
  <title>$label · $reading</title>
  <rect width="152" height="76" rx="14" class="e3dc-card"/>
  <rect x="13" y="13" width="24" height="25" rx="7" fill="$color" fill-opacity="0.12"/>
  <g transform="translate(15 15) scale(.84)" stroke="$color" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none">$icon</g>
  <text x="44" y="24" font-size="14" class="e3dc-label"$fit>$label</text>
  <text x="44" y="47" font-size="23" font-weight="720" font-variant-numeric="tabular-nums" class="e3dc-value">$reading</text>
  <text x="44" y="64" font-size="11" class="e3dc-detail">$detail</text>
</g>
SVG;
        };
        $edge = static function (string $path, ?float $watts, string $color, bool $reverse = false): string {
            $active = $watts !== null && abs($watts) >= 1;
            $stroke = $active ? $color : 'var(--e3dc-track)';
            $arrow = $active ? ($reverse ? ' marker-start="url(#e3dcArrowBack)"' : ' marker-end="url(#e3dcArrow)"') : '';
            $track = '<path d="' . $path . '" stroke="' . $stroke
                . '" stroke-width="2" stroke-opacity="' . ($active ? '.65' : '.48') . '" fill="none"' . $arrow . '/>';
            if (!$active) {
                return $track;
            }
            $direction = $reverse ? ' e3dc-flow-reverse' : '';
            return $track . '<path d="' . $path . '" class="e3dc-flow-pulse' . $direction . '" stroke="' . $color
                . '" stroke-width="4" stroke-dasharray="5 23" stroke-linecap="round" fill="none"/>';
        };

        $pv = $power['PV'] ?? null;
        $grid = $power['Grid'] ?? null;
        $battery = $power['Battery'] ?? null;
        $wallbox = $power['WallboxPower'] ?? null;
        $consumption = $power['House'] ?? null;
        $extra = $meter[1] ?? null;
        $house = $consumption !== null && $extra !== null ? max(0, $consumption - $extra) : null;
        $sun = '<circle cx="12" cy="12" r="4"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3M5 5l2 2m10 10 2 2M5 19l2-2M17 7l2-2"/>';
        $tower = '<path d="m6 22 6-20 6 20M5 9h14M4 14h16M8 16l8 4m0-4-8 4"/>';
        $cell = '<rect x="2" y="6" width="18" height="12" rx="2"/><path d="M22 10v4m-16-4v4m-2-2h4m6 0h3"/>';
        $plug = '<path d="M8 3v6m8-6v6M5 9h14v3a7 7 0 0 1-14 0zm7 10v4"/>';
        $load = '<path d="M3 3v18h18M7 17v-4m5 4V8m5 9V5"/>';
        $home = '<path d="m2 11 10-8 10 8M5 10v11h14V10m-9 11v-6h4v6"/>';
        $meterIcon = '<rect x="2" y="3" width="20" height="18" rx="3"/><path d="M6 15a6 6 0 0 1 12 0m-6 0 3-4"/>';
        $top = $node(28, 26, 'PV', $pv, $sun, 'var(--e3dc-green)');
        $top .= $node(460, 26, 'Netz', $grid, $tower, 'var(--e3dc-blue)', $grid === null || $grid == 0 ? '' : ($grid > 0 ? 'Bezug' : 'Einspeisung'));
        $bottom = $node(28, 279, 'Batterie', $battery, $cell, 'var(--e3dc-purple)',
            ($soc === null ? '' : number_format($soc, 0, ',', '.') . ' %')
                . ($battery === null || $battery == 0 ? '' : (($soc === null ? '' : ' · ') . ($battery > 0 ? 'Lädt' : 'Entlädt'))));
        $bottom .= $node(244, 279, 'Wallbox', $wallbox, $plug, 'var(--e3dc-blue)');
        $bottom .= $node(460, 279, $meter === null ? 'Hausverbrauch' : 'Verbrauch gesamt', $consumption, $load, 'var(--e3dc-orange)');
        $links = $edge('M180 64 H217 Q235 64 235 82 V130 Q235 153 258 153', $pv, 'var(--e3dc-green)');
        $links .= $edge('M460 64 H423 Q405 64 405 82 V130 Q405 153 382 153', $grid, 'var(--e3dc-blue)', ($grid ?? 0) < 0);
        $links .= $edge('M258 199 H125 Q104 199 104 220 V274', $battery, 'var(--e3dc-purple)', ($battery ?? 0) < 0);
        $links .= $edge('M320 239 V274', $wallbox, 'var(--e3dc-blue)');
        $links .= $edge('M382 199 H515 Q536 199 536 220 V274', $consumption, 'var(--e3dc-orange)');
        $height = 398;
        if ($meter !== null) {
            $height = 508;
            $name = $meter[2];
            $characters = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($characters) > 16) {
                $name = implode('', array_slice($characters, 0, 15)) . '…';
            }
            $links .= $edge('M536 355 V376 H320 V404', $house, 'var(--e3dc-orange)');
            $links .= $edge('M536 355 V404', $extra, 'var(--e3dc-orange)');
            $bottom .= $node(244, 409, 'Hausverbrauch', $house, $home, 'var(--e3dc-orange)');
            $bottom .= $node(460, 409, $name, $extra, $meterIcon, 'var(--e3dc-orange)', 'Zubehör · Messpunkt');
        }
        $progress = round(max(0, min(100, $autarky ?? 0)));
        $percentage = $autarky === null ? '–' : number_format($progress, 0, ',', '.') . ' %';
        $statusColor = $status === 'Live-Daten' ? 'var(--e3dc-green)' : 'var(--e3dc-orange)';
        $status = $escape($status);
        $statusY = $height - 19;
        return <<<HTML
<div style="width:100%">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 $height" width="100%" class="e3dc-flow e3dc-flow-$theme" style="display:block;width:100%;height:auto;font-family:system-ui,sans-serif" role="img" aria-label="Energiefluss der Anlage">
<style>
.e3dc-flow {
  --e3dc-bg-start:#172b42; --e3dc-bg-end:#0b1423; --e3dc-card:#142338; --e3dc-border:#304862;
  --e3dc-value:#f0f7ff; --e3dc-label:#d4e1ef; --e3dc-detail:#b3c8dd; --e3dc-hub:#14263a;
  --e3dc-track:#607b9a; --e3dc-arrow:#aac7e4; --e3dc-green:#5ee0b2; --e3dc-blue:#70bdff;
  --e3dc-purple:#c3a4ff; --e3dc-orange:#f4b875;
}
.e3dc-flow-light {
  --e3dc-bg-start:#f9fcff; --e3dc-bg-end:#e6eef7; --e3dc-card:#fff; --e3dc-border:#9db2c7;
  --e3dc-value:#122538; --e3dc-label:#27435c; --e3dc-detail:#405c75; --e3dc-hub:#fff;
  --e3dc-track:#718aa1; --e3dc-arrow:#426482; --e3dc-green:#087f63; --e3dc-blue:#006caf;
  --e3dc-purple:#7049ae; --e3dc-orange:#a7580d;
}
@media (prefers-color-scheme: light) {
  .e3dc-flow-auto {
    --e3dc-bg-start:#f9fcff; --e3dc-bg-end:#e6eef7; --e3dc-card:#fff; --e3dc-border:#9db2c7;
    --e3dc-value:#122538; --e3dc-label:#27435c; --e3dc-detail:#405c75; --e3dc-hub:#fff;
    --e3dc-track:#718aa1; --e3dc-arrow:#426482; --e3dc-green:#087f63; --e3dc-blue:#006caf;
    --e3dc-purple:#7049ae; --e3dc-orange:#a7580d;
  }
}
.e3dc-card { fill:var(--e3dc-card); stroke:var(--e3dc-border); }
.e3dc-value { fill:var(--e3dc-value); }
.e3dc-label { fill:var(--e3dc-label); }
.e3dc-detail { fill:var(--e3dc-detail); }
@keyframes e3dc-flow-forward { to { stroke-dashoffset: -28; } }
.e3dc-flow-pulse { animation: e3dc-flow-forward 1s linear infinite; }
.e3dc-flow-reverse { animation-direction: reverse; }
@media (prefers-reduced-motion: reduce) { .e3dc-flow-pulse { display: none; } }
</style>
<defs>
  <radialGradient id="e3dcFlowBackdrop" cx="50%" cy="42%" r="70%">
    <stop style="stop-color:var(--e3dc-bg-start)"/>
    <stop offset="1" style="stop-color:var(--e3dc-bg-end)"/>
  </radialGradient>
  <marker id="e3dcArrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto"><path d="M0 0 8 4 0 8Z" fill="var(--e3dc-arrow)"/></marker>
  <marker id="e3dcArrowBack" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0 0 8 4 0 8Z" fill="var(--e3dc-arrow)"/></marker>
</defs>
<rect width="640" height="$height" rx="20" fill="url(#e3dcFlowBackdrop)"/>
$links
<circle cx="320" cy="174" r="58" fill="var(--e3dc-hub)" stroke="var(--e3dc-border)" stroke-width="8"/>
<circle cx="320" cy="174" r="58" fill="none" stroke="var(--e3dc-green)" stroke-width="8" stroke-linecap="round" pathLength="100" stroke-dasharray="$progress 100" transform="rotate(-90 320 174)"/>
<text x="320" y="173" text-anchor="middle" class="e3dc-value" font-size="32" font-weight="700">$percentage</text>
<text x="320" y="194" text-anchor="middle" class="e3dc-label" font-size="13">Autarkie</text>
$top
$bottom
<circle cx="33" cy="$statusY" r="3" fill="$statusColor"/>
<text x="44" y="$statusY" dominant-baseline="middle" class="e3dc-detail" font-size="12">$status</text>
</svg>
</div>
HTML;
    }
}
