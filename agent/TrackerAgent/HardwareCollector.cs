using System.Management;

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

        return data.Where(kv => !string.IsNullOrWhiteSpace(kv.Value))
                   .ToDictionary(kv => kv.Key, kv => kv.Value);
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
