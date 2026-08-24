param(
    [string]$Root = 'C:\Users\jonat\OneDrive\Desktop\Ophyra_space'
)

$resolvedRoot = [System.IO.Path]::GetFullPath($Root)
if (-not (Test-Path -LiteralPath $resolvedRoot -PathType Container)) {
    throw "Ophyra_space no existe: $resolvedRoot"
}

$episodes = foreach ($statusFile in Get-ChildItem -LiteralPath $resolvedRoot -Filter 'estado.txt' -File -Recurse) {
    $lines = @(Get-Content -LiteralPath $statusFile.FullName -Encoding UTF8)
    $state = if ($lines.Count -eq 1 -and $lines[0] -match '^ESTADO:\s*(PENDIENTE|LISTO)\s*$') {
        $Matches[1]
    } else {
        'INVALIDO'
    }

    $episodeDirectory = $statusFile.Directory
    $programDirectory = $episodeDirectory.Parent
    $videos = @(Get-ChildItem -LiteralPath $episodeDirectory.FullName -File | Where-Object {
        $_.Extension.ToLowerInvariant() -in @('.mp4', '.mov', '.mkv', '.m4v', '.avi', '.webm', '.mts', '.m2ts')
    })

    [PSCustomObject]@{
        Programa = $programDirectory.Name
        Episodio = $episodeDirectory.Name
        Estado = $state
        VideosRoot = $videos.Count
        Descripcion = Test-Path -LiteralPath (Join-Path $episodeDirectory.FullName 'descripcion.txt') -PathType Leaf
        Guion = Test-Path -LiteralPath (Join-Path $episodeDirectory.FullName 'guion.txt') -PathType Leaf
        Modificado = $statusFile.LastWriteTime
        Ruta = $episodeDirectory.FullName
    }
}

$episodes | Sort-Object @{Expression = { if ($_.Estado -eq 'PENDIENTE') { 0 } elseif ($_.Estado -eq 'INVALIDO') { 1 } else { 2 } }}, Modificado | Format-Table -AutoSize

if ($episodes.Estado -contains 'INVALIDO') {
    Write-Error 'Hay episodios con estado inválido. Solo se permiten PENDIENTE y LISTO.'
    exit 2
}
