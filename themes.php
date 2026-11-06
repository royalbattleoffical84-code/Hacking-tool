<?php
/** 13 themes (7 free + 6 premium) used for BOTH the merchant panel and the cashier page. Default = "orvix" (ZapUPI-style blue). */
function orvix_themes(): array {
    $dark = fn($label, $prem, $bg, $card, $card2, $line, $tx, $mut, $p, $p2, $cy, $input, $glow) =>
        [$label, $prem, $bg, $card, $card2, $line, $tx, $mut, $p, $p2, $cy, $input, $glow, $card2, "linear-gradient(90deg,$p,$p2)", '#ffffff', $mut, $card2];
    $light = fn($label, $prem, $bg, $card, $card2, $line, $tx, $mut, $p, $p2, $cy, $input, $glow, $side) =>
        [$label, $prem, $bg, $card, $card2, $line, $tx, $mut, $p, $p2, $cy, $input, $glow, $side, '#ffffff33', '#ffffff', '#eaf2ff', '#ffffff'];
    // index: 0 label 1 premium 2 bg 3 card 4 card2 5 line 6 tx 7 mut 8 p 9 p2 10 cy 11 input 12 glow 13 side 14 sideact 15 sideacttx 16 sidetx 17 top
    return [
        'orvix'    => ['ORVIX Blue', 0, '#eaf1fb', '#ffffff', '#ffffff', '#dbe5f5', '#0f1a3a', '#64739a', '#0a4aa6', '#0f7bd0', '#0c7fb8', '#f4f7fd', 'rgba(10,74,166,.06)',
                       'linear-gradient(180deg,#07449b 0%,#0069b0 100%)', '#46c9e8', '#08408a', '#eaf4ff', '#ffffff'],
        'darkblue' => $dark('Dark Blue', 0, '#070b1f', '#111a3d', '#0e1636', '#223063', '#e9eeff', '#93a0c9', '#2f6bff', '#8b3dff', '#00d4ff', '#0a1130', 'rgba(27,42,107,.35)'),
        'light'    => $light('Clean Light', 0, '#f3f6ff', '#ffffff', '#f7f9ff', '#d9e1f5', '#10173a', '#5b6794', '#2f6bff', '#7a3dff', '#0a84a8', '#f2f5ff', 'rgba(47,107,255,.08)', 'linear-gradient(180deg,#1f3a8a,#2f6bff)'),
        'midnight' => $dark('Midnight', 0, '#000000', '#0c0c0f', '#08080a', '#26262e', '#f2f2f5', '#8d8da0', '#5b8cff', '#9d6bff', '#4de1ff', '#050507', 'rgba(80,80,200,.18)'),
        'ocean'    => $dark('Ocean', 0, '#03161f', '#072734', '#06202b', '#12475c', '#e5f7ff', '#86b6c8', '#0a9fd8', '#14d4b0', '#3be8ff', '#04202b', 'rgba(10,159,216,.20)'),
        'forest'   => $dark('Forest', 0, '#07140c', '#0f2a1a', '#0c2216', '#1d4a2e', '#e8fbee', '#8dbb9c', '#19a45b', '#8fd14f', '#4fe3a0', '#0a2013', 'rgba(25,164,91,.18)'),
        'sunset'   => $dark('Sunset', 0, '#1a0b12', '#2b1220', '#230f1a', '#54243d', '#fff0ea', '#cfa3a8', '#ff6a3d', '#ff3d7f', '#ffb347', '#200d18', 'rgba(255,106,61,.18)'),
        'neon'     => $dark('Neon Night', 1, '#05010f', '#12062b', '#0d0422', '#3a1a7a', '#f4ecff', '#a98fd9', '#00c8d6', '#ff2bd6', '#7dff6a', '#0b0320', 'rgba(255,43,214,.18)'),
        'rose'     => $light('Rose Light', 1, '#fff5f7', '#ffffff', '#fff0f3', '#f3cdd7', '#3a0f1c', '#a1566b', '#e11d63', '#ff7a9c', '#e11d63', '#fff0f3', 'rgba(225,29,99,.08)', 'linear-gradient(180deg,#9f1239,#e11d63)'),
        'gold'     => $dark('Royal Gold', 1, '#0e0b04', '#1c1608', '#171205', '#5a4510', '#fff6dc', '#c9b27a', '#e6b422', '#ff8f1f', '#ffd966', '#130f05', 'rgba(230,180,34,.18)'),
        'glass'    => $dark('Purple Glass', 1, '#140a2e', '#23164a', '#1c1140', '#4b3a8f', '#f2eeff', '#b7a9e6', '#8b5cf6', '#ec4899', '#a5b4fc', '#1a0f3d', 'rgba(139,92,246,.28)'),
        'carbon'   => $dark('Carbon', 1, '#0b0d10', '#161a20', '#12151a', '#2c333d', '#e8edf3', '#8c97a6', '#3dd68c', '#2bb0ff', '#3dd68c', '#0f1216', 'rgba(61,214,140,.10)'),
        'aurora'   => $dark('Aurora', 1, '#031a1f', '#0a2e36', '#08262d', '#17606b', '#e6fffb', '#8fd0c8', '#00e5a8', '#7c4dff', '#00e5ff', '#062329', 'rgba(0,229,168,.20)'),
    ];
}

/** Valid theme key; legacy "classic" => "orvix"; premium themes fall back to "orvix" unless $premium. */
function resolve_theme(?string $key, bool $premium): string {
    $all = orvix_themes();
    if (!$key || $key === 'classic' || !isset($all[$key])) return 'orvix';
    if ($all[$key][1] && !$premium) return 'orvix';
    return $key;
}

function theme_css(string $key): string {
    $t = orvix_themes()[$key] ?? orvix_themes()['orvix'];
    $v = ['bg' => 2, 'card' => 3, 'card2' => 4, 'line' => 5, 'tx' => 6, 'mut' => 7, 'p' => 8, 'p2' => 9, 'cy' => 10, 'input' => 11, 'glow' => 12,
          'side' => 13, 'sideact' => 14, 'sideacttx' => 15, 'sidetx' => 16, 'top' => 17];
    $css = '';
    foreach ($v as $name => $i) $css .= "--$name:" . $t[$i] . ';';
    return ':root{' . $css . '}';
}
