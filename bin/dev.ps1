<#
.SYNOPSIS
    Dev environment for Tickets Sharp, on a Local (localwp.com) site running The Events Calendar,
    Event Tickets and WooCommerce.

.DESCRIPTION
    Works against the Local site named by -Site (default: aa-bristol-future). The site must be
    started in Local. Everything Local-specific — the site's PHP binary, php.ini, MySQL port and
    bundled WP-CLI / Composer — is read from Local's own config, so nothing is hard-coded.

      setup     link + activate + seed  (one command from a fresh checkout)
      link      junction <site>\wp-content\plugins\tickets-sharp -> this checkout
      unlink    remove that junction (rmdir, never a recursive delete through it)
      activate  wp plugin activate tickets-sharp
      seed      a sample event with tickets (one with attendee fields, two sharing capacity)
      reset     remove the sample data only
      wp        run any WP-CLI command, e.g.  .\bin\dev.ps1 wp plugin list
      composer  run Local's bundled Composer in this checkout, e.g.  .\bin\dev.ps1 composer test
      info      print the resolved paths and URLs

    `setup` never installs WooCommerce, Event Tickets or The Events Calendar: it stops and says
    which is missing, because installing a plugin on a site is a decision for its owner.

.EXAMPLE
    .\bin\dev.ps1 setup
.EXAMPLE
    .\bin\dev.ps1 composer test
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('setup', 'link', 'unlink', 'activate', 'seed', 'reset', 'wp', 'composer', 'info')]
    [string] $Command = 'info',

    [string] $Site = $(if ($env:TICKETS_SHARP_SITE) { $env:TICKETS_SHARP_SITE } else { 'aa-bristol-future' }),

    [Parameter(Position = 1, ValueFromRemainingArguments = $true)]
    [string[]] $Rest = @()
)

$ErrorActionPreference = 'Stop'
$PluginSlug = 'tickets-sharp'
$Root = Split-Path -Parent $PSScriptRoot

function Get-LocalSite {
    $sitesJson = Join-Path $env:APPDATA 'Local\sites.json'
    if (-not (Test-Path $sitesJson)) { throw "Local is not installed (no $sitesJson)." }

    $sites = Get-Content $sitesJson -Raw | ConvertFrom-Json
    $entry = $sites.PSObject.Properties | Where-Object { $_.Value.name -eq $Site } | Select-Object -First 1
    if (-not $entry) {
        $names = ($sites.PSObject.Properties.Value | ForEach-Object name) -join ', '
        throw "No Local site named '$Site'. Known sites: $names"
    }

    $id = $entry.Name
    $s = $entry.Value
    $sitePath = $s.path -replace '^~', $env:USERPROFILE
    $phpVersion = $s.services.php.version
    $phpDir = Get-ChildItem (Join-Path $env:APPDATA 'Local\lightning-services') -Directory |
        Where-Object Name -like "php-$phpVersion+*" | Select-Object -First 1
    if (-not $phpDir) { throw "Local's PHP $phpVersion runtime was not found." }

    $localApp = @(
        (Join-Path $env:LOCALAPPDATA 'Programs\Local'),
        'C:\Program Files\Local',
        'C:\Program Files (x86)\Local'
    ) | Where-Object { Test-Path $_ } | Select-Object -First 1
    if (-not $localApp) { throw 'Could not find the Local application folder.' }
    $bin = Join-Path $localApp 'resources\extraResources\bin'

    $statusFile = Join-Path $env:APPDATA 'Local\site-statuses.json'
    $status = if (Test-Path $statusFile) { (Get-Content $statusFile -Raw | ConvertFrom-Json).$id } else { 'unknown' }

    [pscustomobject]@{
        Id        = $id
        Name      = $s.name
        Domain    = $s.domain
        Status    = $status
        Public    = Join-Path $sitePath 'app\public'
        Php       = Join-Path $phpDir.FullName 'bin\win64\php.exe'
        PhpIni    = Join-Path $env:APPDATA "Local\run\$id\conf\php\php.ini"
        MysqlPort = $s.services.mysql.ports.MYSQL[0]
        WpCli     = Join-Path $bin 'wp-cli\wp-cli.phar'
        Composer  = Join-Path $bin 'composer\composer.phar'
    }
}

function Invoke-Wp {
    param([string[]] $WpArgs, [switch] $AllowFailure)
    $lsite = Get-LocalSite
    if ($lsite.Status -ne 'running') { throw "Start '$($lsite.Name)' in Local first (status: $($lsite.Status))." }

    # wp-config.php uses DB_HOST=localhost; point mysqli at the site's MySQL port.
    $phpArgs = @('-c', $lsite.PhpIni, '-d', "mysqli.default_port=$($lsite.MysqlPort)", '-d', 'display_startup_errors=0', $lsite.WpCli, "--path=$($lsite.Public)") + $WpArgs
    & $lsite.Php @phpArgs 2>&1 | Where-Object { "$_" -notmatch 'php_imagick' }
    if ($LASTEXITCODE -ne 0 -and -not $AllowFailure) { throw "wp $($WpArgs -join ' ') failed ($LASTEXITCODE)." }
}

function Test-PluginActive {
    param([string] $Slug)
    Invoke-Wp @('plugin', 'is-active', $Slug) -AllowFailure | Out-Null
    return $LASTEXITCODE -eq 0
}

function Invoke-Link {
    $lsite = Get-LocalSite
    $target = Join-Path $lsite.Public "wp-content\plugins\$PluginSlug"
    if (Test-Path $target) {
        $item = Get-Item $target -Force
        if ($item.LinkType -eq 'Junction' -and $item.Target -contains $Root) {
            Write-Host "Already linked: $target -> $Root"
            return
        }
        throw "$target already exists and is not a junction to this checkout. Remove it yourself first."
    }
    New-Item -ItemType Junction -Path $target -Target $Root | Out-Null
    Write-Host "Linked $target -> $Root"
}

function Invoke-Unlink {
    $lsite = Get-LocalSite
    $target = Join-Path $lsite.Public "wp-content\plugins\$PluginSlug"
    if (-not (Test-Path $target)) { Write-Host 'Not linked.'; return }
    if ((Get-Item $target -Force).LinkType -ne 'Junction') { throw "$target is not a junction; refusing to touch it." }
    cmd /c rmdir "$target" | Out-Null
    Write-Host "Removed junction $target"
}

function Assert-Dependencies {
    $missing = @('the-events-calendar', 'event-tickets', 'woocommerce') | Where-Object { -not (Test-PluginActive $_) }
    if ($missing) {
        throw "Activate these on '$Site' first (setup does not install plugins): $($missing -join ', ')"
    }
}

switch ($Command) {
    'info' {
        $lsite = Get-LocalSite
        $lsite | Format-List
        Write-Host "Sample event : http://$($lsite.Domain)/?tickets_sharp_sample=1  (after seed)"
        Write-Host "Admin        : http://$($lsite.Domain)/wp-admin/"
    }
    'link'     { Invoke-Link }
    'unlink'   { Invoke-Unlink }
    'activate' { Invoke-Wp @('plugin', 'activate', $PluginSlug) }
    'seed'     { Invoke-Wp @('eval-file', (Join-Path $Root 'bin\seed.php')) }
    'reset'    { Invoke-Wp @('eval-file', (Join-Path $Root 'bin\seed.php'), 'reset') }
    'wp'       { Invoke-Wp $Rest }
    'composer' {
        $lsite = Get-LocalSite
        Push-Location $Root
        # Every PHP that Composer starts (@php scripts, vendor/bin tools such as
        # pest, phpcs and phpstan, build.php's nested composer install) must be
        # this same PHP with this same php.ini, which is what provides ext-zip,
        # ext-openssl and xdebug (for coverage). vendor/bin proxies run the first
        # `php` on PATH, so Local's PHP goes first, and PHPRC points every one of
        # them at the site's php.ini.
        $previousPhprc = $env:PHPRC
        $previousPath = $env:PATH
        $env:PHPRC = $lsite.PhpIni
        $env:PATH = (Split-Path -Parent $lsite.Php) + [IO.Path]::PathSeparator + $env:PATH
        try { & $lsite.Php -c $lsite.PhpIni -d display_startup_errors=0 $lsite.Composer @Rest }
        finally { $env:PHPRC = $previousPhprc; $env:PATH = $previousPath; Pop-Location }
    }
    'setup' {
        Assert-Dependencies
        Invoke-Link
        Invoke-Wp @('plugin', 'activate', $PluginSlug)
        Invoke-Wp @('eval-file', (Join-Path $Root 'bin\seed.php'))
        Write-Host ''
        Write-Host "Ready. The seed printed the sample event's URL above."
    }
}
