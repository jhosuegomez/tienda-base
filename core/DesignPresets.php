<?php
declare(strict_types=1);

// Design presets data (slice: palettes + themes). Pure data, no I/O.
// admin/settings.php validates ids against these arrays before writing
// anything, so unknown input can never reach the settings pipeline.
final class DesignPresets
{
    public static function isDark(string $hex): bool
    {
        $rgb = sscanf($hex, '#%02x%02x%02x');
        return count($rgb) === 3 && ($rgb[0] * .2126 + $rgb[1] * .7152 + $rgb[2] * .0722) < 128;
    }
    /**
     * @return array<string,array{name:string,colors:array{primary:string,accent:string,bg:string,text:string}}>
     */
    public static function palettes(): array
    {
        return [
            'oceano' => [
                'name' => 'Océano',
                'colors' => ['primary' => '#1a73e8', 'accent' => '#f9ab00', 'bg' => '#ffffff', 'text' => '#202124'],
            ],
            'terra' => [
                'name' => 'Terra',
                'colors' => ['primary' => '#c2410c', 'accent' => '#eab308', 'bg' => '#fff7ed', 'text' => '#431407'],
            ],
            'bosque' => [
                'name' => 'Bosque',
                'colors' => ['primary' => '#15803d', 'accent' => '#a3e635', 'bg' => '#f0fdf4', 'text' => '#14532d'],
            ],
            'uva' => [
                'name' => 'Uva',
                'colors' => ['primary' => '#7e22ce', 'accent' => '#f0abfc', 'bg' => '#faf5ff', 'text' => '#3b0764'],
            ],
            'carbon' => [
                'name' => 'Carbón premium',
                'colors' => ['primary' => '#f59e0b', 'accent' => '#38bdf8', 'bg' => '#0f172a', 'text' => '#e2e8f0'],
            ],
            'rosa' => [
                'name' => 'Rosa',
                'colors' => ['primary' => '#e11d48', 'accent' => '#fb7185', 'bg' => '#fff1f2', 'text' => '#4c0519'],
            ],
            'oceano_dark' => ['name' => 'Océano nocturno', 'colors' => ['primary' => '#60a5fa', 'accent' => '#22d3ee', 'bg' => '#0b1628', 'text' => '#e2e8f0']],
            'bosque_dark' => ['name' => 'Bosque nocturno', 'colors' => ['primary' => '#4ade80', 'accent' => '#bef264', 'bg' => '#0c1b14', 'text' => '#e4f4e9']],
            'uva_dark' => ['name' => 'Uva nocturna', 'colors' => ['primary' => '#c084fc', 'accent' => '#f0abfc', 'bg' => '#1a1025', 'text' => '#f3e8ff']],
            'rosa_dark' => ['name' => 'Rosa nocturna', 'colors' => ['primary' => '#fb7185', 'accent' => '#fda4af', 'bg' => '#230f18', 'text' => '#ffe4e6']],
        ];
    }

    /**
     * @return array<string,array{name:string,blurb:string,palette:array{primary:string,accent:string,bg:string,text:string},font:string,radius:string,hero:string}>
     */
    public static function themes(): array
    {
        return [
            'moderno' => [
                'name' => 'Moderno',
                'blurb' => 'Claro y actual, el aspecto de siempre.',
                'palette' => ['primary' => '#1a73e8', 'accent' => '#f9ab00', 'bg' => '#ffffff', 'text' => '#202124'],
                'font' => 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
                'radius' => 'xl',
                'hero' => 'panel',
            ],
            'calido' => [
                'name' => 'Cálido',
                'blurb' => 'Tonos tierra, tipografía serif y hero grande.',
                'palette' => ['primary' => '#c2410c', 'accent' => '#eab308', 'bg' => '#fff7ed', 'text' => '#431407'],
                'font' => 'Georgia, "Times New Roman", serif',
                'radius' => '2xl',
                'hero' => 'big',
            ],
            'nocturno' => [
                'name' => 'Nocturno',
                'blurb' => 'Superficies oscuras para vender de noche.',
                'palette' => ['primary' => '#f59e0b', 'accent' => '#38bdf8', 'bg' => '#0f172a', 'text' => '#e2e8f0'],
                'font' => 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
                'radius' => 'xl',
                'hero' => 'panel',
            ],
        ];
    }
}
