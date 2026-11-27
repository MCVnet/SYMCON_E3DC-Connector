<?php

declare(strict_types=1);

namespace E3DCConnector;

final class IpsViewEnergyFlow
{
    /**
     * Build an IPSView 6.5 media document. Control and page IDs are local to
     * this view; Symcon variable IDs are resolved by the calling instance.
     */
    public static function render(int $media, string $name, array $ids, ?string $meterName): string
    {
        $hasMeter = $meterName !== null;
        $ink = self::color(22, 41, 56);
        $muted = self::color(63, 83, 102);
        $green = self::color(0, 128, 102);
        $blue = self::color(13, 103, 172);
        $purple = self::color(112, 72, 173);
        $orange = self::color(151, 75, 10);
        $controls = [];
        $control = static function (string $type, string $label, int $x, int $y, int $width, int $height, array $properties = []) use (&$controls): void {
            $index = count($controls);
            $controls[] = $properties + [
                'Type' => $type, 'ID' => 0, 'AutoSize' => false,
                'LocationX' => $x, 'LocationY' => $y, 'Width' => $width, 'Height' => $height,
                'ItemID' => $index + 1, 'ItemName' => $label, 'ItemIdx' => $index, 'ItemLayer' => 2, 'ItemVisibility' => 0
            ];
        };
        $text = static function (string $label, int $x, int $y, int $width, int $height, int $size, array $color, int $id = 0, string $suffix = '', int $digits = 0, int $alignment = 4) use ($control): void {
            $control($id > 0 ? 'IPSVarLabel' : 'IPSTxtLabel', $label, $x, $y, $width, $height, [
                'ID' => $id, 'Text1' => $id > 0 ? '' : $label, 'TextAlign' => $alignment,
                'Font' => ['FontFamily' => '', 'Size' => $size], 'ForeColor1' => $color,
                'Suffix' => $suffix, 'Digits' => $digits, 'Widget' => ['grididx' => '-1']
            ]);
        };
        $card = static function (string $label, int $x, int $y, int $id, array $accent, array $tint, string $unit = ' W') use ($control, $text, $ink): void {
            $control('IPSShapeRect', $label . ' Fläche', $x, $y, 196, 94, [
                'BackColor1' => $tint, 'BorderColor1' => $accent, 'BW' => 1,
                'CR1' => 14, 'CR2' => 14, 'CR3' => 14, 'CR4' => 14,
                'ItemLayer' => 1
            ]);
            $text($label, $x + 12, $y + 10, 172, 26, 16, $accent);
            $text($id > 0 ? $label . ' Livewert' : '–', $x + 12, $y + 37, 172, 42, 27, $ink, $id, $unit);
        };
        $flow = static function (string $label, int $id, int $x, int $y, int $width, int $height, int $direction, array $accent, bool $endArrow = true) use ($control): void {
            if ($id <= 0) {
                return;
            }
            $rgb = implode(';', [$accent['R'], $accent['G'], $accent['B']]);
            $control('IPSFlowLine', $label . ' Flusslinie', $x, $y, $width, $height, [
                'ID' => $id, 'ItemLayer' => 1,
                'Widget' => [
                    'grididx' => '-1', 'flowdirection' => (string) $direction,
                    'linewidth' => '3', 'linewidthdynamic' => 'false', 'linearrow' => $endArrow ? 'true' : 'false',
                    'linearrowsize' => '5', 'linearrowwidth' => '9', 'linearrowheight' => '9',
                    'flowlimitneutral' => '0', 'flowlimitnegative' => '-5000', 'flowlimitpositive' => '5000',
                    'linecolorneutral' => '182;196;207', 'linecolorpositive' => $rgb, 'linecolornegative' => $rgb,
                    'flowcolorpositive' => $rgb, 'flowcolornegative' => $rgb,
                    'flowanimationtype' => '1', 'flowanimationspeed' => '1000',
                    'flowanimationsize' => '6', 'flowanimationspace' => '20'
                ]
            ]);
        };

        $text('Energiefluss', 36, 24, 888, 56, 32, $ink);
        $card('PV-Erzeugung', 382, 94, $ids['PV'], $green, self::color(226, 245, 238));
        $card('Netz', 44, 258, $ids['Grid'], $blue, self::color(227, 239, 250));
        $card('Batterie', 720, 258, $ids['Battery'], $purple, self::color(241, 233, 250));
        $card('Wallbox', 44, 468, $ids['Wallbox'], $blue, self::color(227, 239, 250));
        $card($hasMeter ? 'Verbrauch gesamt' : 'Hausverbrauch', 382, 468, $ids['Consumption'], $orange, self::color(252, 241, 226));
        $text('Ladezustand', 730, 361, 176, 25, 15, $muted);
        $text('Ladezustand Livewert', 730, 387, 176, 31, 21, $ink, $ids['SOC'], ' %');
        $control('IPSShapeCircle', 'Autarkie Kreis', 397, 222, 166, 166, [
            'BackColor1' => self::color(248, 252, 250), 'BorderColor1' => $green, 'BW' => 5,
            'ItemLayer' => 1
        ]);
        $text($ids['Autarky'] > 0 ? 'Autarkie Livewert' : '–', 410, 265, 140, 52, 33, $ink, $ids['Autarky'], ' %', 0, 5);
        $text('Autarkie', 410, 320, 140, 25, 16, $muted, 0, '', 0, 5);

        $flow('PV', $ids['PV'], 471, 188, 18, 34, 22, $green);
        $flow('Netz', $ids['Grid'], 240, 296, 157, 18, 2, $blue);
        $flow('Batterie', $ids['Battery'], 563, 296, 157, 18, 2, $purple);
        $flow('Verbrauch', $ids['Consumption'], 471, 388, 18, 80, 22, $orange);
        $flow('Wallbox quer', $ids['Wallbox'], 142, 369, 303, 18, 12, $blue, false);
        $flow('Wallbox', $ids['Wallbox'], 133, 378, 18, 90, 22, $blue);
        if ($hasMeter) {
            $card('Hausverbrauch', 382, 660, $ids['House'], $orange, self::color(252, 241, 226));
            $card($meterName, 720, 660, $ids['Meter'], $orange, self::color(252, 241, 226));
            $flow('Hausverbrauch', $ids['House'], 471, 562, 18, 98, 22, $orange);
            $flow($meterName . ' quer', $ids['Meter'], 480, 601, 338, 18, 2, $orange, false);
            $flow($meterName, $ids['Meter'], 809, 610, 18, 50, 22, $orange);
        }

        $used = [];
        foreach ($ids as $id) {
            if ($id > 0) {
                $used[$id] = true;
            }
        }
        return json_encode([
            'Name' => $name, 'ID' => $media, 'Client' => 'Universal', 'Hardware' => '-', 'Orientation' => 'Landscape',
            'Width' => 960, 'Height' => $hasMeter ? 790 : 600, 'HardwareWidth' => 960,
            'HardwareHeight' => $hasMeter ? 790 : 600, 'FullScreen' => false,
            'MajorDesignerVersion' => 6, 'MinorDesignerVersion' => 5, 'BuildDesignerVersion' => 0, 'ViewVersion' => 2,
            'CurrentPageName' => 'Energiefluss', 'CurrentCustomName' => '', 'ItemID' => count($controls) + 1,
            'PageHistory' => [], 'CustomHistory' => [], 'ZoomLevel' => 100, 'LruIDs' => [], 'GroupIDs' => (object) [],
            'DesignerPanelTabIdxs' => [0, 0, 0, 0, 0],
            'ColorView' => self::color(248, 250, 252), 'ColorPage' => self::color(248, 250, 252),
            'ColorText' => $ink, 'DefaultFontFamily' => '', 'DefaultFontSize' => 16,
            'Images' => [], 'Colors' => [], 'VirtualIDs' => [], 'AppWidgets' => [], 'UsedIDs' => (object) $used,
            'Pages' => [[
                'ParentName' => '', 'PageName' => 'Energiefluss', 'PageTitle' => 'Energiefluss',
                'Idx' => 0, 'Position' => 0, 'IsMainPage' => true, 'IsExpanded' => true, 'IsCustom' => false,
                'IsFolder' => false, 'IsPopup' => false, 'IsInline' => false, 'ResizeOption' => 1,
                'RepositoryName' => '', 'RepositoryGroup' => '', 'PageImage' => -1, 'Description' => '',
                'IsPageProtected' => false, 'IsAlternatePage' => false, 'GridRowSpan' => 1, 'GridColSpan' => 1,
                'PopupWidth' => 960, 'PopupHeight' => $hasMeter ? 790 : 600, 'PopupAutoClose' => false,
                'BackgroundImage' => -1, 'BackgroundLayout' => 'Stretch', 'DefaultLayer' => 2,
                'GestureWipeLeftAction' => 0, 'GestureWipeLeftParam' => '',
                'GestureWipeRightAction' => 0, 'GestureWipeRightParam' => '',
                'GestureWipeUpAction' => 0, 'GestureWipeUpParam' => '',
                'GestureWipeDownAction' => 0, 'GestureWipeDownParam' => '',
                'GridEnabled' => false, 'GridMarginTop' => 0, 'GridCellWidth' => '', 'GridLineEnabled' => false,
                'GridBlockOption' => 0, 'GridPositionAnimationType' => 0, 'GridPositionAnimationSpeed' => 500,
                'GridVisibleAnimationType' => 0, 'GridVisibleAnimationSpeed' => 300,
                'ContainerSource1' => 0, 'ContainerTarget1' => 0, 'ContainerSource2' => 0, 'ContainerTarget2' => 0,
                'AlternatePageSuffix' => '', 'ConditionOrientation' => '', 'ConditionWidth' => '', 'ConditionHeight' => '',
                'ConditionConfig' => '', 'TemplateReference' => '', 'HelpText' => '',
                'Controls' => $controls, 'MatchingProfiles' => [], 'Properties' => []
            ]]
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function color(int $red, int $green, int $blue): array
    {
        return ['A' => 255, 'R' => $red, 'G' => $green, 'B' => $blue, 'Type' => 0, 'Pattern' => '12'];
    }
}
