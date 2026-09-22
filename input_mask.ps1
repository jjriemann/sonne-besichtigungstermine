# PowerShell Eingabemaske fuer Besichtigungstermine Workflow
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$scriptPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$configPath = Join-Path $scriptPath "config.json"

# Standardwerte falls keine config.json existiert
$config = @{
    ordnerPfad          = "Haeuser\Kohlhoefen\Besichtigungen\1 OG Li"
    datumIso            = (Get-Date).ToString("yyyy-MM-dd")
    besichtigungstag    = ""
    startUhrzeit        = "10:00"
    intervallMinuten    = 5
    sofortSenden        = $false
    exposeePfad         = "C:\ShareDrive\Verwaltung\Haeuser\Kohlhoefen\Photos\1OGlinks\Exposee\expose.pdf"
    selbstauskunftPfad  = "C:\ShareDrive\Verwaltung\Vorlagen\Neuvermietung\Mieterselbstauskunft_Sonne.pdf"
    startWorkflow       = $false
}

if (Test-Path $configPath) {
    try {
        $rawJson = Get-Content $configPath -Raw -Encoding UTF8
        $rawJson = $rawJson -replace '^\xEF\xBB\xBF', ''
        $jsonContent = $rawJson | ConvertFrom-Json
        if ($jsonContent.ordnerPfad) { $config.ordnerPfad = $jsonContent.ordnerPfad }
        if ($jsonContent.datumIso) { $config.datumIso = $jsonContent.datumIso }
        if ($jsonContent.startUhrzeit) { $config.startUhrzeit = $jsonContent.startUhrzeit }
        if ($jsonContent.intervallMinuten) { $config.intervallMinuten = [int]$jsonContent.intervallMinuten }
        if ($null -ne $jsonContent.sofortSenden) { $config.sofortSenden = [bool]$jsonContent.sofortSenden }
        if ($jsonContent.exposeePfad) { $config.exposeePfad = $jsonContent.exposeePfad }
        if ($jsonContent.selbstauskunftPfad) { $config.selbstauskunftPfad = $jsonContent.selbstauskunftPfad }
    } catch {
        # Fallback auf Standardwerte
    }
}

# Deutsches Datumsformat berechnen
function Get-GermanDateString([DateTime]$dt) {
    $culture = [System.Globalization.CultureInfo]::GetCultureInfo("de-DE")
    $weekday = $dt.ToString("dddd", $culture)
    $day = $dt.Day
    $monthYear = $dt.ToString("MMMM yyyy", $culture)
    return "$weekday, den $day. $monthYear"
}

# UI Fenster initialisieren
$form = New-Object System.Windows.Forms.Form
$form.Text = "Besichtigungstermine - Parameter festlegen"
$form.Size = New-Object System.Drawing.Size(650, 560)
$form.StartPosition = "CenterScreen"
$form.FormBorderStyle = "FixedDialog"
$form.MaximizeBox = $false
$form.MinimizeBox = $false
$form.Font = New-Object System.Drawing.Font("Segoe UI", 9.5)

# Titel
$lblTitle = New-Object System.Windows.Forms.Label
$lblTitle.Text = "Parameter für den Besichtigungsworkflow"
$lblTitle.Font = New-Object System.Drawing.Font("Segoe UI", 12, [System.Drawing.FontStyle]::Bold)
$lblTitle.Location = New-Object System.Drawing.Point(20, 15)
$lblTitle.AutoSize = $true
$form.Controls.Add($lblTitle)

# 1. Outlook Ordner
$lblOrdner = New-Object System.Windows.Forms.Label
$lblOrdner.Text = "Outlook Ordnerpfad (unterhalb Posteingang, z.B. Häuser\Kohlhöfen\...):"
$lblOrdner.Location = New-Object System.Drawing.Point(20, 55)
$lblOrdner.AutoSize = $true
$form.Controls.Add($lblOrdner)

$txtOrdner = New-Object System.Windows.Forms.TextBox
$txtOrdner.Text = $config.ordnerPfad
$txtOrdner.Location = New-Object System.Drawing.Point(20, 80)
$txtOrdner.Size = New-Object System.Drawing.Size(590, 25)
$form.Controls.Add($txtOrdner)

# 2. Besichtigungsdatum
$lblDatum = New-Object System.Windows.Forms.Label
$lblDatum.Text = "Besichtigungsdatum:"
$lblDatum.Location = New-Object System.Drawing.Point(20, 120)
$lblDatum.AutoSize = $true
$form.Controls.Add($lblDatum)

$dtPicker = New-Object System.Windows.Forms.DateTimePicker
$dtPicker.Format = [System.Windows.Forms.DateTimePickerFormat]::Short
$dtPicker.Location = New-Object System.Drawing.Point(20, 145)
$dtPicker.Size = New-Object System.Drawing.Size(150, 25)
try {
    $dtPicker.Value = [DateTime]::ParseExact($config.datumIso, "yyyy-MM-dd", $null)
} catch {
    $dtPicker.Value = Get-Date
}
$form.Controls.Add($dtPicker)

$lblFormattedDate = New-Object System.Windows.Forms.Label
$lblFormattedDate.Location = New-Object System.Drawing.Point(180, 148)
$lblFormattedDate.Size = New-Object System.Drawing.Size(430, 25)
$lblFormattedDate.ForeColor = [System.Drawing.Color]::DarkGreen
$lblFormattedDate.Font = New-Object System.Drawing.Font("Segoe UI", 9.5, [System.Drawing.FontStyle]::Bold)
$form.Controls.Add($lblFormattedDate)

# DatePicker Event Callback
$updateDateLabel = {
    $lblFormattedDate.Text = "Text in Mail: " + (Get-GermanDateString $dtPicker.Value)
}
$dtPicker.add_ValueChanged($updateDateLabel)
& $updateDateLabel

# 3. Uhrzeit & Intervall
$lblUhrzeit = New-Object System.Windows.Forms.Label
$lblUhrzeit.Text = "Start-Uhrzeit (HH:MM):"
$lblUhrzeit.Location = New-Object System.Drawing.Point(20, 185)
$lblUhrzeit.AutoSize = $true
$form.Controls.Add($lblUhrzeit)

$lblIntervall = New-Object System.Windows.Forms.Label
$lblIntervall.Text = "Dauer pro Termin (Minuten):"
$lblIntervall.Location = New-Object System.Drawing.Point(220, 185)
$lblIntervall.AutoSize = $true
$form.Controls.Add($lblIntervall)

$txtUhrzeit = New-Object System.Windows.Forms.TextBox
$txtUhrzeit.Text = $config.startUhrzeit
$txtUhrzeit.Location = New-Object System.Drawing.Point(20, 210)
$txtUhrzeit.Size = New-Object System.Drawing.Size(150, 25)
$form.Controls.Add($txtUhrzeit)

$numIntervall = New-Object System.Windows.Forms.NumericUpDown
$numIntervall.Location = New-Object System.Drawing.Point(220, 210)
$numIntervall.Size = New-Object System.Drawing.Size(150, 25)
$numIntervall.Minimum = 1
$numIntervall.Maximum = 120
$numIntervall.Value = [math]::Max(1, [math]::Min(120, $config.intervallMinuten))
$form.Controls.Add($numIntervall)

# 4. Anhang 1 (Exposee)
$lblExposee = New-Object System.Windows.Forms.Label
$lblExposee.Text = "Anhang 1: Exposee (PDF):"
$lblExposee.Location = New-Object System.Drawing.Point(20, 250)
$lblExposee.AutoSize = $true
$form.Controls.Add($lblExposee)

$txtExposee = New-Object System.Windows.Forms.TextBox
$txtExposee.Text = $config.exposeePfad
$txtExposee.Location = New-Object System.Drawing.Point(20, 275)
$txtExposee.Size = New-Object System.Drawing.Size(480, 25)
$form.Controls.Add($txtExposee)

$btnBrowseExposee = New-Object System.Windows.Forms.Button
$btnBrowseExposee.Text = "Durchsuchen..."
$btnBrowseExposee.Location = New-Object System.Drawing.Point(510, 274)
$btnBrowseExposee.Size = New-Object System.Drawing.Size(100, 27)
$btnBrowseExposee.add_Click({
    $dlg = New-Object System.Windows.Forms.OpenFileDialog
    $dlg.Filter = "PDF-Dateien (*.pdf)|*.pdf|Alle Dateien (*.*)|*.*"
    if (Test-Path $txtExposee.Text) {
        $dlg.InitialDirectory = Split-Path -Parent $txtExposee.Text
    }
    if ($dlg.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) {
        $txtExposee.Text = $dlg.FileName
    }
})
$form.Controls.Add($btnBrowseExposee)

# 5. Anhang 2 (Selbstauskunft)
$lblSelbst = New-Object System.Windows.Forms.Label
$lblSelbst.Text = "Anhang 2: Selbstauskunft (PDF):"
$lblSelbst.Location = New-Object System.Drawing.Point(20, 315)
$lblSelbst.AutoSize = $true
$form.Controls.Add($lblSelbst)

$txtSelbst = New-Object System.Windows.Forms.TextBox
$txtSelbst.Text = $config.selbstauskunftPfad
$txtSelbst.Location = New-Object System.Drawing.Point(20, 340)
$txtSelbst.Size = New-Object System.Drawing.Size(480, 25)
$form.Controls.Add($txtSelbst)

$btnBrowseSelbst = New-Object System.Windows.Forms.Button
$btnBrowseSelbst.Text = "Durchsuchen..."
$btnBrowseSelbst.Location = New-Object System.Drawing.Point(510, 339)
$btnBrowseSelbst.Size = New-Object System.Drawing.Size(100, 27)
$btnBrowseSelbst.add_Click({
    $dlg = New-Object System.Windows.Forms.OpenFileDialog
    $dlg.Filter = "PDF-Dateien (*.pdf)|*.pdf|Alle Dateien (*.*)|*.*"
    if (Test-Path $txtSelbst.Text) {
        $dlg.InitialDirectory = Split-Path -Parent $txtSelbst.Text
    }
    if ($dlg.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) {
        $txtSelbst.Text = $dlg.FileName
    }
})
$form.Controls.Add($btnBrowseSelbst)

# 6. Option: Sofort senden
$chkSofort = New-Object System.Windows.Forms.CheckBox
$chkSofort.Text = "E-Mails sofort senden (deaktiviert = E-Mail als Entwurf in Outlook zur Prüfung öffnen)"
$chkSofort.Checked = $config.sofortSenden
$chkSofort.Location = New-Object System.Drawing.Point(20, 385)
$chkSofort.Size = New-Object System.Drawing.Size(590, 30)
$form.Controls.Add($chkSofort)

# Buttons unten
$btnStart = New-Object System.Windows.Forms.Button
$btnStart.Text = "Workflow starten"
$btnStart.Font = New-Object System.Drawing.Font("Segoe UI", 10, [System.Drawing.FontStyle]::Bold)
$btnStart.BackColor = [System.Drawing.Color]::LightSkyBlue
$btnStart.Location = New-Object System.Drawing.Point(320, 440)
$btnStart.Size = New-Object System.Drawing.Size(160, 35)
$form.AcceptButton = $btnStart

$btnCancel = New-Object System.Windows.Forms.Button
$btnCancel.Text = "Abbrechen"
$btnCancel.Location = New-Object System.Drawing.Point(490, 440)
$btnCancel.Size = New-Object System.Drawing.Size(120, 35)
$form.CancelButton = $btnCancel

$form.Controls.Add($btnStart)
$form.Controls.Add($btnCancel)

# Event Handlers fuer Buttons
$script:userConfirmed = $false

$btnStart.add_Click({
    if ([string]::IsNullOrWhiteSpace($txtOrdner.Text)) {
        [System.Windows.Forms.MessageBox]::Show("Bitte geben Sie den Outlook-Ordnerpfad an.", "Eingabefehler", [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Warning)
        return
    }
    
    if ([string]::IsNullOrWhiteSpace($txtUhrzeit.Text)) {
        [System.Windows.Forms.MessageBox]::Show("Bitte geben Sie eine Start-Uhrzeit an (z.B. 10:00).", "Eingabefehler", [System.Windows.Forms.MessageBoxButtons]::OK, [System.Windows.Forms.MessageBoxIcon]::Warning)
        return
    }

    $script:userConfirmed = $true
    $form.DialogResult = [System.Windows.Forms.DialogResult]::OK
    $form.Close()
})

$btnCancel.add_Click({
    $script:userConfirmed = $false
    $form.DialogResult = [System.Windows.Forms.DialogResult]::Cancel
    $form.Close()
})

# Formular anzeigen
$result = $form.ShowDialog()

# Encoding ohne BOM verwenden
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

# Ergebnis verarbeiten
if ($script:userConfirmed -and ($result -eq [System.Windows.Forms.DialogResult]::OK)) {
    $outputConfig = [ordered]@{
        ordnerPfad          = $txtOrdner.Text.Trim()
        datumIso            = $dtPicker.Value.ToString("yyyy-MM-dd")
        besichtigungstag    = (Get-GermanDateString $dtPicker.Value)
        startUhrzeit        = $txtUhrzeit.Text.Trim()
        intervallMinuten    = [int]$numIntervall.Value
        sofortSenden        = $chkSofort.Checked
        exposeePfad         = $txtExposee.Text.Trim()
        selbstauskunftPfad  = $txtSelbst.Text.Trim()
        startWorkflow       = $true
    }
    
    $jsonOutput = $outputConfig | ConvertTo-Json -Depth 3
    [System.IO.File]::WriteAllText($configPath, $jsonOutput, $utf8NoBom)
    exit 0
} else {
    # Abbruch
    $config.startWorkflow = $false
    $jsonOutput = $config | ConvertTo-Json -Depth 3
    [System.IO.File]::WriteAllText($configPath, $jsonOutput, $utf8NoBom)
    exit 1
}
