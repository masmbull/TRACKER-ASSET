using System;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Threading.Tasks;
using System.Windows;

namespace TrackerAgent;

public partial class StatusWindow : Window
{
    private static readonly HttpClient Http = new() { Timeout = TimeSpan.FromSeconds(6) };

    public StatusWindow(string serverUrl, string installedAt)
    {
        InitializeComponent();

        ServerText.Text = serverUrl;

        if (DateTime.TryParse(installedAt, out var installed))
        {
            InstalledText.Text = installed.ToString("yyyy-MM-dd HH:mm");
        }

        UserText.Text = Environment.UserName;
        DeviceText.Text = Environment.MachineName;
        VersionText.Text = System.Reflection.Assembly.GetExecutingAssembly()
            .GetName().Version?.ToString(3) ?? "—";
        IpText.Text = LocalIpAddress();

        // The public IP needs a round trip to a third party, so it is fetched
        // after the window is already on screen and patched in when it answers.
        // Blocking the constructor on it would freeze a dialog that is only meant
        // to report status - and it is the one field here that can fail, since it
        // depends on the machine being allowed to reach the internet at all.
        _ = LoadPublicIpAsync();
    }

    /// <summary>
    /// The address the outside world sees this machine as.
    ///
    /// Not derivable locally: behind NAT the machine only ever knows its private
    /// address, so the public one has to be asked of a service that reflects the
    /// connection back. A failure to reach it is normal on a firewalled network
    /// and is shown as such, never thrown.
    /// </summary>
    private async Task LoadPublicIpAsync()
    {
        string result;

        try
        {
            // Plain-text reflectors: the response body is the IP and nothing else,
            // so there is no JSON to parse and no key that can be renamed under us.
            var text = await Http.GetStringAsync("https://api.ipify.org").ConfigureAwait(true);
            result = text.Trim();
        }
        catch
        {
            try
            {
                var text = await Http.GetStringAsync("https://ifconfig.me/ip").ConfigureAwait(true);
                result = text.Trim();
            }
            catch
            {
                result = "unavailable";
            }
        }

        PublicIpText.Text = result;
    }

    /// <summary>
    /// The machine's LAN address - the one its router handed it by DHCP.
    ///
    /// The obvious implementation (first Up, non-loopback IPv4) is wrong on any
    /// real Windows box: Hyper-V, WSL, VirtualBox, VPN clients and disconnected
    /// NICs all present extra interfaces, and a NIC whose DHCP request failed
    /// gets a 169.254.x.x self-assigned address (APIPA). Picking the first one
    /// yielded exactly that - a link-local address that routes nowhere and is no
    /// use to anyone reading the dialog.
    ///
    /// So the interface is chosen the way the OS chooses one: it must carry a
    /// default gateway (i.e. it leads somewhere - the router). Among those, the
    /// lowest metric wins, which is the interface Windows actually routes
    /// through. Link-local addresses are refused outright.
    /// </summary>
    private static string LocalIpAddress()
    {
        try
        {
            var candidates = NetworkInterface.GetAllNetworkInterfaces()
                .Where(nic => nic.OperationalStatus == OperationalStatus.Up
                    && nic.NetworkInterfaceType != NetworkInterfaceType.Loopback
                    && nic.NetworkInterfaceType != NetworkInterfaceType.Tunnel)
                .Select(nic => new
                {
                    Nic = nic,
                    Props = nic.GetIPProperties(),
                    // A default gateway of 0.0.0.0 is Windows' placeholder for
                    // "none", so it is filtered out too.
                    HasGateway = nic.GetIPProperties().GatewayAddresses
                        .Any(g => g.Address != null
                            && g.Address.AddressFamily == AddressFamily.InterNetwork
                            && !g.Address.Equals(IPAddress.Any)),
                })
                .Where(x => x.HasGateway)
                .OrderBy(x => x.Props.GetIPv4Properties()?.Index ?? int.MaxValue)
                .ToList();

            foreach (var candidate in candidates)
            {
                var address = candidate.Props.UnicastAddresses
                    .Select(ua => ua.Address)
                    .FirstOrDefault(ip => ip.AddressFamily == AddressFamily.InterNetwork
                        && !IPAddress.IsLoopback(ip)
                        && !IsLinkLocal(ip));

                if (address != null)
                {
                    return address.ToString();
                }
            }

            // Nothing has a gateway (e.g. a machine on a link-local network with
            // no DHCP). Fall back to any usable private address rather than a
            // blank field, but still never a 169.254 one - it tells the reader
            // nothing.
            var fallback = NetworkInterface.GetAllNetworkInterfaces()
                .Where(nic => nic.OperationalStatus == OperationalStatus.Up)
                .SelectMany(nic => nic.GetIPProperties().UnicastAddresses)
                .Select(ua => ua.Address)
                .FirstOrDefault(ip => ip.AddressFamily == AddressFamily.InterNetwork
                    && !IPAddress.IsLoopback(ip)
                    && !IsLinkLocal(ip));

            return fallback?.ToString() ?? "—";
        }
        catch
        {
            return "—";
        }
    }

    /// <summary>169.254.0.0/16: the address a NIC gives itself when DHCP fails.</summary>
    private static bool IsLinkLocal(IPAddress address)
    {
        var bytes = address.GetAddressBytes();

        return bytes.Length == 4 && bytes[0] == 169 && bytes[1] == 254;
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}