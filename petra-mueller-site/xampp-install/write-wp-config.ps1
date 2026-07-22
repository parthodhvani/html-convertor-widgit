param(
  [Parameter(Mandatory = $true)]
  [string]$Target
)

$Config = Join-Path $Target 'wp-config.php'
$Sample = Join-Path $Target 'wp-config-sample.php'

if (-not (Test-Path $Config)) {
  if (Test-Path $Sample) {
    Copy-Item $Sample $Config -Force
  } else {
    Write-Error "wp-config.php not found in $Target"
    exit 1
  }
}

$content = Get-Content -Path $Config -Raw -Encoding UTF8

function Set-WpDefine([string]$Name, [string]$Value, [string]$Text) {
  $pattern = "define\(\s*'$Name'\s*,\s*'[^']*'\s*\)"
  $replacement = "define( '$Name', '$Value' )"
  if ($Text -match $pattern) {
    return [regex]::Replace($Text, $pattern, $replacement, 1)
  }
  return $Text
}

$content = Set-WpDefine 'DB_NAME' 'wp_petra_mueller' $content
$content = Set-WpDefine 'DB_USER' 'root' $content
$content = Set-WpDefine 'DB_PASSWORD' '' $content
$content = Set-WpDefine 'DB_HOST' 'localhost' $content

# Ensure salts exist (keep existing if present)
Set-Content -Path $Config -Value $content -Encoding UTF8
Write-Host "wp-config.php configured for XAMPP (root / empty password)."
