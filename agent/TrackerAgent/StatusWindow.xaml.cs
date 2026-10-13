using System;
using System.Linq;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Windows;

namespace TrackerAgent;

public partial class StatusWindow : Window
{
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