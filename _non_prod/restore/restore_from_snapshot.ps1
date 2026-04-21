param(
  [string]$Root = 'C:\xampp\htdocs\COMPO FINAL\emsp_docs',
  [string]$ReportFile = 'C:\xampp\htdocs\COMPO FINAL\emsp_docs\rapport_complet.txt',
  [switch]$SkipBackup
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $Root)) { throw "Root not found: $Root" }
if (-not (Test-Path -LiteralPath $ReportFile)) { throw "Report file not found: $ReportFile" }

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupRoot = Join-Path (Split-Path -Parent $Root) '_backups_emsp_docs'
if (-not (Test-Path -LiteralPath $backupRoot)) { New-Item -ItemType Directory -Path $backupRoot | Out-Null }
$backupZip = Join-Path $backupRoot ("emsp_docs_backup_{0}.zip" -f $timestamp)

if (-not $SkipBackup) {
  Add-Type -AssemblyName System.IO.Compression.FileSystem
  [System.IO.Compression.ZipFile]::CreateFromDirectory($Root, $backupZip, [System.IO.Compression.CompressionLevel]::Optimal, $false)
}
else {
  $backupZip = 'SKIPPED'
}

$sections = New-Object System.Collections.Generic.List[object]
$reportPaths = New-Object System.Collections.Generic.HashSet[string]([System.StringComparer]::OrdinalIgnoreCase)

function Add-SectionFromBuffer {
  param(
    [string]$Path,
    [System.Text.StringBuilder]$Builder,
    [System.Collections.Generic.List[object]]$Sections
  )

  if ([string]::IsNullOrWhiteSpace($Path)) { return }
  $content = $Builder.ToString()
  if ($content.EndsWith("`n")) { $content = $content.Substring(0, $content.Length - 1) }
  $Sections.Add([PSCustomObject]@{ Path = $Path; Content = $content }) | Out-Null
}

$sr = [System.IO.StreamReader]::new($ReportFile, [System.Text.Encoding]::UTF8, $true)
try {
  $currentPath = $null
  $state = 'seek_header'
  $sb = [System.Text.StringBuilder]::new()

  while (($line = $sr.ReadLine()) -ne $null) {
    if ($line -match '^CHEMIN\s*:\s*(.+)$') {
      Add-SectionFromBuffer -Path $currentPath -Builder $sb -Sections $sections

      $currentPath = $matches[1].Trim()
      [void]$reportPaths.Add($currentPath)
      [void]$sb.Clear()
      $state = 'await_separator'
      continue
    }

    if ($null -eq $currentPath) { continue }

    if ($state -eq 'await_separator') {
      if ($line -match '^=+$') {
        $state = 'content'
      }
      continue
    }

    if ($state -eq 'content' -and $line -match '^=+$') {
      $nextLine = $sr.ReadLine()
      if ($null -ne $nextLine -and $nextLine -match '^CHEMIN\s*:\s*(.+)$') {
        Add-SectionFromBuffer -Path $currentPath -Builder $sb -Sections $sections

        $currentPath = $matches[1].Trim()
        [void]$reportPaths.Add($currentPath)
        [void]$sb.Clear()
        $state = 'await_separator'
        continue
      }

      [void]$sb.Append($line)
      [void]$sb.Append("`n")
      if ($null -ne $nextLine) {
        [void]$sb.Append($nextLine)
        [void]$sb.Append("`n")
      }
      continue
    }

    [void]$sb.Append($line)
    [void]$sb.Append("`n")
  }

  Add-SectionFromBuffer -Path $currentPath -Builder $sb -Sections $sections
}
finally {
  $sr.Close()
}

$utf8NoBom = [System.Text.UTF8Encoding]::new($false)
$restoredCount = 0
$failedCount = 0
$failed = New-Object System.Collections.Generic.List[object]

foreach ($entry in $sections) {
  if ([string]::IsNullOrWhiteSpace($entry.Path)) { continue }
  try {
    $target = $entry.Path
    $dir = Split-Path -Parent $target
    if (-not [string]::IsNullOrWhiteSpace($dir) -and -not (Test-Path -LiteralPath $dir)) {
      New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }

    [System.IO.File]::WriteAllText($target, $entry.Content, $utf8NoBom)
    $restoredCount++
  }
  catch {
    $failedCount++
    $failed.Add([PSCustomObject]@{ path = $entry.Path; error = $_.Exception.Message }) | Out-Null
  }
}

$currentFiles = Get-ChildItem -LiteralPath $Root -Recurse -File | ForEach-Object { $_.FullName }
$extrasNow = @()
foreach ($f in $currentFiles) {
  if (-not $reportPaths.Contains($f)) { $extrasNow += $f }
}

$missingAfterRestore = @()
foreach ($p in $reportPaths) {
  if (-not (Test-Path -LiteralPath $p)) { $missingAfterRestore += $p }
}

$critical = @(
  (Join-Path $Root 'assets\css\emsp-theme-harvard.css'),
  (Join-Path $Root 'assets\css\emsp-theme.css'),
  (Join-Path $Root 'assets\css\emsp-palette-guard.css'),
  (Join-Path $Root 'assets\css\emsp-phase-pages.css'),
  (Join-Path $Root 'assets\css\emsp-fixes.css'),
  (Join-Path $Root 'assets\js\emsp-experience-upgrade.js'),
  (Join-Path $Root 'assets\js\emsp-fixes.js'),
  (Join-Path $Root 'assets\js\emsp-harvard-motion.js'),
  (Join-Path $Root 'assets\js\emsp-ui.js'),
  (Join-Path $Root 'index.php'),
  (Join-Path $Root 'bibliotheque.php'),
  (Join-Path $Root 'mediatheque.php'),
  (Join-Path $Root 'document.php'),
  (Join-Path $Root 'formations.php'),
  (Join-Path $Root 'historique.php'),
  (Join-Path $Root 'news-blog.php'),
  (Join-Path $Root 'news-article.php'),
  (Join-Path $Root 'includes\navbar.php')
)

$criticalChecks = New-Object System.Collections.Generic.List[object]
foreach ($c in $critical) {
  $status = if (Test-Path -LiteralPath $c) { 'present' } else { 'missing' }
  $criticalChecks.Add([PSCustomObject]@{ path = $c; status = $status }) | Out-Null
}

$reportDir = Join-Path $Root '_non_prod\restore'
if (-not (Test-Path -LiteralPath $reportDir)) { New-Item -ItemType Directory -Path $reportDir -Force | Out-Null }

$extrasPath = Join-Path $reportDir ("extras_now_{0}.txt" -f $timestamp)
$missingPath = Join-Path $reportDir ("missing_after_restore_{0}.txt" -f $timestamp)
$failedPath = Join-Path $reportDir ("failed_restore_{0}.txt" -f $timestamp)
$summaryPath = Join-Path $reportDir ("restore_summary_{0}.json" -f $timestamp)

$extrasNow | Sort-Object | Set-Content -Path $extrasPath -Encoding UTF8
$missingAfterRestore | Sort-Object | Set-Content -Path $missingPath -Encoding UTF8
($failed | ForEach-Object { "{0}`t{1}" -f $_.path, $_.error }) | Set-Content -Path $failedPath -Encoding UTF8

$summary = [PSCustomObject]@{
  timestamp = $timestamp
  backup_zip = $backupZip
  source_report = $ReportFile
  report_paths_count = $reportPaths.Count
  restored_count = $restoredCount
  failed_count = $failedCount
  missing_in_snapshot = $extrasNow.Count
  extras_now_file = $extrasPath
  missing_after_restore_file = $missingPath
  failed_restore_file = $failedPath
  critical_checks = $criticalChecks
}

$summary | ConvertTo-Json -Depth 6 | Set-Content -Path $summaryPath -Encoding UTF8

Write-Output ("BACKUP_ZIP={0}" -f $backupZip)
Write-Output ("REPORT_PATHS={0}" -f $reportPaths.Count)
Write-Output ("RESTORED_COUNT={0}" -f $restoredCount)
Write-Output ("FAILED_COUNT={0}" -f $failedCount)
Write-Output ("MISSING_IN_SNAPSHOT={0}" -f $extrasNow.Count)
Write-Output ("MISSING_AFTER_RESTORE={0}" -f $missingAfterRestore.Count)
Write-Output ("SUMMARY_JSON={0}" -f $summaryPath)
Write-Output ("EXTRAS_LIST={0}" -f $extrasPath)
