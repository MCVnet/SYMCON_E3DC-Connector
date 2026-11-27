<?php

declare(strict_types=1);

namespace E3DCConnector;

final class EnergyFlowTile
{
    public static function render(array $power, ?float $autarky, ?float $soc, ?array $meter, string $status): string
    {
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
        $format = static function (int|float|null $watts): string {
            if ($watts === null) {
                return '–';
            }
            $watts = abs($watts);
            return $watts >= 1000 ? number_format($watts / 1000, 2, ',', '.') . ' kW' : round($watts) . ' W';
        };
        $node = static function (int $x, int $y, string $label, ?float $value, string $icon, string $color, string $detail = '') use ($escape, $format): string {
            $label = $escape($label);
            $detail = $escape($detail);
            $reading = $format($value);
            return <<<SVG
<g transform="translate($x $y)">
  <title>$label · $reading</title>
  <rect width="152" height="76" rx="14" fill="#142338" stroke="#304862"/>
  <rect x="13" y="13" width="24" height="25" rx="7" fill="$color" fill-opacity="0.12"/>
  <g transform="translate(15 15) scale(.84)" stroke="$color" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none">$icon</g>
  <text x="44" y="24" font-size="12" fill="#c0d0e1">$label</text>
  <text x="44" y="47" font-size="21" font-weight="720" font-variant-numeric="tabular-nums" fill="#f0f7ff">$reading</text>
  <text x="44" y="64" font-size="10" fill="#a7bdd4">$detail</text>
</g>
SVG;
        };
        $edge = static function (string $path, ?float $watts, string $color, bool $reverse = false): string {
            $active = $watts !== null && abs($watts) >= 1;
            $stroke = $active ? $color : '#415772';
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
        $top = $node(28, 26, 'PV', $pv, $sun, '#5ee0b2');
        $top .= $node(460, 26, 'Netz', $grid, $tower, '#70bdff', $grid === null || $grid == 0 ? '' : ($grid > 0 ? 'Bezug' : 'Einspeisung'));
        $bottom = $node(28, 279, 'Batterie', $battery, $cell, '#c3a4ff',
            ($soc === null ? '' : number_format($soc, 0, ',', '.') . ' %')
                . ($battery === null || $battery == 0 ? '' : (($soc === null ? '' : ' · ') . ($battery > 0 ? 'Lädt' : 'Entlädt'))));
        $bottom .= $node(244, 279, 'Wallbox', $wallbox, $plug, '#70bdff');
        $bottom .= $node(460, 279, $meter === null ? 'Hausverbrauch' : 'Verbrauch gesamt', $consumption, $load, '#f4b875');
        $links = $edge('M180 64 H217 Q235 64 235 82 V130 Q235 153 258 153', $pv, '#5ee0b2');
        $links .= $edge('M460 64 H423 Q405 64 405 82 V130 Q405 153 382 153', $grid, '#70bdff', ($grid ?? 0) < 0);
        $links .= $edge('M258 199 H125 Q104 199 104 220 V274', $battery, '#c3a4ff', ($battery ?? 0) < 0);
        $links .= $edge('M320 239 V274', $wallbox, '#70bdff');
        $links .= $edge('M382 199 H515 Q536 199 536 220 V274', $consumption, '#f4b875');
        $height = 398;
        if ($meter !== null) {
            $height = 508;
            $name = $meter[2];
            $characters = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($characters) > 16) {
                $name = implode('', array_slice($characters, 0, 15)) . '…';
            }
            $links .= $edge('M536 355 V376 H320 V404', $house, '#f4b875');
            $links .= $edge('M536 355 V404', $extra, '#f4b875');
            $bottom .= $node(244, 409, 'Hausverbrauch', $house, $home, '#f4b875');
            $bottom .= $node(460, 409, $name, $extra, $meterIcon, '#f4b875', 'Zubehör · Messpunkt');
        }
        $progress = round(max(0, min(100, $autarky ?? 0)));
        $percentage = $autarky === null ? '–' : number_format($progress, 0, ',', '.') . ' %';
        $statusColor = $status === 'Live-Daten' ? '#5ee0b2' : '#f4b875';
        $status = $escape($status);
        $statusY = $height - 19;
        return <<<HTML
<div style="max-width:900px;margin:auto">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 $height" width="100%" style="display:block;font-family:system-ui,sans-serif" role="img" aria-label="Energiefluss der Anlage">
<style>
@keyframes e3dc-flow-forward { to { stroke-dashoffset: -28; } }
.e3dc-flow-pulse { animation: e3dc-flow-forward 1s linear infinite; }
.e3dc-flow-reverse { animation-direction: reverse; }
@media (prefers-reduced-motion: reduce) { .e3dc-flow-pulse { display: none; } }
</style>
<defs>
  <radialGradient id="e3dcFlowBackdrop" cx="50%" cy="42%" r="70%">
    <stop stop-color="#172b42"/>
    <stop offset="1" stop-color="#0b1423"/>
  </radialGradient>
  <marker id="e3dcArrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto"><path d="M0 0 8 4 0 8Z" fill="#aac7e4"/></marker>
  <marker id="e3dcArrowBack" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0 0 8 4 0 8Z" fill="#aac7e4"/></marker>
</defs>
<rect width="640" height="$height" rx="20" fill="url(#e3dcFlowBackdrop)"/>
$links
<circle cx="320" cy="174" r="58" fill="#14263a" stroke="#29415a" stroke-width="8"/>
<circle cx="320" cy="174" r="58" fill="none" stroke="#5ee0b2" stroke-width="8" stroke-linecap="round" pathLength="100" stroke-dasharray="$progress 100" transform="rotate(-90 320 174)"/>
<text x="320" y="173" text-anchor="middle" fill="#f0f7ff" font-size="29" font-weight="700">$percentage</text>
<text x="320" y="194" text-anchor="middle" fill="#c0d0e1" font-size="13">Momentanautarkie</text>
$top
$bottom
<circle cx="33" cy="$statusY" r="3" fill="$statusColor"/>
<text x="44" y="$statusY" dominant-baseline="middle" fill="#a7bdd4" font-size="11">$status</text>
</svg>
</div>
HTML;
    }
}
