# Abre el SRS generado con Microsoft Word para actualizar el indice y los campos (numeros de
# pagina), guarda el .docx ya paginado y exporta una copia PDF de lectura.
# Requiere Word instalado en Windows; docx-js no puede calcular paginas por si mismo.
param(
    [string]$Docx = (Join-Path $PSScriptRoot '..\SRS_Produccion_CAB_v1.0.docx'),
    [string]$Pdf = (Join-Path $PSScriptRoot '..\SRS_Produccion_CAB_v1.0.pdf')
)
$ErrorActionPreference = 'Stop'
$Docx = (Resolve-Path $Docx).Path
$Pdf = [IO.Path]::GetFullPath($Pdf)
$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0
try {
    $doc = $word.Documents.Open($Docx, $false, $false, $false)
    $doc.Repaginate()
    foreach ($toc in $doc.TablesOfContents) { $toc.Update() }
    $null = $doc.Fields.Update()
    foreach ($toc in $doc.TablesOfContents) { $toc.UpdatePageNumbers() }
    $doc.Save()
    $paginas = $doc.ComputeStatistics(2)
    $doc.ExportAsFixedFormat($Pdf, 17)
    $doc.Close([ref]0)
    Write-Output "Indice actualizado. Paginas: $paginas"
    Write-Output "PDF: $Pdf"
}
finally {
    $word.Quit()
    [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($word)
}
