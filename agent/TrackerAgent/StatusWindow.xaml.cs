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
    /// The machine's own IPv4 address: the first up, non-loopback address on an
    /// interface that is actually connected. Read here rather than from the
    /// server so the window still shows something useful before the first
    /// heartbeat has been reported.
    /// </summary>
    private static string LocalIpAddress()
    {
        try
        {
            var address = NetworkInterface.GetAllNetworkInterfaces()
                .Where(nic => nic.OperationalStatus == OperationalStatus.Up
                    && nic.NetworkInterfaceType != NetworkInterfaceType.Loopback)
                .SelectMany(nic => nic.GetIPProperties().UnicastAddresses)
                .Select(ua => ua.Address)
                .FirstOrDefault(ip => ip.AddressFamily == AddressFamily.InterNetwork);

            return address?.ToString() ?? "—";
        }
        catch
        {
            return "—";
        }
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}