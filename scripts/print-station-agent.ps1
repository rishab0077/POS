param(
    [string]$ConfigPath = "$PSScriptRoot\print-station-config.json"
)

$ErrorActionPreference = "Stop"
$AgentVersion = "1.0.0"

if (!(Test-Path -LiteralPath $ConfigPath)) {
    throw "Config file not found: $ConfigPath"
}

$Config = Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json
$BaseUrl = $Config.BaseUrl.TrimEnd("/")
$PollSeconds = if ($Config.PollSeconds) { [int]$Config.PollSeconds } else { 2 }
$Limit = if ($Config.Limit) { [int]$Config.Limit } else { 5 }
$Headers = @{ Authorization = "Bearer $($Config.Token)" }

if ($Config.ServiceClientId -and $Config.ServiceClientSecret) {
    $Headers["CF-Access-Client-Id"] = [string]$Config.ServiceClientId
    $Headers["CF-Access-Client-Secret"] = [string]$Config.ServiceClientSecret
}

Add-Type @"
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;

public class RawPrinterHelper
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
    public class DOCINFOA
    {
        [MarshalAs(UnmanagedType.LPStr)]
        public string pDocName;
        [MarshalAs(UnmanagedType.LPStr)]
        public string pOutputFile;
        [MarshalAs(UnmanagedType.LPStr)]
        public string pDataType;
    }

    [DllImport("winspool.Drv", EntryPoint = "OpenPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool OpenPrinter(string szPrinter, out IntPtr hPrinter, IntPtr pd);

    [DllImport("winspool.Drv", EntryPoint = "ClosePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool ClosePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "StartDocPrinterA", SetLastError = true, CharSet = CharSet.Ansi, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool StartDocPrinter(IntPtr hPrinter, int level, [In, MarshalAs(UnmanagedType.LPStruct)] DOCINFOA di);

    [DllImport("winspool.Drv", EntryPoint = "EndDocPrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool EndDocPrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "StartPagePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool StartPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "EndPagePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool EndPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "WritePrinter", SetLastError = true, ExactSpelling = true, CallingConvention = CallingConvention.StdCall)]
    public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);

    public static void SendBytesToPrinter(string printerName, byte[] bytes)
    {
        IntPtr printerHandle;
        if (!OpenPrinter(printerName.Normalize(), out printerHandle, IntPtr.Zero)) {
            throw new Win32Exception(Marshal.GetLastWin32Error(), "Unable to open printer: " + printerName);
        }

        IntPtr unmanagedBytes = IntPtr.Zero;
        try {
            DOCINFOA documentInfo = new DOCINFOA();
            documentInfo.pDocName = "Restaurant POS Print Job";
            documentInfo.pDataType = "RAW";

            if (!StartDocPrinter(printerHandle, 1, documentInfo)) {
                throw new Win32Exception(Marshal.GetLastWin32Error(), "Unable to start print document.");
            }

            if (!StartPagePrinter(printerHandle)) {
                throw new Win32Exception(Marshal.GetLastWin32Error(), "Unable to start print page.");
            }

            unmanagedBytes = Marshal.AllocCoTaskMem(bytes.Length);
            Marshal.Copy(bytes, 0, unmanagedBytes, bytes.Length);

            int written;
            if (!WritePrinter(printerHandle, unmanagedBytes, bytes.Length, out written) || written != bytes.Length) {
                throw new Win32Exception(Marshal.GetLastWin32Error(), "Unable to write all bytes to printer.");
            }

            EndPagePrinter(printerHandle);
            EndDocPrinter(printerHandle);
        }
        finally {
            if (unmanagedBytes != IntPtr.Zero) {
                Marshal.FreeCoTaskMem(unmanagedBytes);
            }
            ClosePrinter(printerHandle);
        }
    }
}
"@

function Invoke-StationApi {
    param(
        [string]$Method,
        [string]$Path,
        [object]$Body = $null
    )

    $Uri = "$BaseUrl$Path"
    if ($Body -eq $null) {
        return Invoke-RestMethod -Method $Method -Uri $Uri -Headers $Headers
    }

    return Invoke-RestMethod -Method $Method -Uri $Uri -Headers $Headers -ContentType "application/json" -Body ($Body | ConvertTo-Json -Depth 5)
}

function Get-PrinterName {
    param(
        [object]$PrinterMap,
        [string]$PrinterKey
    )

    if ($PrinterMap -eq $null) {
        throw "No printer map returned by Restaurant POS for this station."
    }

    $Property = $PrinterMap.PSObject.Properties[$PrinterKey]
    if ($Property -eq $null -or [string]::IsNullOrWhiteSpace($Property.Value)) {
        throw "No local printer configured for printer key '$PrinterKey'."
    }

    return [string]$Property.Value
}

function Test-PrinterReady {
    param([string]$PrinterName)

    $EscapedName = $PrinterName.Replace("\", "\\").Replace("'", "''")
    $Printer = Get-CimInstance -ClassName Win32_Printer -Filter "Name='$EscapedName'" -ErrorAction Stop

    if ($Printer -eq $null) {
        throw "Printer '$PrinterName' was not found on this Windows PC."
    }

    if ($Printer.WorkOffline) {
        throw "Printer '$PrinterName' is set to offline mode."
    }

    if ($Printer.PrinterStatus -eq 7) {
        throw "Printer '$PrinterName' is offline."
    }

    if ($Printer.DetectedErrorState -and $Printer.DetectedErrorState -notin @(0, 2)) {
        throw "Printer '$PrinterName' reports error state $($Printer.DetectedErrorState)."
    }
}

function Report-Printed {
    param([int]$JobId)
    Invoke-StationApi -Method "POST" -Path "/api/print-station/jobs/$JobId/printed" | Out-Null
}

function Report-Failed {
    param(
        [int]$JobId,
        [string]$Message
    )

    Invoke-StationApi -Method "POST" -Path "/api/print-station/jobs/$JobId/failed" -Body @{ error = $Message } | Out-Null
}

Write-Host "Restaurant POS print station agent $AgentVersion started."
Write-Host "Restaurant POS URL: $BaseUrl"

while ($true) {
    $PrinterMap = $null

    try {
        $Heartbeat = Invoke-StationApi -Method "POST" -Path "/api/print-station/heartbeat" -Body @{ version = $AgentVersion }
        $PrinterMap = $Heartbeat.station.printer_map
        $Response = Invoke-StationApi -Method "GET" -Path "/api/print-station/jobs?limit=$Limit"

        foreach ($Job in $Response.jobs) {
            try {
                if ($Job.payload_format -ne "escpos_base64") {
                    throw "Unsupported payload format '$($Job.payload_format)'."
                }

                $PrinterName = Get-PrinterName -PrinterMap $PrinterMap -PrinterKey $Job.printer_key
                Test-PrinterReady -PrinterName $PrinterName
                $Bytes = [Convert]::FromBase64String([string]$Job.payload)

                Write-Host "Printing job #$($Job.id) [$($Job.type)] to '$PrinterName'."
                [RawPrinterHelper]::SendBytesToPrinter($PrinterName, $Bytes)
                Report-Printed -JobId ([int]$Job.id)
            }
            catch {
                $Message = $_.Exception.Message
                Write-Host "Job #$($Job.id) failed: $Message"
                Report-Failed -JobId ([int]$Job.id) -Message $Message
            }
        }
    }
    catch {
        $Message = $_.Exception.Message
        Write-Host "Agent loop error: $Message"
        try {
            Invoke-StationApi -Method "POST" -Path "/api/print-station/heartbeat" -Body @{
                version = $AgentVersion
                last_error = $Message
            } | Out-Null
        }
        catch {
            Write-Host "Unable to report heartbeat error: $($_.Exception.Message)"
        }
    }

    Start-Sleep -Seconds $PollSeconds
}
