using System;
using System.IO;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Interop;

namespace TrackerAgent;

public partial class StatusWindow : Window
{
    public StatusWindow(string version, string serverUrl, string deviceId, string installedAt)
    {
        InitializeComponent();

        VersionText.Text = $"v{version}";
        ServerText.Text = serverUrl;
        
        // Show first 8 chars + ellipsis for device ID
        var shortId = deviceId.Length >= 8
            ? deviceId[..8] + "…"
            : deviceId;
        DeviceIdText.Text = shortId;

        // Parse installed date
        if (DateTime.TryParse(installedAt, out var installed))
        {
            InstalledText.Text = installed.ToString("yyyy-MM-dd HH:mm");
        }

        // Show install path
        var installDir = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "TRACKER");
        PathText.Text = installDir;
    }

    [DllImport("dwmapi.dll")]
    private static extern int DwmSetWindowAttribute(IntPtr hwnd, int attr, ref int value, int size);

    protected override void OnSourceInitialized(EventArgs e)
    {
        base.OnSourceInitialized(e);
        var hwnd  = new WindowInteropHelper(this).Handle;
        var value = 1; // DWMWA_USE_IMMERSIVE_DARK_MODE
        DwmSetWindowAttribute(hwnd, 20, ref value, sizeof(int));
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();
}
