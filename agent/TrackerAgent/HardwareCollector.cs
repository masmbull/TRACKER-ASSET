using System.IO;

using System.Diagnostics;
using System.Globalization;
using System.Management;
using System.Text;

namespace TrackerAgent;

public static class HardwareCollector
{
    public static Dictionary<string, string> Collect()
    {
        var data = new Dictionary<string, string>();

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
            using var cpu = new ManagementObjectSearcher("SELECT TOP 1 * FROM Win32_Processor");
            foreach (var obj in cpu.Get())
                data["processor"] = obj["Name"]?.ToString() ?? "";
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
            using var disk = new ManagementObjectSearcher("SELECT TOP 1 * FROM Win32_DiskDrive ORDER BY Size DESC");
            foreach (var obj in disk.Get())
            {
                var sizeBytes = Convert.ToDouble(obj["Size"] ?? 0);
                if (sizeBytes > 0) data["storage"] = FormatBytes(sizeBytes);
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

        CollectBattery(data);
        CollectDisk(data);
        CollectMemoryLayout(data);
        CollectGraphics(data);
        CollectWifi(data);
        CollectNetwork(data);
        CollectUptime(data);

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

    /// <summary>RAM modules, total size and speed, e.g. "2x 8GB @ 3200 MHz".</summary>
    static void CollectMemoryLayout(Dictionary<string, string> data)
    {
        try
        {
            using var memory = new ManagementObjectSearcher("SELECT * FROM Win32_PhysicalMemory");

            var modules = 0;
            var speed = 0;
            double totalBytes = 0;

            foreach (var obj in memory.Get())
            {
                modules++;
                totalBytes += Convert.ToDouble(obj["Capacity"] ?? 0);
                speed = Math.Max(speed, Convert.ToInt32(obj["Speed"] ?? 0));
            }

            if (modules > 0)
            {
                var layout = $"{modules}x {FormatBytes(totalBytes)}";

                if (speed > 0)
                {
                    layout += $" @ {speed} MHz";
                }

                data["ram_config"] = layout;
            }
        }
        catch { }
    }

    /// <summary>Primary GPU name (Live Fleet hardware summary).</summary>
    static void CollectGraphics(Dictionary<string, string> data)
    {
        try
        {
            using var gpu = new ManagementObjectSearcher(
                "SELECT TOP 1 * FROM Win32_VideoController ORDER BY AdapterRAM DESC");

            foreach (var obj in gpu.Get())
            {
                var name = obj["Name"]?.ToString();

                if (!string.IsNullOrWhiteSpace(name))
                {
                    data["gpu"] = name.Trim();
                }

                break;
            }
        }
        catch { }
    }

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

    static string FormatBytes(double bytes)
    {
        const double GB = 1024.0 * 1024.0 * 1024.0;
        const double MB = 1024.0 * 1024.0;
        if (bytes >= GB) return $"{Math.Round(bytes / GB)}GB";
        if (bytes >= MB) return $"{Math.Round(bytes / MB)}MB";
        return $"{Math.Round(bytes / 1024.0)}KB";
    }
}
