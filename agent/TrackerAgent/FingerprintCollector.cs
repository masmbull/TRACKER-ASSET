using System.Diagnostics;
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
        CollectMemorySlots(data);
        CollectMonitor(data);
        CollectStartup(data);
        CollectSignIn(data);
        CollectLockScreen(data);
        CollectFirmware(data);
        CollectChassis(data);
        CollectScreen(data);
        CollectDefenderDetail(data);
        CollectPatchState(data);
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
        CollectBitLockerMethod(data);
        CollectSecureBoot(data);
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
    /// <summary>
    /// Secure Boot, from the firmware rather than the OS.
    /// </summary>
    /// <remarks>
    /// The column and the UI row for this already existed and could only ever be
    /// NULL, because nothing collected it. A blank there is worse than a missing
    /// row: an operator scanning the security panel reads a blank as "unknown",
    /// while the truth was "never asked" - and Secure Boot is exactly the field
    /// that has to be known before a machine is trusted with a device certificate.
    ///
    /// Two sources, in order of authority. The registry key is what Windows
    /// itself caches, needs no elevation, and is present on anything that has
    /// ever booted in Secure Boot mode. Confirm-SecureBootUEFI is the direct
    /// firmware query and is authoritative when it answers, but it fails on
    /// legacy BIOS and where the key is missing, so it cannot be the only route.
    ///
    /// Reporting false is only safe when a source actually denied it. A machine
    /// where both sources error leaves the field null rather than claiming a
    /// definite "off": guessing "insecure" on a locked-down corporate laptop
    /// would be a false alarm, and the register is useless if it cries wolf.
    /// </remarks>
    static void CollectSecureBoot(Dictionary<string, string> data)
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(
                @"SYSTEM\CurrentControlSet\Control\SecureBoot\State");

            if (key?.GetValue("UEFISecureBootEnabled") is int enabled)
            {
                data["secure_boot_enabled"] = enabled == 1 ? "true" : "false";
                return;
            }
        }
        catch { }

        try
        {
            var exitCode = RunProcess("powershell", "-NoProfile -NonInteractive -Command " +
                "\"(Confirm-SecureBootUEFI) -eq $true\"", out var output);

            // Exit code 0 means the command actually ran. A non-zero exit is
            // "no UEFI Secure Boot" on a legacy BIOS box and a plain failure
            // anywhere else; the two cannot be told apart from the exit code
            // alone, so it stays null rather than guessed either way.
            if (exitCode == 0)
            {
                data["secure_boot_enabled"] = output.Trim()
                    .Equals("True", StringComparison.OrdinalIgnoreCase) ? "true" : "false";
            }
        }
        catch { }
    }

    /// <summary>
    /// Run a command out of process, returning its exit code and stdout.
    /// </summary>
    /// <remarks>
    /// Separate arguments are passed as an array rather than one quoted string.
    /// A PowerShell script containing quotes and $ signs has to be escaped by
    /// hand when it is jammed into a single argument string, and the failure mode
    /// of getting that wrong is a command that runs and returns nothing - which
    /// is indistinguishable from a machine that genuinely has no data.
    ///
    /// The timeout is the point of this method existing as a separate process at
    /// all. Anything launched in-process cannot be given up on, so a COM call
    /// that never returns would take the whole report with it.
    /// </remarks>
    static int RunProcess(string file, string[] arguments, out string output, int timeoutMs = 10_000)
    {
        var info = new ProcessStartInfo(file, arguments.Length == 0 ? string.Empty : string.Join(" ", arguments))
        {
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true,
        };

        using var process = Process.Start(info)
            ?? throw new InvalidOperationException("Could not start " + file);

        // Read before waiting, or a chatty child fills its pipe buffer and blocks
        // forever while we sit in WaitForExit. The reads are what make the timeout
        // mean anything.
        output = process.StandardOutput.ReadToEnd();
        process.StandardError.ReadToEnd();

        if (! process.WaitForExit(timeoutMs))
        {
            // Killing is best-effort: a process that has already gone away is
            // not an error worth propagating, and the caller treats a failed
            // timeout exactly like a failed command - the field stays absent.
            try
            {
                process.Kill(entireProcessTree: true);
            }
            catch
            {
                // Nothing useful to do; the reading is already abandoned.
            }

            throw new TimeoutException(file + " did not exit within " + timeoutMs + "ms.");
        }

        return process.ExitCode;
    }

    /// <summary>Convenience overload for a single pre-quoted argument string.</summary>
    static int RunProcess(string file, string arguments, out string output, int timeoutMs = 10_000)
        => RunProcess(file, new[] { arguments }, out output, timeoutMs);

    /// <summary>
    /// BitLocker encryption strength, which the status flag alone does not convey.
    /// </summary>
    /// <remarks>
    /// "Enabled" is not a security answer. A volume can be on XTS-AES-128 or on
    /// plain XTS with no cipher at all, and the second is encryption in name
    /// only. The bitlocker_method column and its UI row both already existed and
    /// were never populated by any collector, so this pairs with
    /// CollectBitLocker to finally make them mean something.
    ///
    /// ConversionMethod is the documented encoding: 0 none, 1 AES, 2 XTS,
    /// 3 XTS-AES. The mapping lives next to the WMI constant it depends on
    /// rather than being split into the caller.
    /// </remarks>
    static void CollectBitLockerMethod(Dictionary<string, string> data)
    {
        // root\CIMV2 is tried first and root\SecurityCenter2 second, because the
        // class is genuinely published in only one of them depending on the SKU
        // and BitLocker state: on this Home edition Win32_EncryptableVolume
        // returns nothing from CIMV2 and everything from SecurityCenter2, so
        // querying only the first quietly reported no encryption method on every
        // machine that has never had BitLocker configured. The two namespaces are
        // not interchangeable and picking one is a silent failure, not a fallback.
        foreach (var scope in new[] { @"root\CIMV2", @"root\SecurityCenter2" })
        {
            try
            {
                using var searcher = new ManagementObjectSearcher(
                    scope, "SELECT * FROM Win32_EncryptableVolume WHERE DriveLetter = 'C:'");

                foreach (var obj in searcher.Get())
                {
                    var method = Convert.ToInt32(obj["ConversionMethod"] ?? 0);

                    data["bitlocker_method"] = method switch
                    {
                        3 => "XTS-AES",
                        2 => "XTS",
                        1 => "AES",
                        _ => "None",
                    };

                    return;
                }
            }
            catch { }
        }
    }

    /// <summary>Whether the built-in Guest account can sign in without a password.</summary>
    /// <remarks>
    /// Guest is a real exposure on a Home edition - on by default there, off in
    /// Pro - so the register has to be able to show which of those a given laptop
    /// is. The account name is fixed by Windows, so reading its Disabled flag is
    /// enough and no enumeration is needed.
    /// </remarks>
    static void CollectGuestAccount(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT Disabled FROM Win32_UserAccount WHERE Name = 'Guest' AND LocalAccount = TRUE");

            foreach (var obj in searcher.Get())
            {
                if (obj["Disabled"] is not null)
                {
                    data["guest_account_enabled"] = Convert.ToBoolean(obj["Disabled"]) ? "false" : "true";
                }

                return;
            }
        }
        catch { }
    }

    /// <summary>
    /// Automatic sign-in: does this machine let itself in after a reboot?
    /// </summary>
    /// <remarks>
    /// Autologon with a stored password is the highest-severity reading in this
    /// collector. The machine signs itself in after every restart, so a stolen
    /// laptop opens onto a logged-in desktop with no barrier at all - and
    /// BitLocker on the disk does nothing about it, because the volume is already
    /// unlocked by the time the thief touches the keyboard.
    ///
    /// The password itself is never read. Only its presence as a value matters,
    /// and shipping it off the machine would create a secret the server has no
    /// business holding. Both the username and the password must be present: a
    /// leftover username with no password is not an autologon.
    /// </remarks>
    static void CollectSignIn(Dictionary<string, string> data)
    {
        try
        {
            using var winlogon = Registry.LocalMachine.OpenSubKey(
                @"SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon");

            var user = winlogon?.GetValue("DefaultUserName") as string;
            var password = winlogon?.GetValue("DefaultPassword") as string;

            data["auto_logon_enabled"] =
                !string.IsNullOrWhiteSpace(user) && !string.IsNullOrWhiteSpace(password)
                    ? "true"
                    : "false";
        }
        catch { }

        CollectGuestAccount(data);
    }

    /// <summary>
    /// Whether the screen locks on its own, and after how long.
    /// </summary>
    /// <remarks>
    /// This is the practical half of a lost-laptop assessment. BitLocker protects
    /// a machine that is powered down, but a session left unlocked by a walk-away
    /// is fully readable, so "encrypted" and "safe to lose" are not the same
    /// answer and the register has to be able to show both.
    ///
    /// These settings live under the user profile rather than on the machine, so
    /// this reads the profile belonging to the account the agent runs as. Where
    /// the person at the keyboard differs from that account it is a partial
    /// answer; nothing is invented to cover the gap, because publishing one
    /// person's screen-lock setting as the machine's would be a security finding
    /// in the wrong direction. That user's next fingerprint corrects it.
    ///
    /// A zero timeout with the switch on means "lock immediately". That is a real
    /// and desirable setting, so it is reported as lock-on with no minute count,
    /// never as "no timeout configured".
    /// </remarks>
    static void CollectLockScreen(Dictionary<string, string> data)
    {
        try
        {
            // The per-user desktop settings live at "Control Panel\Desktop"
            // directly under the profile root, NOT inside the "Desktop" folder -
            // there is no such profile subfolder at all, so the previous lookup
            // found no directory, silently returned, and left both screen-lock
            // fields absent on every machine.
            var desktop = Registry.CurrentUser.OpenSubKey(@"Control Panel\Desktop");

            if (desktop?.GetValue("ScreenSaveActive") is not string active)
            {
                return;
            }

            var enabled = active == "1";
            data["screen_lock_enabled"] = enabled ? "true" : "false";

            if (! enabled) return;

            // A missing or zero timeout with the switch on means "lock
            // immediately", which is a real and desirable setting. It is
            // reported as lock-on with no minute count rather than as a
            // malformed reading, and never as "no timeout configured".
            if (desktop.GetValue("ScreenSaveTimeOut") is string timeout
                && int.TryParse(timeout, NumberStyles.Integer, CultureInfo.InvariantCulture, out var seconds)
                && seconds > 0)
            {
                data["lock_timeout_minutes"] = Math.Round(seconds / 60.0)
                    .ToString(CultureInfo.InvariantCulture);
            }
            else
            {
                data["lock_timeout_minutes"] = "0";
            }
        }
        catch { }
    }

    /// <summary>BIOS/UEFI version and its release date.</summary>
    /// <remarks>
    /// Firmware age decides whether a machine is even eligible for current
    /// security features, and a Secure Boot reading cannot be interpreted without
    /// knowing what the firmware supports at all.
    ///
    /// ReleaseDate goes through the DMTF parser for the same reason the OS install
    /// date needs it: it arrives as a string, and Convert.ToDateTime throws on it,
    /// which is what kept this field empty every time it was filled in by hand.
    /// </remarks>
    static void CollectFirmware(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT SMBIOSBIOSVersion, ReleaseDate FROM Win32_BIOS");

            foreach (var obj in searcher.Get())
            {
                data["bios_version"] = (obj["SMBIOSBIOSVersion"]?.ToString() ?? "").Trim();

                var released = ParseWmiDate(obj["ReleaseDate"]);

                if (released is not null)
                {
                    data["bios_date"] = released.Value.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
                }

                break;
            }
        }
        catch { }
    }

    /// <summary>Laptop-or-desktop, and the model string, read rather than typed in.</summary>
    /// <remarks>
    /// Chassis is what separates a laptop from a desktop in an asset register, and
    /// today somebody types it by hand into every record - the one field where
    /// transcription errors are a matter of time.
    ///
    /// ChassisTypes is a bitfield of the shapes the case *could* be, not a
    /// statement of what this machine is, so it is read as a hint only: every
    /// bit Windows uses for a portable enclosure is checked, and anything else
    /// that is not genuinely unspecified is reported as a desktop.
    /// </remarks>
    static void CollectChassis(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher("SELECT * FROM Win32_SystemEnclosure");

            foreach (var obj in searcher.Get())
            {
                // ChassisTypes is a uint16 ARRAY on most firmware, not the single
                // uint32 the documentation implies, and it arrives boxed as
                // object. Convert.ToUInt32 on the array threw, the catch-all ate
                // it, and chassis_type was never set on any machine - which is
                // how the one field an operator would never have typed by hand
                // turned out to be silently empty too.
                //
                // Every value in the array is considered, because the list means
                // "any of these shapes", and a portable bit alongside a generic
                // one still makes it a laptop.
                var raw = obj["ChassisTypes"];

                var types = new List<ulong>();

                if (raw is Array list)
                {
                    foreach (var entry in list)
                    {
                        try
                        {
                            types.Add(entry is null ? 0UL : Convert.ToUInt64(entry));
                        }
                        catch { }
                    }
                }
                else if (raw is not null)
                {
                    try { types.Add(Convert.ToUInt64(raw)); } catch { }
                }

                // 8, 9, 10, 14, 30, 31, 32, 33 are portable, laptop, notebook,
                // sub-notebook, handheld, docking station and all-in-one. Only
                // the genuinely portable ones count; a docking station is a
                // laptop accessory, not a laptop.
                var portableBits = new ulong[] { 8, 9, 10, 14, 30, 31, 32, 33 };
                bool portable = types.Any(t => portableBits.Contains(t));

                if (portable)
                {
                    data["chassis_type"] = "Laptop";
                }
                else if (types.Any(t => t != 0))
                {
                    data["chassis_type"] = "Desktop";
                }

                break;
            }
        }
        catch { }

        try
        {
            using var searcher = new ManagementObjectSearcher("SELECT Model FROM Win32_ComputerSystem");

            foreach (var obj in searcher.Get())
            {
                data["device_model"] = (obj["Model"]?.ToString() ?? "").Trim();
                break;
            }
        }
        catch { }
    }

    /// <summary>Screen resolution, when there is a real session to measure.</summary>
    /// <remarks>
    /// Reported only for an interactive session. A service session has no
    /// meaningful resolution, and publishing the 1024x768 placeholder from one
    /// would look exactly like a real finding about a broken display driver.
    /// </remarks>
    static void CollectScreen(Dictionary<string, string> data)
    {
        try
        {
            if (! Environment.UserInteractive) return;

            var screen = System.Windows.Forms.Screen.PrimaryScreen;

            if (screen is null) return;

            var size = screen.Bounds.Size;

            if (size.Width > 0 && size.Height > 0)
            {
                data["screen_resolution"] = size.Width.ToString(CultureInfo.InvariantCulture)
                    + 'x'
                    + size.Height.ToString(CultureInfo.InvariantCulture);
            }
        }
        catch { }
    }

    /// <summary>Real-time protection, and how long ago Defender last scanned.</summary>
    /// <remarks>
    /// antivirus_enabled already exists but reports the product as a whole.
    /// Real-time protection is a separate switch that can read as on while the
    /// engine is not actually inspecting anything, and a machine that has never
    /// run a scan is a materially different risk from one that scans daily. Both
    /// readings are needed before "antivirus: yes" can be taken at face value.
    ///
    /// The scan age is in whole days to match antivirus_signature_age_days so the
    /// two compare directly. Fresh definitions on a machine whose last scan was
    /// months ago means updates are arriving on a device that is not being
    /// checked - the pair together catches what either one misses.
    /// </remarks>
    static void CollectDefenderDetail(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                @"root\Microsoft\Windows\Defender",
                "SELECT RealTimeProtectionEnabled, QuickScanEndTime, FullScanEndTime FROM MSFT_MpComputerStatus");

            foreach (var obj in searcher.Get())
            {
                var realTime = obj["RealTimeProtectionEnabled"];

                if (realTime is not null)
                {
                    data["defender_realtime_enabled"] = Convert.ToBoolean(realTime) ? "true" : "false";
                }

                // The newer scan wins. A machine that ran a quick scan this
                // morning is better protected than one whose last full scan was
                // recent but whose quick scans have silently stopped, and
                // reporting only one of the two would miss exactly that case.
                var quick = ParseWmiDate(obj["QuickScanEndTime"]);
                var full = ParseWmiDate(obj["FullScanEndTime"]);

                var last = quick is null
                    ? full
                    : (full is null ? quick : (quick > full ? quick : full));

                if (last is not null)
                {
                    var days = (int) Math.Max(0, (DateTime.Now.Date - last.Value.LocalDateTime.Date).TotalDays);

                    data["defender_last_scan_days"] = days.ToString(CultureInfo.InvariantCulture);
                }

                break;
            }
        }
        catch { }
    }

    /// <summary>
    /// Patch state: what has been applied, what failed, and what is waiting.
    /// </summary>
    /// <remarks>
    /// Three sources, deliberately split by cost because they differ by orders of
    /// magnitude on this very machine.
    ///
    /// Win32_QuickFixEngineering is instant and needs no COM at all. It gives the
    /// hotfix count and the date of the last patch, which is the "applied" side.
    ///
    /// QueryHistory answers in 358 ms measured here, across 152 entries, and adds
    /// the two things that matter most for CVE tracking: when the machine last
    /// took a security update, and how many updates FAILED. A hotfix that failed
    /// to apply is not protection, yet it still shows up in the hotfix inventory
    /// as though it landed - so the failure count is genuinely new information
    /// that none of the other readings can express.
    ///
    /// Search took 30.9 seconds on this laptop, on a healthy connection, to
    /// return 3 pending updates. It is the only way to know what is waiting, and
    /// also the one call that can stall an entire fleet behind one slow network.
    /// It therefore runs in a child process with a hard timeout and reports
    /// nothing at all when the timeout fires.
    ///
    /// That leaves windows_updates_pending null more often than not, and that is
    /// the correct outcome rather than a shortcoming. A count obtained by giving
    /// up on a slow query is indistinguishable from zero, and "this fleet has no
    /// pending updates" is the most dangerous claim a patch register can make.
    /// </remarks>
    static void CollectPatchState(Dictionary<string, string> data)
    {
        CollectAppliedPatches(data);
        CollectWuaHistory(data);
        CollectPendingUpdates(data);
    }

    /// <summary>Applied hotfixes, from WMI. Instant, no COM.</summary>
    static void CollectAppliedPatches(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT * FROM Win32_QuickFixEngineering");

            var installed = 0;
            DateTimeOffset? newest = null;

            foreach (var obj in searcher.Get())
            {
                installed++;

                // Per-row isolation, for the same reason the licensing scan has
                // it: one unreadable row must not cost the whole field.
                //
                // ParseWmiDate handles every shape this column arrives in -
                // a real DateTime, a DMTF string, or the localised "9/8/2026"
                // that Win32_QuickFixEngineering actually sends. Testing the
                // types here instead duplicated that knowledge and got the
                // short-date case wrong.
                try
                {
                    var patched = ParseWmiDate(obj["InstalledOn"]);

                    if (patched is not null && (newest is null || patched > newest))
                    {
                        newest = patched;
                    }
                }
                catch { }
            }

            if (newest is not null)
            {
                data["last_hotfix_installed_at"] = newest.Value
                    .ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
            }

            if (installed > 0)
            {
                data["hotfix_count"] = installed.ToString(CultureInfo.InvariantCulture);
            }
        }
        catch { }
    }

    /// <summary>
    /// Newest security update, and how many updates failed, from the WUA history.
    /// </summary>
    /// <remarks>
    /// QueryHistory reads a local cache instead of contacting Microsoft, which is
    /// why it answers in a fraction of a second where Search takes half a minute.
    /// It is the cheap half of Windows Update and safe to run every time.
    ///
    /// The security filter is deliberately narrow, and the reason is visible in
    /// the real history: 152 entries on this laptop, the overwhelming majority of
    /// them Defender definition updates arriving several times a day. Counting
    /// those would make a machine look permanently and continuously patched. A
    /// title must mention a KB article AND a security or cumulative update, which
    /// excludes definitions and keeps exactly the monthly rollup and the .NET
    /// security patches - the updates that actually carry CVE fixes.
    ///
    /// Dates are formatted by PowerShell rather than parsed in C#. The COM API
    /// hands them back as local-formatted strings, and hard-coding a format here
    /// is how a patch register ends up claiming a December install for a
    /// September update.
    ///
    /// ResultCode 2 is Succeeded; everything else counts as a failure. That is the
    /// point: ten failed entries exist on this machine and they are invisible to
    /// every other reading in this file.
    /// </remarks>
    static void CollectWuaHistory(Dictionary<string, string> data)
    {
        const string script = @"
$ErrorActionPreference = 'Stop'
$ses = New-Object -ComObject Microsoft.Update.Session
$sr = $ses.CreateUpdateSearcher()
$n = $sr.GetTotalHistoryCount()
if ($n -lt 1) { Write-Output 'HISTORY_NONE'; exit 0 }
$hist = $sr.QueryHistory(0, $n)
$ok = 0
$failed = 0
$securityCount = 0
$lastSecurity = ''
foreach ($e in $hist) {
  if ($e.ResultCode -eq 2) { $ok++ } else { $failed++ }
  $t = [string]$e.Title
  if ($t -match 'KB\d+' -and $t -match '(?i)security update|cumulative update') {
    $securityCount++
    if ($lastSecurity -eq '') { $lastSecurity = $e.Date.ToString('yyyy-MM-dd') }
  }
}
Write-Output ('OK=' + $ok)
Write-Output ('FAILED=' + $failed)
Write-Output ('SECCOUNT=' + $securityCount)
Write-Output ('LASTSEC=' + $lastSecurity)
";

        // Deliberately a chain of independent `if` statements rather than one
        // `elseif` chain. The mutually exclusive shape invites a reader to
        // "tidy" it back into elseif, and that fails to compile here: inside a
        // `foreach` over a deconstructed tuple the parser rejects `elseif`
        // following the block, with a bare "; expected" on the closing brace and
        // no hint that the real problem is a few lines up. Four independent
        // comparisons are trivially cheap and cannot be misread.
        foreach (var (key, value) in ReadWuaOutput(script, 20_000))
        {
            if (key == "OK") {
                data["updates_succeeded"] = value;
            }

            if (key == "FAILED") {
                data["updates_failed"] = value;
            }

            if (key == "SECCOUNT") {
                data["security_updates_installed"] = value;
            }

            if (key == "LASTSEC" && value.Length > 0) {
                data["last_security_update_at"] = value;
            }
        }
    }

    /// <summary>Count of updates waiting, when Windows Update answers in time.</summary>
    /// <remarks>
    /// This is the one call that needs the hard timeout, and it is isolated in
    /// its own child process precisely so a machine on a slow link cannot hold up
    /// the report behind it. 25 seconds was chosen against the measured 30.9 s on
    /// a healthy local connection: a laptop that cannot answer in that window is
    /// behind a network, and the number it would eventually return is not worth a
    /// stalled fleet.
    ///
    /// Nothing is written when the timeout fires. No "unknown", no partial value
    /// - only a real count or nothing at all.
    /// </remarks>
    static void CollectPendingUpdates(Dictionary<string, string> data)
    {
        const string script = @"
$ErrorActionPreference = 'Stop'
$ses = New-Object -ComObject Microsoft.Update.Session
$sr = $ses.CreateUpdateSearcher()
$r = $sr.Search('IsInstalled=0 and IsHidden=0')
Write-Output ('PENDING=' + $r.Updates.Count)
$security = 0
foreach ($u in $r.Updates) {
  if ($u.Title -match '(?i)security update|cumulative update|\(KB\d+\)') { $security++ }
}
Write-Output ('PENDING_SECURITY=' + $security)
";

        // Independent `if` statements, not `elseif` - see the note on the same
        // pattern in CollectWuaHistory.
        foreach (var (key, value) in ReadWuaOutput(script, 25_000))
        {
            if (key == "PENDING") {
                data["windows_updates_pending"] = value;
            }

            if (key == "PENDING_SECURITY") {
                data["security_updates_pending"] = value;
            }
        }
    }

    /// <summary>
    /// Run a KEY=value script and parse the output, or return nothing at all.
    /// </summary>
    /// <remarks>
    /// Every failure mode collapses to an empty result on purpose: a non-zero
    /// exit, a timeout, an exception, an unreadable line. The callers then write
    /// no field at all, which is what keeps "we could not ask" distinct from
    /// "there is nothing to report" across the whole database.
    /// </remarks>
    static List<(string Key, string Value)> ReadWuaOutput(string script, int timeoutMs)
    {
        try
        {
            var exit = RunProcess(
                "powershell",
                new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script },
                out var output,
                timeoutMs);

            if (exit != 0) return new List<(string, string)>();

            var pairs = new List<(string, string)>();

            foreach (var raw in output.Split('\n'))
            {
                var line = raw.Trim();
                var split = line.IndexOf('=');

                if (split <= 0) continue;

                pairs.Add((line[..split], line[(split + 1)..]));
            }

            return pairs;
        }
        catch
        {
            // Includes the timeout case: RunProcess kills the child before
            // throwing, so there is nothing left to salvage.
            return new List<(string, string)>();
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
                // Each date is parsed on its own so one unparseable value cannot
                // take the other down with it. They used to share a single try,
                // and InstallDate throwing meant LastBootUpTime was never even
                // read - both columns stayed NULL on every machine, which reads
                // as "this laptop has no install date" rather than "the probe is
                // broken".
                var installed = ParseWmiDate(obj["InstallDate"]);

                if (installed is not null)
                {
                    data["os_installed_at"] = installed.Value.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture);
                }

                var booted = ParseWmiDate(obj["LastBootUpTime"]);

                if (booted is not null)
                {
                    data["last_boot_at"] = booted.Value.ToLocalTime()
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

    /// <summary>
    /// Parse a WMI date, which arrives as a DMTF string, not a DateTime.
    /// </summary>
    /// <remarks>
    /// WMI reports timestamps as "20260813063802.000000+420". Converting that
    /// with Convert.ToDateTime throws on every machine, and because the calls
    /// sat inside a catch-all the failure was invisible: os_installed_at and
    /// last_boot_at were permanently NULL across the whole fleet, and a register
    /// showing no install date is indistinguishable from one that never asked.
    ///
    /// ManagementDateTimeConverter is the supported reader for this format. The
    /// DateTimeOffset result is deliberate - it keeps the UTC offset Windows
    /// supplied, so a machine on UTC+07:00 does not have its last-boot time
    /// silently shifted by seven hours against every other fleet row.
    /// </remarks>
    static DateTimeOffset? ParseWmiDate(object? value)
    {
        if (value is null)
        {
            return null;
        }

        // System.Management hands the DMTF string over as a string; be tolerant
        // anyway, because a future provider could hand back a real DateTime.
        if (value is DateTimeOffset already)
        {
            return already;
        }

        if (value is DateTime asDateTime)
        {
            return new DateTimeOffset(asDateTime);
        }

        // The WMI provider is not obliged to hand back DMTF, and the two shapes
        // have to be told apart before either parser is tried - in the wrong
        // order they produce confidently wrong dates rather than no date.
        //
        // Win32_OperatingSystem.InstallDate is DMTF: "20260813063802.000000+420",
        // which ManagementDateTimeConverter reads and DateTimeOffset.TryParse
        // rejects outright. Win32_QuickFixEngineering.InstalledOn is a localised
        // short date: "9/8/2026", which the converter rejects with
        // ArgumentOutOfRangeException.
        //
        // Detecting DMTF by shape rather than by trying one parser and seeing
        // what happens is what makes this safe. A bare TryParse accepts the DMTF
        // string under some locales and misreads it - reading a September install
        // as 8 December - and a hotfix date silently landing in the wrong month
        // is far worse than a field left empty.
        var text = value.ToString()?.Trim() ?? "";

        if (text.Length == 0)
        {
            return null;
        }

        bool looksLikeDmtf = text.Length >= 14
            && text[0..4].All(char.IsDigit)
            && text.All(c => char.IsDigit(c) || c == '.' || c == '+' || c == '-');

        if (looksLikeDmtf)
        {
            try
            {
                return ManagementDateTimeConverter.ToDateTime(text);
            }
            catch (ManagementException) { return null; }
            catch (ArgumentException) { return null; }
            catch (FormatException) { return null; }
            catch (UnauthorizedAccessException) { return null; }
            catch (OverflowException) { return null; }
        }

        // Not DMTF, so a localised date string. The CURRENT culture is the only
        // correct reader here, and using the invariant one instead is a
        // month-level data error rather than a formatting quirk.
        //
        // This machine reports CurrentCulture = en-ID, under which "9/8/2026" is
        // the 8th of September. Parsed invariant it becomes the 9th of September,
        // and parsed against an en-US locale - which is what a hard-coded
        // invariant assumption implies - it would be the 9th again. The month is
        // wrong either way except by accident, and a patch register that claims
        // a December install for a September hotfix is worse than useless.
        //
        // The current culture is therefore authoritative and the invariant parse
        // is a last resort for a machine whose locale genuinely rejects the
        // string. Neither is trusted enough to be worth a second opinion when
        // they disagree: the machine's own locale is the only evidence there is.
        try
        {
            if (DateTimeOffset.TryParse(
                    text,
                    CultureInfo.CurrentCulture,
                    DateTimeStyles.AllowWhiteSpaces,
                    out var localised))
            {
                return localised;
            }

            return DateTimeOffset.TryParse(
                text,
                CultureInfo.InvariantCulture,
                DateTimeStyles.AllowWhiteSpaces,
                out var invariant)
                ? invariant
                : null;
        }
        catch (ManagementException)
        {
            return null;
        }
        catch (ArgumentException)
        {
            return null;
        }
        catch (FormatException)
        {
            return null;
        }
        catch (UnauthorizedAccessException)
        {
            return null;
        }
        catch (OverflowException)
        {
            return null;
        }
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

                // 64-bit is not a formality: Credential Guard, memory integrity
                // and VBS are not available on a 32-bit build, so this reading is
                // what qualifies a machine for remediation rather than for
                // replacement - the difference between patching and retiring it.
                var addressWidth = Convert.ToInt32(obj["AddressWidth"] ?? 0);

                if (addressWidth > 0)
                {
                    data["cpu_architecture"] = addressWidth == 64 ? "x64" : "x86";
                }

                break;
            }
        }
        catch { }
    }

    /// <summary>
    /// How many RAM slots the board has, and how many are populated.
    /// </summary>
    /// <remarks>
    /// This is the question an asset register is actually asked: "can this laptop
    /// take more memory?" Total capacity alone cannot answer it - two 8GB sticks
    /// and one 16GB stick look identical in every capacity figure, but the first
    /// is full and the second has a free slot.
    ///
    /// The slot count comes from Win32_PhysicalMemoryArray.MemoryDevices and the
    /// populated count from the number of Win32_PhysicalMemory rows. That pairing
    /// is deliberate: the array describes the board, the modules describe what is
    /// in it, and no single class answers both questions.
    ///
    /// A count of 0 is treated as "unknown" and not reported. Several firmware
    /// versions report MemoryDevices = 0, and publishing "0 of 0" would read as
    /// a machine with no memory at all rather than one we failed to ask properly.
    /// </remarks>
    static void CollectMemorySlots(Dictionary<string, string> data)
    {
        try
        {
            var slots = 0;
            var populated = 0;

            using (var searcher = new ManagementObjectSearcher("SELECT MemoryDevices FROM Win32_PhysicalMemoryArray"))
            {
                foreach (var obj in searcher.Get())
                {
                    slots = Convert.ToInt32(obj["MemoryDevices"] ?? 0);
                    break;
                }
            }

            using (var searcher = new ManagementObjectSearcher("SELECT DeviceLocator FROM Win32_PhysicalMemory"))
            {
                foreach (var _ in searcher.Get())
                {
                    populated++;
                }
            }

            if (populated > 0)
            {
                data["ram_modules"] = populated.ToString(CultureInfo.InvariantCulture);
            }

            if (slots > 0)
            {
                // Never let the board claim fewer slots than are physically
                // present: some firmwares report the array size before SODIMM
                // detection settles, which would produce the nonsense "2 of 1".
                var total = Math.Max(slots, populated);
                data["ram_slots"] = total.ToString(CultureInfo.InvariantCulture);
            }
        }
        catch { }
    }

    /// <summary>Connected display panel, by name.</summary>
    /// <remarks>
    /// The old query filtered on "CurrentHorizontalActivePixels IS NOT NULL" to
    /// skip disconnected panels, and that filter matched nothing on this laptop:
    /// the internal BOE panel reports the field as empty, so the whole query
    /// returned zero rows and the column stayed NULL. The filter was meant as
    /// tidying and ended up suppressing the one monitor that exists.
    ///
    /// WmiMonitorID.Active is the correct "is it plugged in right now" signal,
    /// and filtering on it in code rather than in WQL keeps the failure mode
    /// visible: a query that returns rows we choose to ignore is a very
    /// different problem from a query that never returns rows at all.
    ///
    /// The first usable panel wins, so a docked laptop reports the external
    /// screen the person is actually looking at rather than the internal one.
    /// </remarks>
    static void CollectMonitor(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                @"root\WMI", "SELECT Active, ManufacturerName, UserFriendlyName FROM WmiMonitorID");

            foreach (var obj in searcher.Get())
            {
                // Active is a bool; a panel that is not connected reports false.
                if (obj["Active"] is bool active && !active)
                {
                    continue;
                }

                var label = string.Join(' ', new[] {
                    DecodeMonitorString(obj["ManufacturerName"] as ushort[]),
                    DecodeMonitorString(obj["UserFriendlyName"] as ushort[]),
                }.Where(part => !string.IsNullOrWhiteSpace(part)).Distinct());

                if (!string.IsNullOrWhiteSpace(label))
                {
                    data["monitor"] = label;
                    return;
                }
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
