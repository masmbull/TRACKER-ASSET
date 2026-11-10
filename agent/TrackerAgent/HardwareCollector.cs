using System.IO;

using System.Diagnostics;
using System.Globalization;
using System.Management;
using System.Text;

namespace TrackerAgent;

public static class HardwareCollector
{
    public static Dictionary<string, string> Collect(string agentMode = "scan")
    {
        var data = new Dictionary<string, string>();
        data["agent_mode"] = agentMode;

        // A timestamp, not just the mode string.
        //
        // The tray and the SYSTEM daemon share one telemetry row and heartbeat in
        // turn, so the last writer decided what the fleet page believed: with both
        // processes running - the normal state - the status flipped between
        // "online" and "service active · tray not detected" every minute, and an
        // operator reading it at the wrong moment concluded the tray was gone when
        // it was sitting in the taskbar. Recording when a tray last reported lets
        // the server keep saying "tray" for as long as one is genuinely running.
        if (string.Equals(agentMode, "tray", StringComparison.OrdinalIgnoreCase))
        {
            data["tray_seen_at"] = DateTime.UtcNow.ToString("O");
        }

        try { data["hostname"] = Environment.MachineName; } catch { }

        try
        {
            using var cs = new ManagementObjectSearcher("SELECT * FROM Win32_ComputerSystem");
            foreach (var obj in cs.Get())
            {
                data["brand"] = obj["Manufacturer"]?.ToString() ?? "";
                data["model"] = obj["Model"]?.ToString() ?? "";
                var ramBytes = Convert.ToDouble(obj["TotalPhysicalMemory"] ?? 0);
                if (ramBytes > 0) data["ram"] = FormatBytes(ramBytes);
            }
        }
        catch { }

        try
        {
            using var bios = new ManagementObjectSearcher("SELECT * FROM Win32_BIOS");
            foreach (var obj in bios.Get())
                data["serial_number"] = (obj["SerialNumber"]?.ToString() ?? "").Trim();
        }
        catch { }

        try
        {
            // Deliberately not "SELECT TOP 1 * FROM Win32_Processor": that form
            // matches no rows on this hardware and left `processor` permanently
            // empty. FingerprintCollector hit the same trap and works around it
            // there; this is the same workaround, kept identical so the two
            // collectors cannot drift apart again.
            using var cpu = new ManagementObjectSearcher("SELECT Name FROM Win32_Processor");
            foreach (var obj in cpu.Get())
            {
                data["processor"] = obj["Name"]?.ToString() ?? "";
                break;
            }
        }
        catch { }

        try
        {
            using var os = new ManagementObjectSearcher("SELECT * FROM Win32_OperatingSystem");
            foreach (var obj in os.Get())
                data["os"] = $"{obj["Caption"]} {obj["Version"]}".Trim();
        }
        catch { }

        try
        {
            // Same "TOP 1" trap as the processor above: all fixed disks are read
            // and the largest is chosen in code, which also lets a multi-disk
            // laptop report the drive people actually care about.
            double largest = 0;
            string driveType = "";
            string driveModel = "";

            using var disk = new ManagementObjectSearcher(
                "SELECT Size, MediaType, Model FROM Win32_DiskDrive");

            foreach (var obj in disk.Get())
            {
                var sizeBytes = Convert.ToDouble(obj["Size"] ?? 0);

                if (sizeBytes > largest)
                {
                    largest = sizeBytes;
                    driveModel = obj["Model"]?.ToString() ?? "";
                    driveType = ClassifyDriveType(obj["MediaType"]?.ToString(), driveModel);
                }
            }

            if (largest > 0)
            {
                data["storage"] = FormatBytes(largest);
                data["storage_type"] = driveType;
                data["storage_capacity"] = $"{Number(largest / Gigabyte, 1)} GB";
            }
        }
        catch { }

        try
        {
            using var nic = new ManagementObjectSearcher(
                "SELECT * FROM Win32_NetworkAdapterConfiguration WHERE IPEnabled=TRUE");
            foreach (var obj in nic.Get())
            {
                var mac = obj["MACAddress"]?.ToString();
                if (!string.IsNullOrWhiteSpace(mac))
                    data["mac_address"] = mac;

                var ips = obj["IPAddress"] as string[];
                if (ips is { Length: > 0 })
                    data["ip_address"] = ips[0];
                break;
            }
        }
        catch { }

        // Live Fleet telemetry (PRD §11) — battery wear, C: disk space, RAM
        // layout, Wi-Fi link and the Windows user that is logged in.
        try { data["logged_in_user"] = Environment.UserName; } catch { }

        // Whether this agent can run machine-level scripts. A standard-user agent
        // cannot, and the fleet page needs to say so before an operator pushes a
        // run_as:system script at it and reads the failure. "1"/"0" because the
        // payload is a string map and Laravel's boolean rule reads those, not
        // the words "true"/"false".
        //
        // The tray process itself is never elevated - it runs in the user session.
        // When a SYSTEM daemon is running (created by a successful elevate command),
        // that daemon CAN run machine-level scripts. The tray must report that
        // capability so the fleet page stops showing "no admin" and the "Make admin"
        // button stops appearing for a device that is already elevated via the daemon.
        bool elevated = AgentRunner.IsProcessElevated() || AgentRunner.IsSystemDaemonAlive();
        try { data["is_elevated"] = elevated ? "1" : "0"; } catch { }

        CollectBattery(data);
        CollectDisk(data);
        CollectMemoryLayout(data);
        CollectGraphics(data);
        CollectWifi(data);
        CollectNetwork(data);
        CollectUptime(data);
        CollectAccessories(data);

        return data.Where(kv => !string.IsNullOrWhiteSpace(kv.Value))
                   .ToDictionary(kv => kv.Key, kv => kv.Value);
    }

    const double Gigabyte = 1024.0 * 1024.0 * 1024.0;

    /// <summary>
    /// Formats numbers with a dot as the decimal separator: the API validates
    /// telemetry with `numeric`, which rejects comma decimals.
    /// </summary>
    static string Number(double value, int decimals) =>
        Math.Round(value, decimals).ToString(CultureInfo.InvariantCulture);

    /// <summary>
    /// Battery charge, wear and cycles. Battery sensors differ per vendor, so
    /// every reading is optional: a missing class simply leaves the key unset.
    /// </summary>
    static void CollectBattery(Dictionary<string, string> data)
    {
        try
        {
            using var battery = new ManagementObjectSearcher("SELECT * FROM Win32_Battery");
            foreach (var obj in battery.Get())
            {
                if (obj["EstimatedChargeRemaining"] is not null)
                {
                    data["battery_percent"] = Convert.ToInt32(obj["EstimatedChargeRemaining"])
                        .ToString(CultureInfo.InvariantCulture);
                }

                data["battery_status"] = Convert.ToInt32(obj["BatteryStatus"] ?? 0) switch
                {
                    2 => "charging",
                    1 => "discharging",
                    _ => "idle",
                };

                break;
            }
        }
        catch { }

        var fullCharge = 0L;
        var design = 0L;

        try
        {
            using var full = new ManagementObjectSearcher(
                new ManagementScope(@"root\wmi"), new ObjectQuery("SELECT * FROM BatteryFullChargedCapacity"));

            foreach (var obj in full.Get())
            {
                fullCharge = Convert.ToInt64(obj["FullChargedCapacity"] ?? 0);

                if (fullCharge > 0)
                {
                    break;
                }
            }
        }
        catch { }

        try
        {
            using var staticData = new ManagementObjectSearcher(
                new ManagementScope(@"root\wmi"), new ObjectQuery("SELECT * FROM BatteryStaticData"));

            foreach (var obj in staticData.Get())
            {
                design = Convert.ToInt64(obj["DesignedCapacity"] ?? 0);

                if (design > 0)
                {
                    break;
                }
            }
        }
        catch { }

        if (design <= 0)
        {
            try
            {
                using var battery = new ManagementObjectSearcher("SELECT * FROM Win32_Battery");
                foreach (var obj in battery.Get())
                {
                    design = Convert.ToInt64(obj["DesignCapacity"] ?? 0);

                    if (design > 0)
                    {
                        break;
                    }
                }
            }
            catch { }
        }

        if (fullCharge > 0 && design > 0)
        {
            var health = Math.Clamp((int)Math.Round(fullCharge * 100.0 / design), 0, 100);
            data["battery_health"] = health.ToString(CultureInfo.InvariantCulture);
        }

        try
        {
            using var cycles = new ManagementObjectSearcher(
                new ManagementScope(@"root\wmi"), new ObjectQuery("SELECT * FROM BatteryCycleCount"));

            foreach (var obj in cycles.Get())
            {
                data["battery_cycle_count"] = Convert.ToInt64(obj["CycleCount"] ?? 0)
                    .ToString(CultureInfo.InvariantCulture);

                break;
            }
        }
        catch { }
    }

    /// <summary>Free/total space of the system drive (Live Fleet disk alarm).</summary>
    static void CollectDisk(Dictionary<string, string> data)
    {
        try
        {
            var systemDrive = (Path.GetPathRoot(Environment.SystemDirectory) ?? "C:\\").TrimEnd('\\');

            using var disk = new ManagementObjectSearcher(
                $"SELECT * FROM Win32_LogicalDisk WHERE DeviceID='{systemDrive}'");

            foreach (var obj in disk.Get())
            {
                var free = Convert.ToDouble(obj["FreeSpace"] ?? 0);
                var total = Convert.ToDouble(obj["Size"] ?? 0);

                if (free > 0) data["disk_free_gb"] = Number(free / Gigabyte, 2);
                if (total > 0) data["disk_total_gb"] = Number(total / Gigabyte, 2);

                break;
            }
        }
        catch { }
    }

    /// <summary>Installed module sizes and speed, e.g. "2x 8GB @ 3200 MHz".</summary>
    static void CollectMemoryLayout(Dictionary<string, string> data)
    {
        try
        {
            var modules = ReadMemoryModules();

            if (modules.Count > 0)
            {
                data["ram_config"] = FormatMemoryLayout(modules);
            }
        }
        catch { }
    }

    internal static int InstalledMemoryModuleCount() => ReadMemoryModules().Count;

    internal static string FormatMemoryLayout(IEnumerable<MemoryModuleReading> modules)
    {
        var installed = DeduplicateMemoryModules(modules);

        if (installed.Count == 0)
        {
            return string.Empty;
        }

        var sizes = installed
            .GroupBy(module => module.Capacity)
            .OrderByDescending(group => group.Key)
            .Select(group => group.Count() == 1
                ? FormatBytes(group.Key)
                : $"{group.Count()}x {FormatBytes(group.Key)}");
        var layout = string.Join(" + ", sizes);
        var speed = installed.Max(module => module.Speed);

        return speed > 0 ? $"{layout} @ {speed} MHz" : layout;
    }

    private static List<MemoryModuleReading> ReadMemoryModules()
    {
        using var memory = new ManagementObjectSearcher(
            "SELECT Capacity, DeviceLocator, SerialNumber, Tag, Speed FROM Win32_PhysicalMemory");
        var modules = new List<MemoryModuleReading>();

        foreach (var obj in memory.Get())
        {
            var capacity = Convert.ToUInt64(obj["Capacity"] ?? 0);

            if (capacity == 0)
            {
                continue;
            }

            modules.Add(new MemoryModuleReading(
                obj["DeviceLocator"]?.ToString(),
                obj["SerialNumber"]?.ToString(),
                obj["Tag"]?.ToString(),
                capacity,
                Convert.ToInt32(obj["Speed"] ?? 0)));
        }

        return DeduplicateMemoryModules(modules);
    }

    private static List<MemoryModuleReading> DeduplicateMemoryModules(IEnumerable<MemoryModuleReading> modules)
    {
        var unique = new Dictionary<string, MemoryModuleReading>(StringComparer.OrdinalIgnoreCase);
        var unlocated = new List<MemoryModuleReading>();

        foreach (var module in modules.Where(module => module.Capacity > 0))
        {
            var identity = FirstIdentity(module.SerialNumber, module.Tag, module.DeviceLocator);

            if (identity is null)
            {
                unlocated.Add(module);
                continue;
            }

            unique.TryAdd(identity, module);
        }

        return unique.Values.Concat(unlocated).ToList();
    }

    private static string? FirstIdentity(params string?[] identities) => identities
        .Select(identity => identity?.Trim())
        .FirstOrDefault(identity => !string.IsNullOrWhiteSpace(identity));

    internal sealed record MemoryModuleReading(
        string? DeviceLocator,
        string? SerialNumber,
        string? Tag,
        ulong Capacity,
        int Speed);

    /// <summary>Primary GPU name (Live Fleet hardware summary).</summary>
    /// <remarks>
    /// The third "TOP 1" casualty in this file, fixed the same way. It also has
    /// to pick the right adapter by hand, because a laptop reports both the
    /// integrated and the discrete GPU and the register wants the one an
    /// operator would call the graphics card.
    ///
    /// The "basic display adapter" rows are what Windows falls back to when a
    /// driver is missing or the GPU is disabled in the BIOS; they are not real
    /// hardware and must never win, or the fleet page would report every
    /// troubled laptop as a generic Microsoft adapter.
    ///
    /// AdapterRAM is a uint32 that wraps above 4 GB, so a 6 GB card reports a
    /// tiny number and would sort below a 2 GB one. Ordering is therefore a
    /// preference, not a truth: integrated-only machines still get their one
    /// real adapter named, and no adapter is invented when none qualify.
    /// </remarks>
    static void CollectGraphics(Dictionary<string, string> data)
    {
        try
        {
            var candidates = new List<(string Name, ulong Ram)>();
            ulong placeholderRam = 0;
            string? placeholderName = null;

            using var gpu = new ManagementObjectSearcher(
                "SELECT Name, AdapterRAM FROM Win32_VideoController");

            foreach (var obj in gpu.Get())
            {
                var name = obj["Name"]?.ToString()?.Trim();

                if (string.IsNullOrWhiteSpace(name))
                {
                    continue;
                }

                var ram = Convert.ToUInt64(obj["AdapterRAM"] ?? 0UL);

                // Remembered before the skip below, otherwise the "no real
                // adapter" branch could never reach a placeholder name and the
                // field would stay empty for exactly the machines that most
                // need to be reported.
                if (IsPlaceholderAdapter(name))
                {
                    if (ram > placeholderRam)
                    {
                        placeholderRam = ram;
                        placeholderName = name;
                    }

                    continue;
                }

                candidates.Add((name, ram));
            }

            if (candidates.Count == 0)
            {
                // Every adapter was a placeholder. Reporting the placeholder is
                // more useful than nothing at all: it tells an operator the
                // driver is gone instead of leaving the field mysteriously empty.
                if (placeholderName is not null)
                {
                    data["gpu"] = placeholderName;
                }

                return;
            }

            // Prefer a discrete card over the integrated one on the same laptop.
            // AdapterRAM wraps, so this is a tiebreak between two real names,
            // never a measurement of which has more memory.
            var best = candidates
                .OrderByDescending(c => c.Ram)
                .First();

            data["gpu"] = best.Name;
        }
        catch { }
    }

    /// <summary>True for the generic adapters Windows reports instead of real hardware.</summary>
    static bool IsPlaceholderAdapter(string name) =>
        name.Contains("Basic Display", StringComparison.OrdinalIgnoreCase)
        || name.Contains("Basic Render", StringComparison.OrdinalIgnoreCase)
        || name.Contains("Remote Display", StringComparison.OrdinalIgnoreCase);

    /// <summary>Current Wi-Fi SSID + signal strength, parsed from netsh.</summary>
    static void CollectWifi(Dictionary<string, string> data)
    {
        try
        {
            var startInfo = new ProcessStartInfo("netsh", "wlan show interfaces")
            {
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true,
                StandardOutputEncoding = Encoding.UTF8,
            };

            using var process = Process.Start(startInfo);

            if (process is null)
            {
                return;
            }

            var output = process.StandardOutput.ReadToEnd();
            process.WaitForExit(5000);

            var ssidSeen = false;

            foreach (var raw in output.Split('\n'))
            {
                var line = raw.Trim();
                var separator = line.IndexOf(':');

                if (separator <= 0)
                {
                    continue;
                }

                var key = line[..separator].Trim();
                var value = line[(separator + 1)..].Trim();

                if (value.Length == 0)
                {
                    continue;
                }

                // "Nama SSID" on localized Windows, but never "BSSID".
                if (!ssidSeen
                    && key.EndsWith("SSID", StringComparison.OrdinalIgnoreCase)
                    && !key.EndsWith("BSSID", StringComparison.OrdinalIgnoreCase))
                {
                    data["wifi_ssid"] = value;
                    ssidSeen = true;
                }
                else if (key.Equals("Signal", StringComparison.OrdinalIgnoreCase))
                {
                    data["wifi_signal"] = value.Replace("%", string.Empty).Trim();
                }
            }
        }
        catch { }
    }

    /// <summary>
    /// Which link is actually carrying traffic, and how fast.
    /// </summary>
    /// <remarks>
    /// `netsh wlan show interfaces` only ever describes wireless, so a machine on
    /// a cable reported nothing at all and the fleet page fell through to
    /// "no adapter data" — indistinguishable from being offline. This reads the
    /// physical adapters instead, so an Ethernet laptop reports its NIC.
    ///
    /// Only physical adapters count. Virtual ones (VPN, Hyper-V, WSL, Tailscale)
    /// are skipped: they would otherwise win the "up and enabled" filter and
    /// report a 100 Gbps tunnel instead of the real 1 Gbps cable.
    /// </remarks>
    static void CollectNetwork(Dictionary<string, string> data)
    {
        try
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT Name, NetEnabled, Speed, AdapterType, PhysicalAdapter " +
                "FROM Win32_NetworkAdapter WHERE NetEnabled = TRUE");

            foreach (var obj in searcher.Get())
            {
                var enabled = obj["NetEnabled"];
                if (enabled is not bool isEnabled || !isEnabled)
                {
                    continue;
                }

                // 0 / null is what a disconnected or virtual interface reports.
                if (obj["PhysicalAdapter"] is not bool physical || !physical)
                {
                    continue;
                }

                var speed = Convert.ToUInt64(obj["Speed"] ?? 0UL);
                if (speed == 0)
                {
                    continue;
                }

                var name = obj["Name"]?.ToString()?.Trim();
                if (string.IsNullOrWhiteSpace(name))
                {
                    continue;
                }

                data["network_adapter"] = name;
                data["network_speed"] = FormatLinkSpeed(speed);
                data["network_type"] = ClassifyAdapter(name);
                return;
            }
        }
        catch { }
    }

    /// <summary>"ethernet", "wifi" or "unknown", from the adapter description.</summary>
    static string ClassifyAdapter(string name)
    {
        var lowered = name.ToLowerInvariant();

        if (lowered.Contains("wi-fi") || lowered.Contains("wifi")
            || lowered.Contains("wireless") || lowered.Contains("wlan"))
        {
            return "wifi";
        }

        if (lowered.Contains("ethernet") || lowered.Contains("gbe")
            || lowered.Contains("gbic") || lowered.Contains("lan")
            || lowered.Contains("realtek") || lowered.Contains("intel"))
        {
            return "ethernet";
        }

        return "unknown";
    }

    /// <summary>Adapter speed in the largest unit that keeps a useful number.</summary>
    static string FormatLinkSpeed(ulong bitsPerSecond)
    {
        if (bitsPerSecond >= 1_000_000_000)
        {
            return string.Create(
                CultureInfo.InvariantCulture, $"{bitsPerSecond / 1_000_000_000.0:0.#} Gbps");
        }

        if (bitsPerSecond >= 1_000_000)
        {
            return string.Create(
                CultureInfo.InvariantCulture, $"{bitsPerSecond / 1_000_000.0:0.#} Mbps");
        }

        return $"{bitsPerSecond} bps";
    }

    /// <summary>Hours since the last boot (Live Fleet freshness check).</summary>
    static void CollectUptime(Dictionary<string, string> data)
    {
        try
        {
            using var os = new ManagementObjectSearcher(
                "SELECT LastBootUpTime FROM Win32_OperatingSystem");

            foreach (var obj in os.Get())
            {
                var boot = obj["LastBootUpTime"]?.ToString();

                if (string.IsNullOrWhiteSpace(boot))
                {
                    continue;
                }

                var hours = (DateTime.Now - ManagementDateTimeConverter.ToDateTime(boot)).TotalHours;

                if (hours >= 0)
                {
                    data["uptime_hours"] = Number(hours, 2);
                }

                break;
            }
        }
        catch { }
    }

    /// <summary>Monitor, keyboard, mouse, headset, webcam collection.</summary>
    static void CollectAccessories(Dictionary<string, string> data)
    {
        try
        {
            using var monitor = new ManagementObjectSearcher(
                "SELECT SerialNumber FROM Win32_PnPEntity WHERE ClassGuid = '{4d36e96e-e325-11ce-bfc1-08002be10318}'");
            foreach (var obj in monitor.Get())
            {
                var sn = obj["SerialNumber"]?.ToString()?.Trim();
                if (!string.IsNullOrWhiteSpace(sn) && sn != "To Be Filled By O.E.M.")
                {
                    data["monitor_sn"] = sn;
                    break;
                }
            }
        }
        catch { }

        try
        {
            using var keyboard = new ManagementObjectSearcher(
                "SELECT Name FROM Win32_Keyboard");
            foreach (var obj in keyboard.Get())
            {
                var name = obj["Name"]?.ToString()?.Trim();
                if (!string.IsNullOrWhiteSpace(name))
                {
                    data["keyboard_model"] = name;
                    break;
                }
            }
        }
        catch { }

        try
        {
            using var mouse = new ManagementObjectSearcher(
                "SELECT Name FROM Win32_PointingDevice");
            foreach (var obj in mouse.Get())
            {
                var name = obj["Name"]?.ToString()?.Trim();
                if (!string.IsNullOrWhiteSpace(name))
                {
                    data["mouse_model"] = name;
                    break;
                }
            }
        }
        catch { }
    }

    static string FormatBytes(double bytes)
    {
        const double GB = 1024.0 * 1024.0 * 1024.0;
        const double MB = 1024.0 * 1024.0;
        if (bytes >= GB) return $"{Math.Round(bytes / GB)}GB";
        if (bytes >= MB) return $"{Math.Round(bytes / MB)}MB";
        return $"{Math.Round(bytes / 1024.0)}KB";
    }

    static string ClassifyDriveType(string? mediaType, string model)
    {
        var combined = $"{mediaType ?? ""} {model ?? ""}".ToLowerInvariant();

        if (combined.Contains("nvme") || combined.Contains("nvme"))
            return "NVME";
        if (combined.Contains("ssd") || combined.Contains("solid state"))
            return "SSD";
        if (combined.Contains("hdd") || combined.Contains("hard disk"))
            return "HDD";

        return "Unknown";
    }
}
