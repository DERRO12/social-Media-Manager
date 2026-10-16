# Renders the app mark (hub + four connected nodes, same geometry as resources/views/components/app-logo-icon.blade.php)
# on the brand gradient, as PNG files.
# Usage (Windows PowerShell):  powershell -ExecutionPolicy Bypass -File scripts\make-logo.ps1 -OutDir public\brand
param([string]$OutDir = 'public\brand')

Add-Type -AssemblyName System.Drawing

function New-RoundedPath([float]$x, [float]$y, [float]$w, [float]$h, [float]$r) {
    $p = New-Object System.Drawing.Drawing2D.GraphicsPath
    if ($r -le 0) { $p.AddRectangle((New-Object System.Drawing.RectangleF($x, $y, $w, $h))); return $p }
    $d = 2 * $r
    $p.AddArc($x, $y, $d, $d, 180, 90)
    $p.AddArc($x + $w - $d, $y, $d, $d, 270, 90)
    $p.AddArc($x + $w - $d, $y + $h - $d, $d, $d, 0, 90)
    $p.AddArc($x, $y + $h - $d, $d, $d, 90, 90)
    $p.CloseFigure()
    return $p
}

function Render([int]$size, [string]$file, [bool]$rounded) {
    $bmp = New-Object System.Drawing.Bitmap($size, $size, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.Clear([System.Drawing.Color]::Transparent)

    # Background: indigo -> fuchsia -> orange, top-left to bottom-right (the site's brand gradient).
    $rect = New-Object System.Drawing.RectangleF(0, 0, $size, $size)
    $brush = New-Object System.Drawing.Drawing2D.LinearGradientBrush($rect, [System.Drawing.Color]::Black, [System.Drawing.Color]::Black, 45.0)
    $blend = New-Object System.Drawing.Drawing2D.ColorBlend(3)
    $blend.Colors = @([System.Drawing.Color]::FromArgb(99, 102, 241), [System.Drawing.Color]::FromArgb(217, 70, 239), [System.Drawing.Color]::FromArgb(251, 146, 60))
    $blend.Positions = @(0.0, 0.55, 1.0)
    $brush.InterpolationColors = $blend
    $radius = if ($rounded) { $size * 0.22 } else { 0 }
    $bg = New-RoundedPath 0 0 $size $size $radius
    $g.FillPath($brush, $bg)

    # Mark: drawn in a 40x40 design grid, scaled to 62% of the image and centred.
    $scale = $size * 0.62 / 40.0
    $off = ($size - 40 * $scale) / 2
    $white = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::White)
    function P([float]$v) { return $off + $v * $scale }
    function S([float]$v) { return $v * $scale }

    foreach ($bar in @(@(18.6, 11.2, 2.8, 6.1), @(18.6, 22.7, 2.8, 6.1), @(11.2, 18.6, 6.1, 2.8), @(22.7, 18.6, 6.1, 2.8))) {
        $g.FillRectangle($white, (P $bar[0]), (P $bar[1]), (S $bar[2]), (S $bar[3]))
    }
    foreach ($c in @(@(20, 20, 5.2), @(20, 6.5, 4.5), @(20, 33.5, 4.5), @(6.5, 20, 4.5), @(33.5, 20, 4.5))) {
        $g.FillEllipse($white, (P ($c[0] - $c[2])), (P ($c[1] - $c[2])), (S (2 * $c[2])), (S (2 * $c[2])))
    }

    $bmp.Save($file, [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose(); $bmp.Dispose()
    "{0,-28} {1}x{1}  {2:N0} bytes" -f (Split-Path $file -Leaf), $size, (Get-Item $file).Length
}

New-Item -ItemType Directory -Force $OutDir | Out-Null
# For Google's OAuth consent screen: exactly 120x120, full square (Google crops it to a circle itself).
Render 120 (Join-Path $OutDir 'logo-google-120.png') $false
# Larger copy for Meta / TikTok / LinkedIn app settings.
Render 512 (Join-Path $OutDir 'logo-512.png') $false
# TikTok requires a 1024x1024 app icon.
Render 1024 (Join-Path $OutDir 'logo-1024.png') $false
# Website icons (rounded, like the logo in the site header).
Render 32 (Join-Path $OutDir 'favicon-32.png') $true
Render 180 (Join-Path $OutDir 'apple-touch-icon.png') $true
Render 192 (Join-Path $OutDir 'icon-192.png') $true
