Add-Type -AssemblyName System.IO.Compression.FileSystem
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$books = @{}
Get-ChildItem -LiteralPath (Split-Path $PSScriptRoot -Parent) -Filter '*.xlsx' | ForEach-Object {
    $zip = [IO.Compression.ZipFile]::OpenRead($_.FullName)
    try {
        function Read-Part($path) {
            $entry = $zip.GetEntry($path)
            if (!$entry) { return $null }
            $reader = [IO.StreamReader]::new($entry.Open())
            try { [xml]$part = $reader.ReadToEnd(); return $part } finally { $reader.Dispose() }
        }
        $ss = Read-Part 'xl/sharedStrings.xml'
        $strings = @($ss.sst.si | ForEach-Object { ($_.SelectNodes('.//*[local-name()="t"]') | ForEach-Object { $_.InnerText }) -join '' })
        $workbook = Read-Part 'xl/workbook.xml'
        $relations = Read-Part 'xl/_rels/workbook.xml.rels'
        $sheets = @{}
        foreach ($sheet in $workbook.workbook.sheets.sheet) {
            $id = $sheet.GetAttribute('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
            $target = ($relations.Relationships.Relationship | Where-Object Id -eq $id).Target
            $path = if ($target.StartsWith('/')) { $target.TrimStart('/') } else { 'xl/' + $target }
            $xml = Read-Part $path
            $rows = @($xml.worksheet.sheetData.row | ForEach-Object {
                $record = @{ row = [int]$_.r }
                foreach ($cell in $_.c) {
                    $value = $cell.v
                    if ($cell.t -eq 's') { $value = $strings[[int]$value] }
                    elseif ($cell.t -eq 'inlineStr') { $value = $cell.is.InnerText }
                    if ($null -ne $value -and "$value" -ne '') { $record[($cell.r -replace '\d', '')] = "$value" }
                }
                if ($record.Count -gt 1) { $record }
            })
            $sheets[$sheet.name] = $rows
        }
        $books[$_.Name] = $sheets
    } finally { $zip.Dispose() }
}
$books | ConvertTo-Json -Depth 12 -Compress
