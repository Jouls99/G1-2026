$ErrorActionPreference = 'Stop'

$phpCommand = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpCommand) {
    throw 'No se encontró php.exe en PATH. Agregá PHP de XAMPP al PATH y volvé a ejecutar este script.'
}

$backupScript = Join-Path $PSScriptRoot 'backup.php'
if (-not (Test-Path -LiteralPath $backupScript -PathType Leaf)) {
    throw "No se encontró el script de respaldo: $backupScript"
}

$taskName = 'SOS Cosmeticos - Backup automatico'
$action = New-ScheduledTaskAction -Execute $phpCommand.Source -Argument "`"$backupScript`""
$triggers = @(
    (New-ScheduledTaskTrigger -Daily -At '00:00')
    (New-ScheduledTaskTrigger -Daily -At '06:00')
    (New-ScheduledTaskTrigger -Daily -At '12:00')
    (New-ScheduledTaskTrigger -Daily -At '18:00')
)
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $triggers `
    -Settings $settings `
    -Description 'Crea respaldos de la base sos_cosmeticos cada seis horas y conserva los últimos 30 días.' `
    -Force | Out-Null

Write-Output "Tarea '$taskName' programada para ejecutarse cada seis horas."
Write-Output "PHP: $($phpCommand.Source)"
Write-Output "Script: $backupScript"
