using System.Globalization;
using System.IO;
using System.Management;
using System.Text.Json;
using Microsoft.Win32;

namespace TrackerAgent;

/// <summary>One installed program, read from the registry Uninstall keys.</summary>
public sealed record InstalledProgram(string Name, string? Version, string? Publisher, string? InstalledAt);

/// <summary>
/// Deep inventory of a machine: installed software, security posture,
/// licensing, storage health and OS state.
/// </summary>
/// <remarks>
/// Deliberately separate from <see cref="HardwareCollector"/>. The daemon calls
/// the hardware collector every 60 seconds, and the registry walk below plus
/// the WMI calls for antivirus/BitLocker/SMART cost seconds per device. Running
/// them on the heartbeat would slow the poll loop across the whole fleet and
/// push a multi-hundred-entry software list every minute. This runs once at
/// install and then at most daily.
///
/// Software is read from the registry, never from WMI's Win32_Product:
/// querying Win32_Product makes Windows run a consistency check over every MSI
/// product installed, which can trigger an unsolicited repair. On a fleet
/// reporting in the background, that is a real risk of breaking someone's
/// working applications.
/// </remarks>
public static class FingerprintCollector
{
    public static Dictionary<string, string> Collect()
    {
        var data = new Dictionary<string, string>();

        try { data["hostname"] = Environment.MachineName; } catch { }

        // Identity fields, so the server can attach this report to the same
        // asset the audit resolved to. Without them the fingerprint row sat at
        // asset_id = NULL and never appeared against the laptop it describes.
        // The device_id itself is injected by the caller, which already knows
        // the registered value: this class deliberately does not re-derive it.
        CollectIdentity(data);

        CollectSecurity(data);
        CollectLicensing(data);
        CollectOsState(data);
        CollectStorage(data);
        CollectProcessor(data);
        CollectMonitor(data);
        CollectStartup(data);
        CollectSoftware(data);

        return data.Where(kv => !string.IsNullOrWhiteSpace(kv.Value))
                   .ToDictionary(kv => kv.Key, kv => kv.Value);
    }

    // ---- Security posture -------------------------------------------------

    static void CollectSecurity(Dictionary<string, string> data)
    {
        CollectAntivirus(data);
        CollectFirewall(data);
        CollectBitLocker(data);
        CollectDomain(data);
        CollectUac(data);
        CollectTpm(data);
        CollectRdp(data);
        CollectLocalAdmins(data);
    }

    /// <summary>
    /// Antivirus product, real-time state and signature age.
    /// </summary>
    /// <remarks>
    /// MSFT_MpComputerStatus is the source of truth for Defender and exposes a
    /// real timestamp. The older SecurityCenter2 route decodes a bitfield whose
    /// "enabled" bit lives at 0x1000, not 0x10 — reading 0x10 reported a fully
    /// enabled Defender as switched off, which put a permanent false "Antivirus
    /// off" alarm on every healthy machine. A monitoring panel that cries wolf is
    /// worse than one that stays quiet, so the bitfield path is now only a
    /// fallback for third-party products that Defender's class does not cover.
    /// </remarks>
    static void CollectAntivirus(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                @"root\SecurityCenter2", "SELECT * FROM AntiVirusProduct");

            foreach (var obj in searcher.Get())
            {
                data["antivirus_product"] = obj["displayName"]?.ToString() ?? "";
                break;
            }
        }
        catch { }

        try
        {
            // Explicit column list on purpose: SELECT * over the CIM classes used
            // here fails to marshal some properties and takes the whole reader
            // down with a COMException, which is why the signature date kept
            // coming back empty even though the class plainly has it.
            using var searcher = new ManagementObjectSearcher(
                @"root\Microsoft\Windows\Defender",
                "SELECT AntivirusEnabled, RealTimeProtectionEnabled, AntivirusSignatureLastUpdated FROM MSFT_MpComputerStatus");

            foreach (var obj in searcher.Get())
            {
                var enabled = obj["AntivirusEnabled"];
                var realTime = obj["RealTimeProtectionEnabled"];

                if (enabled is not null)
                {
                    // Real-time protection is the setting that actually matters;
                    // prefer it, fall back to the overall switch.
                    var state = realTime is not null ? realTime : enabled;

                    data["antivirus_enabled"] = Convert.ToBoolean(state) ? "true" : "false";
                }

                // Belt and braces: if the CIM date will not marshal, fall back to
                // the modification time of the loaded definition folder, which is
                // the same thing Windows itself uses to age a signature set.
                var stamp = obj["AntivirusSignatureLastUpdated"];

                var updated = stamp switch
                {
                    DateTime value => value,
                    string text when DateTime.TryParse(text, CultureInfo.InvariantCulture,
                        DateTimeStyles.None, out var parsed) => parsed,
                    _ => (DateTime?)null,
                };

                if (updated is null)
                {
                    updated = ReadSignatureFolderAge();
                }

                if (updated is not null)
                {
                    var age = (int)Math.Max(0, (DateTime.Now.Date - updated.Value.Date).TotalDays);

                    data["antivirus_signature_age_days"] = age.ToString(CultureInfo.InvariantCulture);
                }

                break;
            }

            return;
        }
        catch
        {
            // No Defender class: a third-party product owns the machine, so fall
            // back to the product-state bitfield.
        }

        try
        {
            using var searcher = new ManagementObjectSearcher(
                @"root\SecurityCenter2", "SELECT * FROM AntiVirusProduct");

            foreach (var obj in searcher.Get())
            {
                var state = Convert.ToInt32(obj["productState"] ?? 0);

                // Byte 1 of the state field: 0x10 = enabled.
                if ((state & 0x1000) != 0) data["antivirus_enabled"] = "true";
                else if ((state & 0x0F00) == 0x1000 || (state & 0xF0) == 0x10) data["antivirus_enabled"] = "true";
                else if (state != 0) data["antivirus_enabled"] = "false";

                break;
            }
        }
        catch { }
    }

    /// <summary>
    /// Age of the currently loaded definition set, from its folder timestamp.
    /// </summary>
    static DateTime? ReadSignatureFolderAge()
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(
                @"SOFTWARE\Microsoft\Windows Defender\Signature Updates");

            var location = key?.GetValue("SignatureLocation") as string;

            if (string.IsNullOrWhiteSpace(location) || ! Directory.Exists(location)) return null;

            return Directory.GetLastWriteTime(location);
        }
        catch
        {
            return null;
        }
    }

    /// <summary>
    /// Firewall state per profile.
    /// </summary>
    /// <remarks>
    /// SecurityCenter2's FirewallProduct is empty on current Windows 11 builds,
    /// and the EnableFirewall registry values are gone too, which left the field
    /// permanently unreported. MSFT_NetFirewallProfile is the live source and
    /// names the profiles individually, so an operator can see that the machine
    /// is safe at home but exposed on a café network.
    /// </remarks>
    static void CollectFirewall(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                @"root\StandardCimv2", "SELECT * FROM MSFT_NetFirewallProfile");

            var profiles = 0;
            var off = new List<string>();

            foreach (var obj in searcher.Get())
            {
                var name = obj["Name"]?.ToString() ?? "";

                if (name.Length == 0) continue;

                profiles++;

                if (obj["Enabled"] is not null && ! Convert.ToBoolean(obj["Enabled"]))
                {
                    off.Add(name);
                }
            }

            if (profiles == 0) return;

            // Only a fully-off firewall is a critical finding; one profile off is
            // reported through firewall_profiles_off instead.
            data["firewall_enabled"] = off.Count == 0
                ? "true"
                : (off.Count == profiles ? "false" : "true");

            if (off.Count > 0)
            {
                data["firewall_profiles_off"] = string.Join(", ", off);
            }
        }
        catch { }
    }

    static void CollectBitLocker(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                @"root\SecurityCenter2", "SELECT * FROM Win32_EncryptableVolume");

            foreach (var obj in searcher.Get())
            {
                // ConversionStatus 1 = protection on. Only the first protected
                // volume counts, which is in practice the OS drive.
                if (Convert.ToInt32(obj["ConversionStatus"] ?? 0) == 1)
                {
                    data["bitlocker_status"] = "Enabled";
                    return;
                }
            }

            data["bitlocker_status"] = "Disabled";
        }
        catch
        {
            // No Win32_EncryptableVolume means BitLocker was never enabled on
            // this SKU, which carries the same risk as it being switched off.
            data["bitlocker_status"] = "Disabled";
        }
    }
    static void CollectDomain(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher("SELECT * FROM Win32_ComputerSystem");

            foreach (var obj in searcher.Get())
            {
                if (obj["PartOfDomain"] is not null)
                {
                    data["domain"] = Convert.ToBoolean(obj["PartOfDomain"])
                        ? "Domain joined"
                        : "Workgroup";
                }

                break;
            }
        }
        catch { }
    }

    static void CollectUac(Dictionary<string, string> data)
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(
                @"SOFTWARE\Microsoft\Windows\CurrentVersion\Policies\System");

            // EnableLUA = 0 means User Account Control is switched off entirely.
            if (key?.GetValue("EnableLUA") is int lua)
            {
                data["uac_enabled"] = lua == 0 ? "false" : "true";
            }
        }
        catch { }
    }

    /// <summary>
    /// TPM presence.
    /// </summary>
    /// <remarks>
    /// Win32_Tpm is "Invalid class" on most consumer Windows 11 machines even
    /// when the hardware is there, so a WMI-only check reports "absent" for
    /// perfectly good laptops. The TPM service key under the current control set
    /// is present whenever the machine has the firmware stack, so it is the more
    /// reliable signal.
    /// </remarks>
    static void CollectTpm(Dictionary<string, string> data)
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(
                @"SYSTEM\CurrentControlSet\Services\TPM\WMI");

            if (key is not null) data["tpm_present"] = "true";
        }
        catch { }
    }

    /// <summary>
    /// RDP is a single registry value rather than a WMI class, and it is the most
    /// common unintended remote-access exposure on a corporate laptop.
    /// </summary>
    static void CollectRdp(Dictionary<string, string> data)
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(
                @"SYSTEM\CurrentControlSet\Control\Terminal Server");

            if (key?.GetValue("fDenyTSConnections") is int denied)
            {
                data["rdp_enabled"] = denied == 1 ? "false" : "true";
            }
        }
        catch { }
    }

    static void CollectLocalAdmins(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT * FROM Win32_UserAccount WHERE LocalAccount=TRUE");

            var admins = 0;

            foreach (var obj in searcher.Get())
            {
                // SIDs ending -500 are the built-in Administrator/RDP/backup
                // service accounts that every machine has; counting them would
                // report "3 admins" on a machine nobody else can sign in to.
                if (obj["SID"]?.ToString()?.EndsWith("-500") == true) continue;

                if (obj["Disabled"] is not null && Convert.ToBoolean(obj["Disabled"])) continue;

                admins++;
            }

            if (admins > 0)
            {
                data["local_admin_count"] = admins.ToString(CultureInfo.InvariantCulture);
            }
        }
        catch { }
    }

    // ---- Licensing / OS state / storage -----------------------------------

    static void CollectLicensing(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher("SELECT * FROM Win32_OperatingSystem");

            foreach (var obj in searcher.Get())
            {
                data["windows_edition"] = obj["Caption"]?.ToString() ?? "";
                data["os_build"] = obj["Version"]?.ToString() ?? "";
            }
        }
        catch { }

        // Activation without shelling out to slmgr.
        //
        // Two traps here, both learned the hard way:
        //  - WQL "WHERE Name LIKE 'Windows%'" returned nothing, so the whole set
        //    is fetched and filtered in code instead.
        //  - the class lists every installed SKU, and almost all of the Windows
        //    rows are uninstalled remnants with LicenseStatus 0 and an empty
        //    partial key. Taking the first match reported a fully licensed OEM
        //    laptop as "unlicensed" — the same false-alarm class as the
        //    antivirus bug. A row is only accepted when it actually carries a
        //    partial product key, and a Licensed row always wins.
        try
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT Name, Description, LicenseStatus, PartialProductKey FROM SoftwareLicensingProduct");

            string? channel = null;
            bool? licensed = null;

            foreach (var obj in searcher.Get())
            {
                string name;
                string key;
                int status;

                // Per-row isolation: one SoftwareLicensingProduct row with
                // unreadsable properties throws out of the whole enumeration and
                // silently left activation unreported. A single bad row must not
                // cost the field entirely.
                try
                {
                    name = obj["Name"]?.ToString() ?? "";
                    key = obj["PartialProductKey"]?.ToString() ?? "";
                    status = Convert.ToInt32(obj["LicenseStatus"] ?? 0);
                }
                catch
                {
                    continue;
                }

                if (!name.StartsWith("Windows", StringComparison.OrdinalIgnoreCase)) continue;

                // Rows with an empty partial key are uninstalled remnants, not
                // the licence actually in force on this machine.
                if (key.Trim().Length == 0) continue;

                if (licensed == true) break;

                try
                {
                    channel = obj["Description"]?.ToString() ?? "";
                }
                catch
                {
                    channel = null;
                }

                licensed = status == 1;
            }

            if (licensed is not null)
            {
                data["license_channel"] = channel ?? "";
                data["activation_status"] = licensed.Value ? "Licensed" : "Unlicensed";
            }
        }
        catch { }
    }

    static void CollectOsState(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher("SELECT * FROM Win32_OperatingSystem");

            foreach (var obj in searcher.Get())
            {
                if (obj["InstallDate"] is not null)
                {
                    data["os_installed_at"] = Convert.ToDateTime(obj["InstallDate"]!)
                        .ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
                }

                if (obj["LastBootUpTime"] is not null)
                {
                    data["last_boot_at"] = Convert.ToDateTime(obj["LastBootUpTime"]!)
                        .ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture);
                }
            }
        }
        catch { }

        try { data["timezone"] = TimeZoneInfo.Local.DisplayName; } catch { }

        // A pending reboot means the last servicing operation did not finish, so
        // patch-compliance figures for this device are not yet trustworthy.
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(
                @"SYSTEM\CurrentControlSet\Control\Session Manager");

            if (key?.GetValue("PendingFileRenameOperations") is string[] { Length: > 0 })
            {
                data["pending_reboot"] = "true";
            }
        }
        catch { }
    }
    static void CollectStorage(Dictionary<string, string> data)
    {
        try
        {
            // Deliberately not "TOP 1 ... ORDER BY Size DESC": that form returned
            // nothing on this hardware and left disk_type permanently unset. All
            // fixed disks are read and the largest is picked in code instead.
            using var searcher = new ManagementObjectSearcher("SELECT * FROM Win32_DiskDrive");

            string? bestModel = null;
            string? bestInterface = null;
            ulong bestSize = 0;

            foreach (var obj in searcher.Get())
            {
                if (obj["Size"] is null) continue;

                var size = Convert.ToUInt64(obj["Size"]);

                if (size <= bestSize) continue;

                bestSize = size;
                bestModel = obj["Model"]?.ToString() ?? "";
                bestInterface = obj["InterfaceType"]?.ToString() ?? "";
            }

            if (bestModel is null) return;

            // Copied out of the loop variables so the compiler can see they are
            // non-null: bestInterface is still flagged as maybe-null on the
            // lines below because it is assigned inside the foreach.
            var model = bestModel;
            var interfaceType = bestInterface ?? "";

            // NVMe drives report InterfaceType "SCSI" on Windows, so the model
            // string is the only reliable NVMe signal.
            if (model.Contains("NVMe", StringComparison.OrdinalIgnoreCase)) data["disk_type"] = "NVMe";
            else if (model.Contains("SSD", StringComparison.OrdinalIgnoreCase)
                     || interfaceType.Contains("SSD", StringComparison.OrdinalIgnoreCase)) data["disk_type"] = "SSD";
            else if (interfaceType.Contains("Fixed", StringComparison.OrdinalIgnoreCase)) data["disk_type"] = "HDD";
        }
        catch { }

        CollectSmart(data);
    }

    /// <summary>
    /// SMART status: a failing drive predicts it before the disk dies, and that
    /// prediction is the only warning there is for planning a replacement.
    /// </summary>
    static void CollectSmart(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                @"root\WMI", "SELECT * FROM MSStorageDriver_FailurePredictStatus");

            foreach (var obj in searcher.Get())
            {
                var predictFailure = Convert.ToInt32(obj["PredictFailure"] ?? 0);

                data["smart_health"] = predictFailure == 0 ? "OK" : "At risk";

                if (predictFailure != 0) data["smart_predicted_failure"] = "true";

                break;
            }
        }
        catch
        {
            // No SMART provider is normal on many laptop SSDs, so absence is
            // reported as unavailable rather than as a passing result.
            data["smart_health"] = "Unavailable";
        }
    }

    static void CollectProcessor(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher("SELECT * FROM Win32_Processor");

            foreach (var obj in searcher.Get())
            {
                data["cpu_cores"] = Convert.ToInt32(obj["NumberOfLogicalProcessors"] ?? 0)
                    .ToString(CultureInfo.InvariantCulture);
                data["cpu_physical_cores"] = Convert.ToInt32(obj["NumberOfCores"] ?? 0)
                    .ToString(CultureInfo.InvariantCulture);

                break;
            }
        }
        catch { }
    }

    static void CollectMonitor(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT * FROM WmiMonitorID WHERE CurrentHorizontalActivePixels IS NOT NULL");

            foreach (var obj in searcher.Get())
            {
                var label = string.Join(' ', new[] {
                    DecodeMonitorString(obj["ManufacturerName"] as ushort[]),
                    DecodeMonitorString(obj["UserFriendlyName"] as ushort[]),
                }.Where(part => !string.IsNullOrWhiteSpace(part)).Distinct());

                if (!string.IsNullOrWhiteSpace(label)) data["monitor"] = label;

                break;
            }
        }
        catch { }
    }

    /// <summary>WmiMonitorID returns UTF-16LE as a NUL-padded ushort array.</summary>
    static string DecodeMonitorString(ushort[]? codes)
    {
        if (codes is not { Length: > 0 }) return string.Empty;

        return new string(codes.Select(c => (char)c).ToArray()).Trim('\0', ' ').Trim();
    }

    static void CollectStartup(Dictionary<string, string> data)
    {
        try
        {
            using var key = Registry.CurrentUser.OpenSubKey(
                @"Software\Microsoft\Windows\CurrentVersion\Run");

            var count = key?.GetValueNames().Length ?? 0;

            if (count > 0) data["startup_count"] = count.ToString(CultureInfo.InvariantCulture);
        }
        catch { }
    }

    /// <summary>
    /// Serial number and MAC, the same fields the audit uses to find an asset.
    /// </summary>
    static void CollectIdentity(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher("SELECT * FROM Win32_BIOS");

            foreach (var obj in searcher.Get())
            {
                data["serial_number"] = (obj["SerialNumber"]?.ToString() ?? "").Trim();
                break;
            }
        }
        catch { }

        try
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT * FROM Win32_NetworkAdapterConfiguration WHERE IPEnabled=TRUE");

            foreach (var obj in searcher.Get())
            {
                var mac = obj["MACAddress"]?.ToString();

                if (!string.IsNullOrWhiteSpace(mac))
                {
                    data["mac_address"] = mac;
                    break;
                }
            }
        }
        catch { }
    }

    // ---- Installed software -----------------------------------------------

    /// <summary>
    /// Registry paths holding the installed-program list. The 32-bit view is
    /// read as well because a large share of installed software lives there, and
    /// missing it would silently under-report exactly the old corporate clients
    /// an asset register is most often asked about.
    /// </summary>
    private static readonly string[] UninstallKeys =
    {
        @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall",
        @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall",
    };

    private const int MaxSoftwareEntries = 1500;

    /// <summary>
    /// camelCase to match the API contract. Must stay in step with the options
    /// Api.Fingerprint deserialises and re-serialises with, or the entries come
    /// back with null names and the server rejects the report.
    /// </summary>
    private static readonly JsonSerializerOptions SoftwareJson = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
    };

    /// <summary>
    /// Installed programs as a compact JSON array.
    /// </summary>
    /// <remarks>
    /// Sent as a JSON string rather than flattened key/value pairs because the
    /// agent's payload dictionary is string-typed and the server validates this
    /// endpoint as a real array. A flat "software.0.name" scheme would also make
    /// the request body ambiguous to anyone reading a captured packet.
    /// </remarks>
    static void CollectSoftware(Dictionary<string, string> data)
    {
        var programs = ReadInstalledPrograms();

        if (programs.Count == 0) return;

        data["software_count"] = programs.Count.ToString(CultureInfo.InvariantCulture);
        // camelCase to match the API contract: the server validates
        // software.*.name, and System.Text.Json would otherwise emit "Name".
        data["software"] = JsonSerializer.Serialize(programs, SoftwareJson);
    }

    public static List<InstalledProgram> ReadInstalledPrograms()
    {
        var found = new Dictionary<string, InstalledProgram>(StringComparer.OrdinalIgnoreCase);

        foreach (var path in UninstallKeys)
        {
            try
            {
                using var key = Registry.LocalMachine.OpenSubKey(path);

                if (key is null) continue;

                foreach (var subKeyName in key.GetSubKeyNames())
                {
                    try
                    {
                        // OpenSubKey returns null for a key that disappeared
                        // between the name listing and the open, which happens
                        // during uninstalls running concurrently.
                        using var subKey = key.OpenSubKey(subKeyName);

                        if (subKey is not null)
                        {
                            AddProgram(found, subKey);
                        }
                    }
                    catch
                    {
                        // A single unreadable key must not lose the whole list.
                    }
                }
            }
            catch { }
        }

        return found.Values
            .OrderBy(program => program.Name, StringComparer.OrdinalIgnoreCase)
            .Take(MaxSoftwareEntries)
            .ToList();
    }

    static void AddProgram(Dictionary<string, InstalledProgram> found, RegistryKey subKey)
    {
        // SystemComponent=1 marks the hundreds of hidden servicing stubs that are
        // not "installed software" in any sense an operator means, and including
        // them would triple the report while telling nobody anything. ParentKeyName
        // marks the same class of entry, so both filters are needed.
        if (subKey.GetValue("SystemComponent") is int component && component == 1) return;

        if (subKey.GetValue("ParentKeyName") is string parent && !string.IsNullOrWhiteSpace(parent)) return;

        var name = (subKey.GetValue("DisplayName") as string)?.Trim() ?? "";

        if (name.Length == 0 || name.StartsWith('{') || name.Length > 255) return;

        // Keyed on name+version so the 32-bit and 64-bit views of the same
        // program collapse into one row instead of appearing twice.
        var version = (subKey.GetValue("DisplayVersion") as string)?.Trim() ?? "";
        var key = $"{name}|{version}";

        if (found.ContainsKey(key)) return;

        found[key] = new InstalledProgram(
            name,
            NullIfEmpty(version),
            NullIfEmpty((subKey.GetValue("Publisher") as string)?.Trim()),
            NormaliseInstallDate(subKey.GetValue("InstallDate") as string)
        );
    }

    static string? NullIfEmpty(string? value) =>
        string.IsNullOrWhiteSpace(value) ? null : value;

    /// <summary>
    /// InstallDate is the registry's own "yyyymmdd" string, not a real date type.
    /// Anything else is dropped rather than parsed loosely, so a corrupt value
    /// cannot become a plausible-looking but wrong install date.
    /// </summary>
    static string? NormaliseInstallDate(string? raw)
    {
        if (raw is null || raw.Length != 8 || !raw.All(char.IsDigit)) return null;

        var month = raw.Substring(4, 2);
        var day = raw.Substring(6, 2);

        if (month is "00" || day is "00") return null;

        return $"{raw[..4]}-{month}-{day}";
    }
}
