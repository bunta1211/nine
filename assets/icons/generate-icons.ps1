# Social9 PWA icons generator (fallback when PHP GD is not available)
# Uses .NET System.Drawing. Creates green background + white "9" icons.
# Run: powershell -ExecutionPolicy Bypass -File assets/icons/generate-icons.ps1

$ErrorActionPreference = "Stop"
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$sizes = @(72, 96, 128, 144, 152, 192, 384, 512)
$appleSize = 180
$faviconSize = 32

Add-Type -AssemblyName System.Drawing

$bgColor = [System.Drawing.Color]::FromArgb(255, 45, 74, 45)   # #2d4a2d
$fgColor = [System.Drawing.Color]::FromArgb(255, 255, 255, 255)

function New-IconBitmap {
    param([int]$size)
    $bmp = New-Object System.Drawing.Bitmap($size, $size)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.Clear($bgColor)
    $font = New-Object System.Drawing.Font("Meiryo", [math]::Max(12, [int]($size * 0.52)), [System.Drawing.FontStyle]::Bold)
    $sf = New-Object System.Drawing.StringFormat
    $sf.Alignment = [System.Drawing.StringAlignment]::Center
    $sf.LineAlignment = [System.Drawing.StringAlignment]::Center
    $rect = New-Object System.Drawing.RectangleF(0, 0, $size, $size)
    $brush = New-Object System.Drawing.SolidBrush($fgColor)
    $g.DrawString("9", $font, $brush, $rect, $sf)
    $font.Dispose()
    $brush.Dispose()
    $g.Dispose()
    return $bmp
}

foreach ($s in $sizes) {
    $path = Join-Path $scriptDir "icon-$s`x$s.png"
    $bmp = New-IconBitmap -size $s
    $bmp.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
    Write-Host "Generated: icon-$s`x$s.png"
}

$path180 = Join-Path $scriptDir "apple-touch-icon.png"
$bmp180 = New-IconBitmap -size $appleSize
$bmp180.Save($path180, [System.Drawing.Imaging.ImageFormat]::Png)
$bmp180.Dispose()
Write-Host "Generated: apple-touch-icon.png"

$path32 = Join-Path $scriptDir "favicon-32x32.png"
$bmp32 = New-IconBitmap -size $faviconSize
$bmp32.Save($path32, [System.Drawing.Imaging.ImageFormat]::Png)
$bmp32.Dispose()
Write-Host "Generated: favicon-32x32.png"

Write-Host "Done. icon-192x192.png and other PWA icons are in $scriptDir"
exit 0
