# Paired with tracing.sh. Keep actions, environment defaults, mutations,
# messages, and failures equivalent; derive every repository path from this file.
# Remain compatible with stock Windows PowerShell 5.1 and check native exit codes.
[CmdletBinding()]
param(
    [ValidateSet('overlay', 'up', 'down', 'status')]
    [string]$Action = 'status'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$backendCompose = Join-Path $root 'locker-backend/docker-compose.yml'
$observabilityCompose = Join-Path $root 'locker-backend/docker-compose.observability.yml'

$signozHome = if ($env:SIGNOZ_DIR) {
    $env:SIGNOZ_DIR
} elseif ($env:HOME) {
    Join-Path $env:HOME '.open-locker/signoz'
} elseif ($env:USERPROFILE) {
    Join-Path $env:USERPROFILE '.open-locker/signoz'
} else {
    Join-Path $root '.open-locker/signoz'
}

$signozVersion = 'v0.99.0'
$signozUiPort = if ($env:SIGNOZ_UI_PORT) { $env:SIGNOZ_UI_PORT } else { '8085' }
$otlpHttpPort = if ($env:FORWARD_OTLP_HTTP_PORT) { $env:FORWARD_OTLP_HTTP_PORT } else { '4418' }
$otlpGrpcPort = if ($env:FORWARD_OTLP_GRPC_PORT) { $env:FORWARD_OTLP_GRPC_PORT } else { '4417' }

function Invoke-Checked {
    param(
        [Parameter(Mandatory)] [string]$Program,
        [Parameter(Mandatory)] [string[]]$Arguments
    )

    & $Program @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Program exited with code $LASTEXITCODE"
    }
}

function Invoke-TraceOverlay {
    $env:FORWARD_OTLP_HTTP_PORT = $otlpHttpPort
    $env:FORWARD_OTLP_GRPC_PORT = $otlpGrpcPort
    Invoke-Checked 'docker' @(
        'compose',
        '-f', $backendCompose,
        '-f', $observabilityCompose,
        'up', '-d'
    )
}

function Test-Url {
    param([Parameter(Mandatory)] [string]$Url)

    curl.exe -sS -f -o NUL $Url 2>$null
    return $LASTEXITCODE -eq 0
}

function Show-TraceStatus {
    $signozUrl = "http://localhost:$signozUiPort"
    $signozStatus = if (Test-Url $signozUrl) { $signozUrl } else { "DOWN - run 'just trace-up'" }
    Write-Output "SigNoz UI              $signozStatus"

    $collector = docker ps --filter 'name=otel-collector' --filter 'status=running' --format '{{.ID}}'
    if ($LASTEXITCODE -eq 0 -and $collector) {
        Write-Output "Our collector          up (host ports $otlpGrpcPort/$otlpHttpPort)"
    } else {
        Write-Output "Our collector          DOWN - run 'just trace-up'"
    }

    $app = docker ps --filter 'name=locker-backend-app-1' --filter 'status=running' --format '{{.ID}}'
    $instrumented = if ($LASTEXITCODE -eq 0 -and $app) {
        docker compose -f $backendCompose exec -T app printenv OTEL_EXPORTER_OTLP_ENDPOINT 2>$null
    } else {
        ''
    }
    if ($LASTEXITCODE -eq 0 -and $instrumented) {
        Write-Output 'Backend instrumented   yes'
    } else {
        Write-Output "Backend instrumented   NO - run 'just trace-up'"
    }

    Write-Output ''
    Write-Output 'Locker client / simulator:'
    Write-Output "  cd locker-client; `$env:OTEL_EXPORTER_OTLP_ENDPOINT='http://localhost:$otlpHttpPort'; pnpm sim"
}

function Get-SignozComposeArgument {
    $deploy = Join-Path $signozHome 'deploy/docker'
    $composeFile = Join-Path $deploy 'docker-compose.yaml'
    $overrideFile = Join-Path $deploy 'signoz-port-override.yml'

    if (-not (Test-Path -LiteralPath $composeFile -PathType Leaf)) {
        throw "SigNoz Compose file not found: $composeFile"
    }

    $arguments = @('compose', '-p', 'signoz', '-f', $composeFile)
    if (Test-Path -LiteralPath $overrideFile -PathType Leaf) {
        $arguments += @('-f', $overrideFile)
    }
    return $arguments
}

switch ($Action) {
    'overlay' {
        Write-Output 'Starting the stack with the tracing overlay...'
        Invoke-TraceOverlay
    }
    'up' {
        $signozGit = Join-Path $signozHome '.git'
        if (-not (Test-Path -LiteralPath $signozGit -PathType Container)) {
            if (Test-Path -LiteralPath $signozHome) {
                throw "SIGNOZ_DIR exists but is not a SigNoz Git checkout: $signozHome"
            }

            New-Item -ItemType Directory -Path (Split-Path -Parent $signozHome) -Force | Out-Null
            Invoke-Checked 'git' @(
                'clone', '-b', $signozVersion, '--depth', '1',
                'https://github.com/SigNoz/signoz.git', $signozHome
            )
        }

        $deploy = Join-Path $signozHome 'deploy/docker'
        $override = Join-Path $deploy 'signoz-port-override.yml'
        $overrideText = @"
services:
    signoz:
        ports: !override
            - "$signozUiPort`:8080"
"@
        $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($override, $overrideText, $utf8NoBom)

        Write-Output "Starting SigNoz (UI on port $signozUiPort)..."
        $signozArgs = Get-SignozComposeArgument
        Invoke-Checked 'docker' ($signozArgs + @('up', '-d', '--remove-orphans'))
        Invoke-TraceOverlay

        Write-Output 'Waiting for SigNoz to answer...'
        $ready = $false
        for ($i = 0; $i -lt 60; $i++) {
            if (Test-Url "http://localhost:$signozUiPort") {
                $ready = $true
                break
            }
            Start-Sleep -Seconds 2
        }
        Show-TraceStatus
        if (-not $ready) {
            throw "SigNoz did not become ready at http://localhost:$signozUiPort"
        }
    }
    'down' {
        Invoke-Checked 'docker' @('compose', '-f', $backendCompose, 'up', '-d', '--remove-orphans')
        if (Test-Path -LiteralPath (Join-Path $signozHome '.git') -PathType Container) {
            $signozArgs = Get-SignozComposeArgument
            Invoke-Checked 'docker' ($signozArgs + @('stop'))
        } else {
            Write-Output 'SigNoz checkout not found; nothing to stop.'
        }
        Write-Output 'Tracing off. SigNoz data is kept.'
    }
    'status' {
        Show-TraceStatus
    }
}
