<#
.SYNOPSIS
    Puts the current commit on the test bench.

.DESCRIPTION
    Builds the package with `git archive`, which honours the export-ignore rules
    in .gitattributes, so what lands on the bench is exactly what would be
    distributed: no tests, no documents, no Composer files. Testing something
    else is testing the wrong thing.

    The bench is https://test.44123.it/oxysuppliers on the Hestia server
    ph.oxysoft.it. See docs/06_PIANO_TEST.md.
#>

[CmdletBinding()]
param(
    [string] $Server = 'ph.oxysoft.it',
    [string] $KeyPath = "$env:USERPROFILE\.ssh\oxy_cluster",
    [string] $SitePath = '/home/webtest/web/test.44123.it/public_html/oxysuppliers',
    [switch] $SkipActivate
)

$ErrorActionPreference = 'Stop'

$repo = Split-Path -Parent $PSScriptRoot
$slug = 'oxysuppliers-for-woocommerce'
$tar  = Join-Path $env:TEMP "$slug.tar"

Push-Location $repo
try {
    Write-Host 'Costruisco il pacchetto con git archive...'
    git archive --format=tar --prefix="$slug/" HEAD -o $tar
    if ($LASTEXITCODE -ne 0) { throw 'git archive fallito' }
}
finally {
    Pop-Location
}

Write-Host 'Carico...'
scp -i $KeyPath $tar "root@${Server}:/tmp/$slug.tar"
if ($LASTEXITCODE -ne 0) { throw 'scp fallito' }

# A plugin that is already active makes WP-CLI warn and return non-zero, which
# is not a failed deploy.
# A plugin that is already active makes WP-CLI warn on stderr and return
# non-zero, which is not a failed deploy — and PowerShell turns any stderr from
# a native command into an error record, so the warning has to go too.
$activate = if ($SkipActivate) { 'true' } else { "wp plugin activate $slug >/dev/null 2>&1 || true" }

# Composer files are export-ignored, so they travel separately: the plugin needs
# them to install its one runtime dependency, and the distributed package must
# not carry them.
scp -i $KeyPath (Join-Path $repo 'composer.json') "root@${Server}:/tmp/$slug-composer.json"
scp -i $KeyPath (Join-Path $repo 'composer.lock') "root@${Server}:/tmp/$slug-composer.lock"

$remote = @"
set -e
PLUGIN=$SitePath/wp-content/plugins/$slug
rm -rf \$PLUGIN
tar -xf /tmp/$slug.tar -C $SitePath/wp-content/plugins/

# The runtime dependency (Dompdf) is installed here rather than shipped in the
# repository: what git holds is the plugin's own code.
cp /tmp/$slug-composer.json \$PLUGIN/composer.json
cp /tmp/$slug-composer.lock \$PLUGIN/composer.lock
cd \$PLUGIN
composer install --no-dev --classmap-authoritative --no-interaction --quiet
rm -f \$PLUGIN/composer.json \$PLUGIN/composer.lock

chown -R webtest:webtest \$PLUGIN
sudo -u webtest -H bash -c "cd $SitePath && $activate"
sudo -u webtest -H bash -c "cd $SitePath && wp plugin list --format=csv --fields=name,status,version"
"@

# Written to a file and copied rather than piped into ssh: PowerShell encodes a
# pipeline to a native command with a byte order mark, and bash reads that mark
# as part of the first command. The failure reads "set: command not found",
# which points nowhere near the cause.
$remoteScript = Join-Path $env:TEMP "$slug-deploy.sh"
[System.IO.File]::WriteAllText(
    $remoteScript,
    ($remote -replace "`r`n", "`n"),
    (New-Object System.Text.UTF8Encoding $false)
)

scp -i $KeyPath $remoteScript "root@${Server}:/tmp/$slug-deploy.sh"
if ($LASTEXITCODE -ne 0) { throw 'scp dello script fallito' }

ssh -i $KeyPath "root@$Server" "bash /tmp/$slug-deploy.sh"
if ($LASTEXITCODE -ne 0) { throw 'deploy remoto fallito' }

Remove-Item $tar, $remoteScript -Force
Write-Host 'Fatto: https://test.44123.it/oxysuppliers/wp-admin/'
