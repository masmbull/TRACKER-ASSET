using System.IO;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Windows;
using System.Windows.Input;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Threading;

namespace TrackerAgent;

public partial class ScanWindow : Window
{
    private readonly string _apiUrl;
    private readonly string _token;
    private readonly string _deviceId;

    public ScanWindow(string apiUrl, string token, string deviceId)
    {
        InitializeComponent();
        _apiUrl = apiUrl;
        _token  = token;
        _deviceId = deviceId;

        UrlBox.Text   = apiUrl;
        TokenBox.Text = token.Length > 12
            ? token[..12] + "••••••••"
            : token;
    }

    [DllImport("dwmapi.dll")]
    private static extern int DwmSetWindowAttribute(IntPtr hwnd, int attr, ref int value, int size);

    protected override void OnSourceInitialized(EventArgs e)
    {
        base.OnSourceInitialized(e);
        var hwnd  = new WindowInteropHelper(this).Handle;
        var value = 1;
        DwmSetWindowAttribute(hwnd, 20, ref value, sizeof(int));
    }

    private async void ScanButton_Click(object sender, RoutedEventArgs e)
    {
        ScanButton.IsEnabled = false;
        ConfigPanel.Visibility = Visibility.Collapsed;
        StatusPanel.Visibility = Visibility.Visible;
        SubtitleText.Text = "Scanning…";

        var success = await Task.Run(RunScan);

        StatusPanel.Visibility = Visibility.Collapsed;
        ResultPanel.Visibility  = Visibility.Visible;
        CloseButton.Visibility  = Visibility.Visible;

        if (success)
        {
            SubtitleText.Text    = "Scan complete";
            ResultText.Text      = "Scan Complete";
            ResultDetail.Text    = "Hardware data sent to server.";
        }
        else
        {
            SubtitleText.Text    = "Scan failed";
            ResultText.Text      = "Scan Failed";
            ResultDetail.Text    = "Could not reach server. Check connection and try again.";
        }
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private bool RunScan()
    {
        try
        {
            Step(10, "Collecting hardware info…");
            var payload = HardwareCollector.Collect();
            payload["device_id"] = _deviceId;

            Step(45, "Sending audit data…");
            var result = Api.Post(_apiUrl, _token, payload);

            Step(85, "Processing response…");
            Thread.Sleep(250);

            Step(100, "Done");
            Thread.Sleep(200);
            return result is not null;
        }
        catch
        {
            return false;
        }
    }

    private void Step(int percent, string message)
    {
        Dispatcher.BeginInvoke(() =>
        {
            ScanProgressFill.Width = percent / 100.0 * 400;
            PercentText.Text       = $"{percent}%";
            StatusText.Text        = message;
        });
        Thread.Sleep(120);
    }
}
