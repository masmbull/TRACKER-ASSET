using System.Diagnostics;
using System.IO;
using System.Text.Json;
using System.Windows;
using System.Windows.Threading;

namespace TrackerAgent;

public partial class ScanWindow : Window
{
    private readonly DispatcherTimer _timer = new();
    private int _progress;
    private string _stepMessage = "";
    private string _apiUrl = "";
    private string _token = "";

    public ScanWindow(string apiUrl, string token)
    {
        InitializeComponent();
        _apiUrl = apiUrl;
        _token = token;
        UrlBox.Text = apiUrl;
        TokenBox.Text = token;
        UrlBox.IsReadOnly = true;
        TokenBox.IsReadOnly = true;
    }

    private async void ScanButton_Click(object sender, RoutedEventArgs e)
    {
        ScanButton.IsEnabled = false;
        ConfigPanel.Visibility = Visibility.Collapsed;
        StatusPanel.Visibility = Visibility.Visible;

        var success = await Task.Run(() => RunScan());

        StatusPanel.Visibility = Visibility.Collapsed;
        ResultPanel.Visibility = Visibility.Visible;
        CloseButton.Visibility = Visibility.Visible;

        if (success)
        {
            ResultIcon.Text = "✅";
            ResultIcon.Foreground = System.Windows.Media.Brushes.LightGreen;
            ResultText.Text = "Scan Complete";
            ResultDetail.Text = "Audit data has been sent to the server successfully.";
        }
        else
        {
            ResultIcon.Text = "❌";
            ResultIcon.Foreground = System.Windows.Media.Brushes.OrangeRed;
            ResultText.Text = "Scan Failed";
            ResultDetail.Text = "Could not reach the server. Please check your connection and try again.";
        }
    }

    private void CloseButton_Click(object sender, RoutedEventArgs e) => Close();

    private bool RunScan()
    {
        try
        {
            Step(10, "Collecting hardware info...");
            var payload = HardwareCollector.Collect();

            Step(40, "Sending audit data...");
            var result = Api.Post(_apiUrl, _token, payload);

            Step(80, "Processing response...");
            Thread.Sleep(300);

            Step(100, "Done!");
            Thread.Sleep(300);
            return result is not null;
        }
        catch
        {
            return false;
        }
    }

    private void Step(int percent, string message)
    {
        _progress = percent;
        _stepMessage = message;

        Dispatcher.BeginInvoke(() =>
        {
            ProgressBar.Value = _progress;
            PercentText.Text = $"{_progress}%";
            StatusText.Text = _stepMessage;
        });

        Thread.Sleep(150);
    }
}
